<?php

declare(strict_types=1);

namespace DoliMcp\Tests\Integration;

use PHPUnit\Framework\TestCase;

abstract class McpTestCase extends TestCase
{
    private const ADMIN_LOGIN = 'admin';
    private const ADMIN_PASSWORD = 'admin-test-pass';
    private const PROTOCOL_VERSION = '2026-07-28';

    /** @var list<callable> */
    protected array $cleanup = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $undo) {
            try {
                $undo();
            } catch (\Throwable) {
            }
        }
        $this->cleanup = [];
    }

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

    /** Writes a Dolibarr constant the way dolibarr_set_const does (delete then insert, entity 1). */
    protected static function setConst(string $name, string $value): void
    {
        $name = self::db()->real_escape_string($name);
        $value = self::db()->real_escape_string($value);
        self::db()->query("DELETE FROM llx_const WHERE name = '$name' AND entity = 1");
        self::db()->query("INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('$name', 1, '$value', 'chaine', 0)");
    }

    /** @return array{id: int, key: string} a non-admin user who may read projects and log time (and renew their own key, which /login needs) */
    protected function restrictedUser(int $thirdpartyId = 0): array
    {
        $login = 'tm' . bin2hex(random_bytes(4));
        $fields = ['login' => $login, 'pass' => 'Restricted-pass-1', 'lastname' => $login, 'admin' => 0];
        $id = (int) $this->rest('POST', 'users', $fields)['body'];
        $this->assertGreaterThan(0, $id);
        $this->cleanup[] = function () use ($id): void {
            self::db()->query("DELETE FROM llx_user_rights WHERE fk_user = $id");
            $this->rest('DELETE', "users/$id");
        };
        self::db()->query("INSERT INTO llx_user_rights (entity, fk_user, fk_id) SELECT 1, $id, id FROM llx_rights_def WHERE (module = 'projet' AND perms IN ('lire', 'time') AND (subperms IS NULL OR subperms = '')) OR (module = 'user' AND perms = 'self' AND subperms = 'creer')");
        $this->assertSame(3, self::db()->affected_rows);
        if ($thirdpartyId > 0) { // the REST API does not set the third party of a user
            self::db()->query("UPDATE llx_user SET fk_soc = $thirdpartyId WHERE rowid = $id");
        }
        // POST /login with the password sets and returns the API key of the user.
        $url = getenv('REST_URL') . '/login';
        $raw = file_get_contents($url, false, stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json\r\nAccept: application/json", 'content' => json_encode(['login' => $login, 'password' => 'Restricted-pass-1']), 'ignore_errors' => true,
        ]]));
        $key = json_decode((string) $raw, true)['success']['token'] ?? null;
        $this->assertIsString($key, (string) $raw);

        return ['id' => $id, 'key' => $key];
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

        $isError = (bool) ($result['isError'] ?? false);
        // structuredContent only carries JSON objects; lists and scalars come back in the text block.
        $data = $result['structuredContent'] ?? ($isError ? null : json_decode($text, true));

        return [
            'isError' => $isError,
            'data' => $data,
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
