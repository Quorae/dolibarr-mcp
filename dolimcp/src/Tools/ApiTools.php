<?php

declare(strict_types=1);

namespace DoliMcp\Tools;

use DoliMcp\Dolibarr\Context;
use DoliMcp\Dolibarr\RestError;
use DoliMcp\Mcp\Tool;
use DoliMcp\Mcp\ToolError;
use DoliMcp\Mcp\ToolRegistry;
use DoliMcp\Mcp\ToolResult;
use DoliMcp\Support\Compactor;

/** Raw access to the Dolibarr REST API, for what no dedicated tool covers. */
final class ApiTools
{
    public static function register(ToolRegistry $registry, Context $context): void
    {
        $registry->add(new Tool(
            'api_endpoints',
            'List the Dolibarr REST endpoints (method, path, summary), optionally filtered by a text found in the path or summary.',
            ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'additionalProperties' => false],
            static function (array $arguments) use ($context): ToolResult {
                try {
                    $swagger = $context->rest->request('GET', 'explorer/swagger.json');
                } catch (RestError $e) {
                    throw new ToolError('The endpoint catalogue (explorer/swagger.json) cannot be read: ' . $e->getMessage());
                }
                if (!is_array($swagger['paths'] ?? null)) {
                    throw new ToolError('The endpoint catalogue (explorer/swagger.json) cannot be read: unexpected answer');
                }

                $needle = strtolower((string) ($arguments['query'] ?? ''));
                $list = [];
                foreach ($swagger['paths'] as $path => $methods) {
                    foreach ((array) $methods as $method => $operation) {
                        $summary = (string) ($operation['summary'] ?? '');
                        if ($needle === '' || str_contains(strtolower((string) $path), $needle) || str_contains(strtolower($summary), $needle)) {
                            $list[] = ['method' => strtoupper((string) $method), 'path' => (string) $path, 'summary' => $summary];
                        }
                    }
                }

                return ToolResult::ok($list);
            },
        ));

        $registry->add(new Tool(
            'api_request',
            'Call the Dolibarr REST API as the authenticated user (path relative to the API root, e.g. "thirdparties" or "invoices/12/validate"). Use api_endpoints to find paths. A GET that returns [] may mean no result, or a wrong path: check it with api_endpoints.',
            [
                'type' => 'object',
                'properties' => [
                    'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'DELETE']],
                    'path' => ['type' => 'string'],
                    'query' => ['type' => 'object'],
                    'body' => ['type' => 'object'],
                ],
                'required' => ['method', 'path'],
                'additionalProperties' => false,
            ],
            static function (array $arguments) use ($context): ToolResult {
                $method = (string) $arguments['method'];
                $path = ltrim((string) $arguments['path'], '/');
                if (str_contains($path, '..') || str_contains($path, '://')) {
                    throw new ToolError('Invalid path: it must be relative to the API root');
                }
                // Same refusal as module_set: the guard must hold whichever tool reaches the REST API.
                $normalized = strtolower(trim((string) preg_replace('#/+#', '/', rawurldecode((string) strtok($path, '?'))), '/'));
                if (preg_match('#^setup/modules/(mod)?(api|dolimcp)/disable$#', $normalized) === 1) {
                    throw new ToolError(SetupTools::PROTECTED_MESSAGE);
                }

                try {
                    $data = $context->rest->request($method, $path, (array) ($arguments['query'] ?? []), isset($arguments['body']) ? (array) $arguments['body'] : null);
                } catch (RestError $e) {
                    if ($method === 'GET' && $e->isEmptyResult()) {
                        return ToolResult::ok([]);
                    }
                    throw $e;
                }

                return ToolResult::ok(self::mask($path, Compactor::compact($data)));
            },
            true,
        ));
    }

    /** setup/conf/<name> answers with a bare value: its name is only in the path. */
    public static function mask(string $path, mixed $data): mixed
    {
        if (is_string($data) && preg_match('#^setup/conf/([^/?]+)#', $path, $m) && Compactor::isSecretConstant(urldecode($m[1]))) {
            return Compactor::MASK;
        }

        return $data;
    }
}
