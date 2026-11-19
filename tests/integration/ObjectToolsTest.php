<?php

declare(strict_types=1);

namespace DoliMcp\Tests\Integration;

final class ObjectToolsTest extends McpTestCase
{
    public function testSearchWithNoMatchReturnsEmptyArray(): void
    {
        $r = $this->callTool('object_search', ['type' => 'thirdparty', 'sqlfilters' => "(t.nom:=:'no-such-thirdparty-xyz')"]);
        $this->assertFalse($r['isError'], $r['text']);
        $this->assertSame([], $r['data']);
    }

    public function testThirdpartyCrud(): void
    {
        $name = 'Crud ' . bin2hex(random_bytes(4));
        $created = $this->callTool('object_create', ['type' => 'thirdparty', 'fields' => ['name' => $name]]);
        $this->assertFalse($created['isError'], $created['text']);
        $id = (int) $created['data']['id'];
        $this->assertSame($name, $created['data']['name']);
        $this->assertSame($name, $this->rest('GET', "thirdparties/$id")['body']['name']);

        $found = $this->callTool('object_search', ['type' => 'thirdparty', 'sqlfilters' => "(t.nom:=:'$name')"]);
        $this->assertSame([$id], array_map('intval', array_column($found['data'], 'id')));

        $updated = $this->callTool('object_update', ['type' => 'thirdparty', 'id' => $id, 'fields' => ['name' => "$name bis"]]);
        $this->assertFalse($updated['isError'], $updated['text']);
        $this->assertSame("$name bis", $updated['data']['name']);
        $this->assertSame("$name bis", $this->callTool('object_get', ['type' => 'thirdparty', 'id' => $id])['data']['name']);

        $this->assertFalse($this->callTool('object_delete', ['type' => 'thirdparty', 'id' => $id])['isError']);
        $this->assertTrue($this->callTool('object_get', ['type' => 'thirdparty', 'id' => $id])['isError']);
    }

    public function testInvoiceCreatedWithLinesHasTotalsAndDraftLabel(): void
    {
        $tp = $this->rest('POST', 'thirdparties', ['name' => 'Invoiced ' . bin2hex(random_bytes(4)), 'client' => 1]);
        $thirdpartyId = (int) $tp['body'];
        $r = $this->callTool('object_create', ['type' => 'invoice', 'fields' => [
            'socid' => $thirdpartyId,
            'date' => time(),
            'lines' => [
                ['desc' => 'A', 'qty' => 1, 'subprice' => 100, 'tva_tx' => 20],
                ['desc' => 'B', 'qty' => 1, 'subprice' => 50, 'tva_tx' => 20],
            ],
        ]]);
        try {
            $this->assertFalse($r['isError'], $r['text']);
            $this->assertEquals(150, $r['data']['total_ht']);
            $this->assertEquals(180, $r['data']['total_ttc']);
            $this->assertSame('draft', $r['data']['status_label']);
        } finally {
            if (isset($r['data']['id'])) {
                $this->rest('DELETE', 'invoices/' . (int) $r['data']['id']);
            }
            $this->rest('DELETE', "thirdparties/$thirdpartyId");
        }
    }

    public function testCustomFieldIsWrittenAndRead(): void
    {
        // 409/500 when it already exists from a previous run: ignored on purpose.
        $this->rest('POST', 'setup/extrafields/societe/testfield', ['type' => 'varchar', 'label' => 'Test field', 'size' => '50', 'pos' => 100]);
        $created = $this->callTool('object_create', ['type' => 'thirdparty', 'fields' => ['name' => 'Extra ' . bin2hex(random_bytes(4)), 'array_options' => ['options_testfield' => 'hello']]]);
        $this->assertFalse($created['isError'], $created['text']);
        $id = (int) $created['data']['id'];
        try {
            $read = $this->callTool('object_get', ['type' => 'thirdparty', 'id' => $id]);
            $this->assertSame('hello', $read['data']['array_options']['options_testfield']);
        } finally {
            $this->rest('DELETE', "thirdparties/$id");
        }
    }

    public function testUnknownTypeIsRejected(): void
    {
        $response = $this->mcp(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'object_get', 'arguments' => ['type' => 'nope', 'id' => 1], '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']]],
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'object_get'],
        );
        $this->assertSame(-32602, $response['body']['error']['code']);
    }
}
