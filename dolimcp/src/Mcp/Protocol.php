<?php

declare(strict_types=1);

namespace DoliMcp\Mcp;

use DoliMcp\Http\Request;
use DoliMcp\Http\Response;

final class Protocol
{
    private const MODERN = '2026-07-28';
    private const LEGACY = ['2025-03-26', '2025-06-18', '2025-11-25'];
    private const META_KEY = 'io.modelcontextprotocol/protocolVersion';

    private ?\Closure $onWrite;

    /** @param ?\Closure(Tool,array,ToolResult):void $onWrite */
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly string $serverVersion,
        ?\Closure $onWrite = null,
    ) {
        $this->onWrite = $onWrite;
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->dispatch($request);
        } catch (\Throwable $e) {
            return $this->error(500, null, -32603, 'Internal error: ' . $e::class);
        }
    }

    private function dispatch(Request $request): Response
    {
        if ($request->method !== 'POST') {
            return Response::empty(405);
        }
        $msg = json_decode($request->body, true);
        if (!is_array($msg) || array_is_list($msg) || !isset($msg['method']) || !is_string($msg['method'])) {
            return $this->error(400, null, -32600, 'Invalid Request: expected a single JSON-RPC object');
        }
        if (!array_key_exists('id', $msg)) {
            return Response::empty(202);
        }
        $id = $msg['id'];
        $method = $msg['method'];
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

        if ($method === 'initialize') {
            $asked = $params['protocolVersion'] ?? null;
            return $this->result($id, [
                'protocolVersion' => in_array($asked, self::LEGACY, true) ? $asked : '2025-11-25',
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => $this->serverInfo(),
            ]);
        }

        $header = $request->headers['mcp-protocol-version'] ?? null;
        $meta = $params['_meta'][self::META_KEY] ?? null;
        $modern = $header === self::MODERN || $meta === self::MODERN;

        if ($modern) {
            $mismatch = $this->modernMismatch($request, $method, $params, $header, $meta);
            if ($mismatch !== null) {
                return $this->error(400, $id, -32020, 'Header mismatch: ' . $mismatch);
            }
        } else {
            foreach ([$header, $meta] as $requested) {
                if ($requested !== null && !in_array($requested, self::LEGACY, true)) {
                    return $this->error(400, $id, -32022, 'Unsupported protocol version', [
                        'supported' => [self::MODERN, ...self::LEGACY],
                        'requested' => $requested,
                    ]);
                }
            }
        }

        return match ($method) {
            'server/discover' => $this->result($id, [
                'supportedVersions' => [self::MODERN, ...self::LEGACY],
                'capabilities' => ['tools' => new \stdClass()],
                'instructions' => 'Dolibarr tools: search, read and write business records.',
                '_meta' => ['io.modelcontextprotocol/serverInfo' => $this->serverInfo()],
            ], true),
            'ping' => $this->result($id, new \stdClass(), false),
            'tools/list' => $this->result($id, [
                'tools' => array_map(static fn (Tool $t): array => [
                    'name' => $t->name,
                    'description' => $t->description,
                    'inputSchema' => self::emptyPropertiesAsObjects($t->inputSchema),
                ], $this->tools->all()),
                'ttlMs' => 3600000,
                'cacheScope' => 'private',
            ], $modern),
            'tools/call' => $this->callTool($id, $params, $modern),
            default => $this->error($modern ? 404 : 200, $id, -32601, 'Method not found: ' . $method),
        };
    }

    private function callTool(mixed $id, array $params, bool $modern): Response
    {
        $tool = is_string($params['name'] ?? null) ? $this->tools->get($params['name']) : null;
        if ($tool === null) {
            return $this->error(200, $id, -32602, 'Unknown tool: ' . json_encode($params['name'] ?? null));
        }
        $arguments = $params['arguments'] ?? [];
        $errors = SchemaValidator::validate($tool->inputSchema, $arguments);
        if ($errors !== []) {
            return $this->error(200, $id, -32602, 'Invalid arguments: ' . implode('; ', $errors));
        }

        try {
            $result = ($tool->handler)($arguments);
        } catch (ToolError $e) {
            $result = ToolResult::error($e->getMessage());
        } catch (\Throwable $e) {
            $result = ToolResult::error('Internal error: ' . $e::class);
        }
        try {
            $formatted = $this->formatResult($result);
        } catch (\JsonException $e) {
            $result = ToolResult::error('Internal error: ' . $e::class);
            $formatted = $this->formatResult($result);
        }
        if ($tool->writes && $this->onWrite !== null) {
            try {
                ($this->onWrite)($tool, $arguments, $result);
            } catch (\Throwable $e) {
                // The write already happened: report the failure, never fail the call.
                error_log('dolimcp: write hook failed: ' . $e::class . ': ' . $e->getMessage());
            }
        }

        return $this->result($id, $formatted, $modern);
    }

    /** An empty `properties` must encode as `{}`, not `[]`, at any depth. */
    private static function emptyPropertiesAsObjects(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if ($key === 'properties' && $value === []) {
                $schema[$key] = new \stdClass();
            } elseif (is_array($value)) {
                $schema[$key] = self::emptyPropertiesAsObjects($value);
            }
        }
        return $schema;
    }

    private function formatResult(ToolResult $r): array
    {
        if ($r->isError) {
            return ['isError' => true, 'content' => [['type' => 'text', 'text' => (string) $r->data]]];
        }
        $text = json_encode(
            $r->data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        $content = [];
        if ($r->file !== null) {
            $content[] = ['type' => 'resource', 'resource' => $r->file];
        }
        $content[] = ['type' => 'text', 'text' => $text];
        $out = ['content' => $content];
        // structuredContent must be a JSON object: lists, [] and scalars only travel in the text block.
        $isObject = is_object($r->data) || is_array($r->data) && $r->data !== [] && !array_is_list($r->data);
        if ($r->file === null && $isObject) {
            $out['structuredContent'] = $r->data;
        }
        return $out;
    }

    /** Returns a description of the first header/body mismatch, or null. */
    private function modernMismatch(Request $request, string $method, array $params, mixed $header, mixed $meta): ?string
    {
        if ($header !== self::MODERN || $meta !== self::MODERN) {
            return 'MCP-Protocol-Version and _meta protocolVersion must both be ' . self::MODERN;
        }
        if (($request->headers['mcp-method'] ?? null) !== $method) {
            return 'Mcp-Method does not match the request method';
        }
        if ($method === 'tools/call') {
            $name = $request->headers['mcp-name'] ?? null;
            if (is_string($name) && preg_match('/^=\?base64\?(.*)\?=$/', $name, $m) === 1) {
                $name = base64_decode($m[1], true);
            }
            if ($name !== ($params['name'] ?? null)) {
                return 'Mcp-Name does not match params.name';
            }
        }
        return null;
    }

    private function serverInfo(): array
    {
        return ['name' => 'dolibarr-mcp', 'version' => $this->serverVersion];
    }

    private function result(mixed $id, array|\stdClass $result, bool $complete = false): Response
    {
        if ($complete && is_array($result)) {
            $result = ['resultType' => 'complete'] + $result;
        }
        return Response::json(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function error(int $status, mixed $id, int $code, string $message, ?array $data = null): Response
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }
        return Response::json($status, ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error]);
    }
}
