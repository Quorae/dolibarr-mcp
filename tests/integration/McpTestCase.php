<?php

declare(strict_types=1);

namespace DoliMcp\Tests\Integration;

use PHPUnit\Framework\TestCase;

abstract class McpTestCase extends TestCase
{
    private const ADMIN_LOGIN = 'admin';
    private const ADMIN_PASSWORD = 'admin-test-pass';
    private const PROTOCOL_VERSION = '2026-07-28';

    private static ?string $apiKey = null;
    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        // The setup route matches the descriptor file name (modDoliMcp); it is a no-op when already enabled.
        $response = self::request('PUT', self::env('REST_URL') . '/setup/modules/modDoliMcp/enable', [], null, self::apiKey());
        if ($response['status'] !== 200) {
            throw new \RuntimeException('Could not enable the module: HTTP ' . $response['status'] . ' ' . $response['raw']);
        }
    }

    protected static function apiKey(): string
    {
        if (self::$apiKey === null) {
            // Dolibarr disables POST /login by default; the test instance turns it on.
            self::db()->query("DELETE FROM llx_const WHERE name = 'API_ENABLE_LOGIN_API'");
            self::db()->query("INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('API_ENABLE_LOGIN_API', 1, '1', 'chaine', 0)");
            $response = self::request('POST', self::env('REST_URL') . '/login', [], [
                'login' => self::ADMIN_LOGIN,
                'password' => self::ADMIN_PASSWORD,
            ]);
            $key = $response['body']['success']['token'] ?? null;
            if (!is_string($key)) {
                throw new \RuntimeException('Login failed: HTTP ' . $response['status'] . ' ' . $response['raw']);
            }
            self::$apiKey = $key;
        }

        return self::$apiKey;
    }

    protected static function db(): \mysqli
    {
        return self::$db ??= new \mysqli(
            self::env('DB_HOST'),
            self::env('DB_USER'),
            self::env('DB_PASSWORD'),
            self::env('DB_NAME'),
        );
    }

    /** @return array{status: int, body: mixed} */
    protected function rest(string $method, string $path, ?array $body = null, ?string $key = null): array
    {
        $response = self::request($method, self::env('REST_URL') . '/' . ltrim($path, '/'), [], $body, $key ?? self::apiKey());

        return ['status' => $response['status'], 'body' => $response['body']];
    }

    /** @return array{status: int, headers: array<string, string>, body: ?array} */
    protected function mcp(array $message, array $headers = [], ?string $key = null): array
    {
        $response = self::request('POST', self::env('MCP_URL'), $headers, $message, $key ?? self::apiKey());

        return ['status' => $response['status'], 'headers' => $response['headers'], 'body' => is_array($response['body']) ? $response['body'] : null];
    }

    /** @return array{isError: bool, data: mixed, text: string, content: array} */
    protected function callTool(string $name, array $arguments = [], ?string $key = null): array
    {
        $response = $this->mcp(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => $name,
                    'arguments' => (object) $arguments,
                    '_meta' => ['io.modelcontextprotocol/protocolVersion' => self::PROTOCOL_VERSION],
                ],
            ],
            [
                'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
                'Mcp-Method' => 'tools/call',
                'Mcp-Name' => $name,
            ],
            $key,
        );

        $result = $response['body']['result'] ?? [];
        $content = $result['content'] ?? [];
        $text = '';
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        return [
            'isError' => (bool) ($result['isError'] ?? false),
            'data' => $result['structuredContent'] ?? null,
            'text' => $text,
            'content' => $content,
        ];
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        if ($value === false) {
            throw new \RuntimeException("Missing environment variable $name (run the tests through bin/test).");
        }

        return $value;
    }

    /** @return array{status: int, headers: array<string, string>, body: mixed, raw: string} */
    private static function request(string $method, string $url, array $headers, ?array $body, ?string $key = null): array
    {
        $lines = ['Accept: application/json, text/event-stream'];
        if ($body !== null) {
            $lines[] = 'Content-Type: application/json';
        }
        if ($key !== null) {
            $lines[] = 'DOLAPIKEY: ' . $key;
        }
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $lines),
            'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 60,
        ]]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new \RuntimeException("Request failed: $method $url");
        }

        $status = 0;
        $responseHeaders = [];
        foreach (http_get_last_response_headers() ?? [] as $line) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) {
                $status = (int) $m[1];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => json_decode($raw, true), 'raw' => $raw];
    }
}
