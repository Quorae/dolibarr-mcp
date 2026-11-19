<?php

declare(strict_types=1);

namespace DoliMcp\Tests\Integration;

final class ApiToolsTest extends McpTestCase
{
    public function testGetOnEmptyListReturnsEmptyArray(): void
    {
        $r = $this->callTool('api_request', ['method' => 'GET', 'path' => 'thirdparties', 'query' => ['sqlfilters' => "(t.nom:=:'no-such-thirdparty-xyz')"]]);
        $this->assertFalse($r['isError'], $r['text']);
        $this->assertSame([], $r['data']);
    }

    public function testPostCreatesAThirdpartyAndIsJournaled(): void
    {
        $name = 'Journaled ' . bin2hex(random_bytes(4));
        $r = $this->callTool('api_request', ['method' => 'POST', 'path' => 'thirdparties', 'body' => ['name' => $name]]);
        $this->assertFalse($r['isError'], $r['text']);

        $found = $this->rest('GET', 'thirdparties/' . (int) $r['text']);
        $this->assertSame($name, $found['body']['name']);

        $row = self::db()->query("SELECT outcome, params FROM llx_dolimcp_journal WHERE tool = 'api_request' ORDER BY rowid DESC LIMIT 1")->fetch_assoc();
        $this->assertSame('ok', $row['outcome']);
        $this->assertStringContainsString($name, $row['params']);
    }

    public function testUserApiKeyIsMasked(): void
    {
        $id = (int) $this->rest('GET', 'users/info')['body']['id'];
        $r = $this->callTool('api_request', ['method' => 'GET', 'path' => 'users/' . $id]);
        $this->assertFalse($r['isError'], $r['text']);
        $this->assertStringNotContainsString(self::apiKey(), $r['text']);
        // Dolibarr already drops the key here; whatever stays must be masked.
        $this->assertContains($r['data']['api_key'] ?? '********', ['********']);
    }

    public function testEndpointsSearchFindsInvoicesValidate(): void
    {
        $r = $this->callTool('api_endpoints', ['query' => 'invoices/{id}/validate']);
        $this->assertFalse($r['isError'], $r['text']);
        $this->assertContains('POST', array_column($r['data'], 'method'));
        $this->assertContains('/invoices/{id}/validate', array_column($r['data'], 'path'));
    }

    public function testSecretConstantIsMasked(): void
    {
        self::db()->query("DELETE FROM llx_const WHERE name = 'MAIN_MAIL_SMTPS_PW_TEST'");
        self::db()->query("INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('MAIN_MAIL_SMTPS_PW_TEST', 1, 'hunter2-secret', 'chaine', 0)");
        $r = $this->callTool('api_request', ['method' => 'GET', 'path' => 'setup/conf/MAIN_MAIL_SMTPS_PW_TEST']);
        $this->assertStringNotContainsString('hunter2-secret', $r['text']);
    }

    public function testContextKeyIsTheAuthenticatedOneWhenApiKeyParamIsEmpty(): void
    {
        $url = getenv('MCP_URL') . '?api_key=';
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'api_request', 'arguments' => ['method' => 'GET', 'path' => 'users/info'], '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']]]);
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'content' => $body, 'header' => implode("\r\n", [
            'Accept: application/json, text/event-stream', 'Content-Type: application/json',
            'Authorization: Bearer ' . self::apiKey(), 'MCP-Protocol-Version: 2026-07-28', 'Mcp-Method: tools/call', 'Mcp-Name: api_request',
        ])]]);
        $result = json_decode((string) file_get_contents($url, false, $ctx), true)['result'] ?? [];
        $this->assertFalse($result['isError'] ?? false, json_encode($result));
        $this->assertSame('admin', $result['structuredContent']['login'] ?? null);
    }
}
