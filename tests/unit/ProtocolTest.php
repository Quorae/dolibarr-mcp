<?php

declare(strict_types=1);

namespace DoliMcp\Tests\Unit;

use DoliMcp\Http\Request;
use DoliMcp\Http\Response;
use DoliMcp\Mcp\Protocol;
use DoliMcp\Mcp\Tool;
use DoliMcp\Mcp\ToolError;
use DoliMcp\Mcp\ToolRegistry;
use DoliMcp\Mcp\ToolResult;
use PHPUnit\Framework\TestCase;

final class ProtocolTest extends TestCase
{
    private const V = 'io.modelcontextprotocol/protocolVersion';
    private ToolRegistry $registry;
    private array $writes = [];
    private Protocol $protocol;

    protected function setUp(): void
    {
        $this->registry = new ToolRegistry();
        $schema = ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']], 'required' => ['n'], 'additionalProperties' => false];
        $this->registry->add(new Tool('zeta', 'Z', $schema, static fn (array $a): ToolResult => ToolResult::ok(['n' => $a['n'] * 2])));
        $this->registry->add(new Tool('alpha', 'A', $schema, static fn (array $a): ToolResult => throw new ToolError('Customer not found')));
        $this->registry->add(new Tool('boom', 'B', $schema, static fn (array $a): ToolResult => throw new \LogicException('secret path /srv/x')));
        $this->registry->add(new Tool('file', 'F', $schema, static fn (array $a): ToolResult => ToolResult::file('dolibarr://f', 'application/pdf', 'QUJD', ['name' => 'f.pdf'])));
        $this->registry->add(new Tool('write', 'W', $schema, static fn (array $a): ToolResult => ToolResult::ok(['done' => true]), true));
        $this->protocol = new Protocol($this->registry, '1.2.3', function (Tool $t, array $args, ToolResult $r): void {
            $this->writes[] = [$t->name, $args, $r->isError];
        });
    }

    private function modern(string $method, array $params = [], array $headers = [], int $id = 1): Response
    {
        $params['_meta'] = [self::V => '2026-07-28'];
        $headers += ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => $method];
        if (isset($params['name'])) {
            $headers += ['mcp-name' => $params['name']];
        }
        return $this->post(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], $headers);
    }

    private function post(array $body, array $headers = []): Response
    {
        return $this->protocol->handle(new Request('POST', $headers, json_encode($body)));
    }

    private function decode(Response $r): array
    {
        return json_decode($r->body, true);
    }

    public function testGetIsRejectedWith405(): void
    {
        $r = $this->protocol->handle(new Request('GET', [], ''));
        $this->assertSame(405, $r->status);
        $this->assertSame('', $r->body);
    }

    public function testBatchIsRejected(): void
    {
        $r = $this->protocol->handle(new Request('POST', [], '[{"jsonrpc":"2.0","id":1,"method":"ping"}]'));
        $this->assertSame(400, $r->status);
        $this->assertSame(-32600, $this->decode($r)['error']['code']);
    }

    public function testInitializeEchoesASupported2025Version(): void
    {
        $r = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]);
        $res = $this->decode($r)['result'];
        $this->assertSame('2025-06-18', $res['protocolVersion']);
        $this->assertSame(['tools' => ['listChanged' => false]], $res['capabilities']);
        $this->assertSame(['name' => 'dolibarr-mcp', 'version' => '1.2.3'], $res['serverInfo']);
    }

    public function testInitializeFallsBackTo20251125(): void
    {
        $r = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']]);
        $this->assertSame('2025-11-25', $this->decode($r)['result']['protocolVersion']);
    }

    public function testNotificationGets202(): void
    {
        $r = $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $this->assertSame(202, $r->status);
        $this->assertSame('', $r->body);
    }

    public function testModernRequestWithMismatchedMethodHeaderGets32020(): void
    {
        $r = $this->modern('ping', [], ['mcp-method' => 'tools/list']);
        $this->assertSame(400, $r->status);
        $this->assertSame(-32020, $this->decode($r)['error']['code']);
    }

    public function testBase64McpNameIsDecoded(): void
    {
        $name = 'zeta';
        $r = $this->modern('tools/call', ['name' => $name, 'arguments' => ['n' => 1]], ['mcp-name' => '=?base64?' . base64_encode($name) . '?=']);
        $this->assertSame(200, $r->status);
        $this->assertArrayHasKey('result', $this->decode($r));
    }

    public function testMismatchedMcpNameGets32020(): void
    {
        $r = $this->modern('tools/call', ['name' => 'zeta', 'arguments' => ['n' => 1]], ['mcp-name' => 'alpha']);
        $this->assertSame(-32020, $this->decode($r)['error']['code']);
    }

    public function testUnknownVersionGets32022WithSupportedList(): void
    {
        $r = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['mcp-protocol-version' => '2030-01-01']);
        $this->assertSame(400, $r->status);
        $err = $this->decode($r)['error'];
        $this->assertSame(-32022, $err['code']);
        $this->assertSame(['2026-07-28', '2025-03-26', '2025-06-18', '2025-11-25'], $err['data']['supported']);
        $this->assertSame('2030-01-01', $err['data']['requested']);
    }

    public function testLegacyRequestWithNoVersionIsAccepted(): void
    {
        $r = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        $this->assertSame([], $this->decode($r)['result']);
    }

    public function testServerDiscover(): void
    {
        $res = $this->decode($this->modern('server/discover'))['result'];
        $this->assertSame('complete', $res['resultType']);
        $this->assertSame(['2026-07-28', '2025-03-26', '2025-06-18', '2025-11-25'], $res['supportedVersions']);
        $this->assertSame(['tools' => []], $res['capabilities']);
        $this->assertNotSame('', $res['instructions']);
        $this->assertSame(['name' => 'dolibarr-mcp', 'version' => '1.2.3'], $res['_meta']['io.modelcontextprotocol/serverInfo']);
    }

    public function testPingReturnsEmptyObject(): void
    {
        $this->assertSame('{"jsonrpc":"2.0","id":1,"result":{}}', $this->modern('ping')->body);
    }

    public function testToolsListIsSortedWithCacheFields(): void
    {
        $res = $this->decode($this->modern('tools/list'))['result'];
        $this->assertSame('complete', $res['resultType']);
        $this->assertSame(['alpha', 'boom', 'file', 'write', 'zeta'], array_column($res['tools'], 'name'));
        $this->assertSame(['name', 'description', 'inputSchema'], array_keys($res['tools'][0]));
        $this->assertSame(3600000, $res['ttlMs']);
        $this->assertSame('private', $res['cacheScope']);
    }

    public function testUnknownToolGets32602(): void
    {
        $r = $this->modern('tools/call', ['name' => 'nope', 'arguments' => []]);
        $this->assertSame(-32602, $this->decode($r)['error']['code']);
    }

    public function testInvalidArgumentsGet32602WithDetails(): void
    {
        $r = $this->modern('tools/call', ['name' => 'zeta', 'arguments' => ['n' => 'x']]);
        $err = $this->decode($r)['error'];
        $this->assertSame(-32602, $err['code']);
        $this->assertStringContainsString('n must be of type integer', $err['message']);
    }

    public function testToolErrorBecomesIsError(): void
    {
        $res = $this->decode($this->modern('tools/call', ['name' => 'alpha', 'arguments' => ['n' => 1]]))['result'];
        $this->assertTrue($res['isError']);
        $this->assertSame([['type' => 'text', 'text' => 'Customer not found']], $res['content']);
    }

    public function testUnexpectedExceptionHidesTrace(): void
    {
        $r = $this->modern('tools/call', ['name' => 'boom', 'arguments' => ['n' => 1]]);
        $res = $this->decode($r)['result'];
        $this->assertTrue($res['isError']);
        $this->assertSame('Internal error: LogicException', $res['content'][0]['text']);
        $this->assertStringNotContainsString('secret', $r->body);
    }

    public function testSuccessfulCallReturnsTextAndStructuredContent(): void
    {
        $res = $this->decode($this->modern('tools/call', ['name' => 'zeta', 'arguments' => ['n' => 4]]))['result'];
        $this->assertSame('complete', $res['resultType']);
        $this->assertSame([['type' => 'text', 'text' => '{"n":8}']], $res['content']);
        $this->assertSame(['n' => 8], $res['structuredContent']);
    }

    public function testFileResultReturnsAResourceBlock(): void
    {
        $res = $this->decode($this->modern('tools/call', ['name' => 'file', 'arguments' => ['n' => 1]]))['result'];
        $this->assertSame(['type' => 'resource', 'resource' => ['uri' => 'dolibarr://f', 'mimeType' => 'application/pdf', 'blob' => 'QUJD']], $res['content'][0]);
        $this->assertSame('{"name":"f.pdf"}', $res['content'][1]['text']);
    }

    public function testUnknownMethodIs404WhenModernAnd200WhenLegacy(): void
    {
        $this->assertSame(404, $this->modern('nope/nope')->status);
        $legacy = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'nope/nope']);
        $this->assertSame(200, $legacy->status);
        $this->assertSame(-32601, $this->decode($legacy)['error']['code']);
    }

    public function testOnWriteRunsForWritingToolsOnly(): void
    {
        $this->modern('tools/call', ['name' => 'zeta', 'arguments' => ['n' => 1]]);
        $this->assertSame([], $this->writes);
        $this->modern('tools/call', ['name' => 'write', 'arguments' => ['n' => 1]]);
        $this->assertSame([['write', ['n' => 1], false]], $this->writes);
    }
}
