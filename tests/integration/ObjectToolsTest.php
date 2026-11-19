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

    public function testPasswordCustomFieldIsMaskedInAnswersAndJournal(): void
    {
        // Left over from a failed run: ignored on purpose.
        $this->rest('DELETE', 'setup/extrafields/societe/testsecret');
        $made = $this->rest('POST', 'setup/extrafields/societe/testsecret', ['type' => 'password', 'label' => 'Test secret', 'size' => '50', 'pos' => 101]);
        $this->assertSame(200, $made['status'], json_encode($made['body']));
        // The REST API stores no `options` for a password field, which Dolibarr then cannot write to; the UI stores an empty one.
        self::db()->query("UPDATE llx_extrafields SET param = 'a:1:{s:7:\"options\";a:1:{s:0:\"\";N;}}' WHERE name = 'testsecret'");
        $id = 0;
        $lastJournalRow = (int) self::db()->query('SELECT COALESCE(MAX(rowid), 0) FROM llx_dolimcp_journal')->fetch_row()[0];
        try {
            $created = $this->callTool('object_create', ['type' => 'thirdparty', 'fields' => ['name' => 'Secret ' . bin2hex(random_bytes(4)), 'array_options' => ['options_testsecret' => 's3cr3t']]]);
            $this->assertFalse($created['isError'], $created['text']);
            $id = (int) $created['data']['id'];
            $this->assertStringNotContainsString('s3cr3t', $created['text']);
            $read = $this->callTool('object_get', ['type' => 'thirdparty', 'id' => $id]);
            $this->assertSame('********', $read['data']['array_options']['options_testsecret']);
            $this->assertStringNotContainsString('s3cr3t', $this->callTool('object_search', ['type' => 'thirdparty', 'sqlfilters' => "(t.rowid:=:$id)"])['text']);
            $this->assertStringNotContainsString('s3cr3t', $this->callTool('api_request', ['method' => 'GET', 'path' => "thirdparties/$id"])['text']);

            $count = self::db()->query("SELECT COUNT(*) FROM llx_dolimcp_journal WHERE rowid > $lastJournalRow AND params LIKE '%s3cr3t%'")->fetch_row()[0];
            $this->assertSame('0', (string) $count);
        } finally {
            if ($id > 0) {
                $this->rest('DELETE', "thirdparties/$id");
            }
            $this->rest('DELETE', 'setup/extrafields/societe/testsecret');
        }
    }

    public function testTextDatesAreStoredAsLocalMidnightAndDriveTheDueDate(): void
    {
        $thirdpartyId = (int) $this->rest('POST', 'thirdparties', ['name' => 'Dates ' . bin2hex(random_bytes(4)), 'client' => 1])['body'];
        $r = $this->callTool('object_create', ['type' => 'invoice', 'fields' => [
            'socid' => $thirdpartyId,
            'date' => '2026-11-19',
            'cond_reglement_id' => 2, // 30 days
            'lines' => [['desc' => 'A', 'qty' => 1, 'subprice' => 100, 'tva_tx' => 20, 'date_start' => '2026-11-01']],
        ]]);
        try {
            $this->assertFalse($r['isError'], $r['text']);
            $this->assertSame('2026-11-19', $r['data']['date']);
            $this->assertSame('2026-12-19', $r['data']['date_lim_reglement']);
            $this->assertSame('2026-11-01', $r['data']['lines'][0]['date_start']);

            $updated = $this->callTool('object_update', ['type' => 'invoice', 'id' => (int) $r['data']['id'], 'fields' => ['date' => '2026-12-01']]);
            $this->assertFalse($updated['isError'], $updated['text']);
            $this->assertSame('2026-12-01', $updated['data']['date']);
        } finally {
            if (isset($r['data']['id'])) {
                $this->rest('DELETE', 'invoices/' . (int) $r['data']['id']);
            }
            $this->rest('DELETE', "thirdparties/$thirdpartyId");
        }
    }

    public function testInvalidDateStringIsRejected(): void
    {
        $r = $this->callTool('object_create', ['type' => 'invoice', 'fields' => ['socid' => 1, 'date' => '19/11/2026']]);
        $this->assertTrue($r['isError']);
        $this->assertStringContainsString('YYYY-MM-DD', $r['text']);
    }
}
