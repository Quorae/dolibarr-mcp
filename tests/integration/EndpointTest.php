<?php

declare(strict_types=1);

namespace DoliMcp\Tests\Integration;

final class EndpointTest extends McpTestCase
{
    private const PING = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];

    public function testMissingKeyGets401Json(): void
    {
        $response = ($this->mcp(self::PING, [], ''));

        $this->assertSame(401, $response['status']);
        $this->assertSame(-32001, $response['body']['error']['code']);
        $this->assertNotSame('', $response['body']['error']['message']);
    }

    public function testWrongKeyGets401Json(): void
    {
        $response = ($this->mcp(self::PING, [], 'not-a-real-key'));

        $this->assertSame(401, $response['status']);
        $this->assertSame(-32001, $response['body']['error']['code']);
    }

    public function testForeignOriginGets403(): void
    {
        $response = ($this->mcp(self::PING, ['Origin' => 'https://evil.example.com']));

        $this->assertSame(403, $response['status']);
    }

    public function testGetGets405(): void
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => 'DOLAPIKEY: ' . self::apiKey(),
            'ignore_errors' => true,
        ]]);
        @file_get_contents((string) getenv('MCP_URL'), false, $context);

        $this->assertStringContainsString(' 405', http_get_last_response_headers()[0]);
    }

    public function testLegacyClientCanInitializeListAndCall(): void
    {
        $init = ($this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]));
        $this->assertSame(200, $init['status']);
        $this->assertSame('2025-06-18', $init['body']['result']['protocolVersion']);

        $list = ($this->mcp(self::PING));
        $names = array_column($list['body']['result']['tools'], 'name');
        $this->assertContains('whoami', $names);

        $call = ($this->mcp(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'whoami', 'arguments' => (object) []]]));
        $this->assertSame(200, $call['status']);
        $this->assertFalse($call['body']['result']['isError'] ?? false);
    }

    public function testModernClientCanDiscoverAndCallWhoami(): void
    {
        $result = $this->callTool('whoami');

        $this->assertFalse($result['isError']);
        $this->assertSame('admin', $result['data']['login']);
        $this->assertTrue($result['data']['admin']);
        $this->assertContains('dolimcp', $result['data']['enabled_modules']);
        $this->assertArrayHasKey('dolibarr_version', $result['data']);
        $this->assertArrayHasKey('entity', $result['data']);
    }

    public function testBearerKeyWorksWhenApiKeyQueryParameterIsEmpty(): void
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . self::apiKey(),
            'content' => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']),
            'ignore_errors' => true,
        ]]);
        $raw = file_get_contents(getenv('MCP_URL') . '?api_key=', false, $context);

        $this->assertContains('whoami', array_column(json_decode($raw, true)['result']['tools'], 'name'));
    }

    public function testEveryResponseIsJson(): void
    {
        $call = ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'whoami', 'arguments' => (object) []]];
        $responses = [
            $this->mcp(self::PING, [], ''),
            $this->mcp(self::PING, [], 'not-a-real-key'),
            $this->mcp(self::PING, ['Origin' => 'https://evil.example.com']),
            $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]),
            $this->mcp($call, ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'whoami']),
        ];

        foreach ($responses as $response) {
            $this->assertNotNull($response['body']);
            $this->assertStringStartsWith('application/json', $response['headers']['content-type'] ?? '');
        }
    }

    public function testIpRestrictionAnswers403Json(): void
    {
        self::setConst('API_RESTRICT_ON_IP', '10.99.99.99');
        try {
            $response = $this->mcp(self::PING);
            $this->assertSame(403, $response['status']);
            $this->assertSame(-32004, $response['body']['error']['code']);
        } finally {
            self::db()->query("DELETE FROM llx_const WHERE name = 'API_RESTRICT_ON_IP'");
        }
        $this->assertSame(200, $this->mcp(self::PING)['status']);
    }

    public function testArrayApiKeyGets401Json(): void
    {
        $raw = file_get_contents(getenv('MCP_URL') . '?api_key[]=x', false, stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json\r\nAccept: application/json", 'content' => json_encode(self::PING), 'ignore_errors' => true,
        ]]));
        $this->assertStringContainsString('HTTP/1.1 401', implode("\n", http_get_last_response_headers()));
        $this->assertSame(-32001, json_decode((string) $raw, true)['error']['code'] ?? null);
    }

    public function testKeyMatchedOnlyByCollationGets401Json(): void
    {
        $key = $this->apiKey();
        $other = strtoupper($key) === $key ? strtolower($key) : strtoupper($key);
        $this->assertNotSame($key, $other);
        $response = $this->mcp(self::PING, [], $other);
        $this->assertSame(401, $response['status']);
        $this->assertSame(-32001, $response['body']['error']['code']);
    }
}
