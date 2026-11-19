<?php

declare(strict_types=1);

namespace DoliMcp\Tests\Integration;

final class SetupToolsMoreTest extends McpTestCase
{
    public function testInvoiceNumberingMaskGivesTheExpectedExample(): void
    {
        $before = $this->callTool('document_setup_get', ['doc_type' => 'invoice']);
        $this->assertFalse($before['isError'], $before['text']);
        $this->assertSame('mod_facture_terre', $before['data']['numbering_model']);
        $this->assertContains('mod_facture_mercure', $before['data']['available_numbering_models']);
        $this->assertContains('sponge', $before['data']['available_pdf_models']);
        try {
            $set = $this->callTool('document_setup_set', ['doc_type' => 'invoice', 'numbering_model' => 'mod_facture_mercure', 'masks' => ['invoice' => 'F-{yyyy}-{000@1}']]);
            $this->assertFalse($set['isError'], $set['text']);
            $this->assertSame('mod_facture_mercure', $set['data']['numbering_model']);
            $this->assertSame('F-{yyyy}-{000@1}', $set['data']['masks']['invoice']);
            $this->assertMatchesRegularExpression('/^F-' . date('Y') . '-001$/', $set['data']['next_example']);
        } finally {
            $this->callTool('const_set', ['name' => 'FACTURE_ADDON', 'value' => $before['data']['numbering_model']]);
            $this->callTool('const_set', ['name' => 'FACTURE_MERCURE_MASK_INVOICE', 'value' => '']);
        }
    }

    public function testUnknownNumberingModelAndMaskAreRefused(): void
    {
        $this->assertTrue($this->callTool('document_setup_set', ['doc_type' => 'invoice', 'numbering_model' => 'mod_nope'])['isError']);
        $this->assertTrue($this->callTool('document_setup_set', ['doc_type' => 'proposal', 'masks' => ['invoice' => 'X']])['isError']);
    }

    public function testProposalPdfModelCanBeChanged(): void
    {
        $before = $this->callTool('document_setup_get', ['doc_type' => 'proposal'])['data'];
        $this->assertSame('cyan', $before['pdf_model']);
        try {
            $set = $this->callTool('document_setup_set', ['doc_type' => 'proposal', 'pdf_model' => 'azur']);
            $this->assertFalse($set['isError'], $set['text']);
            $this->assertSame('azur', $set['data']['pdf_model']);
            $this->assertSame('azur', $this->callTool('document_setup_get', ['doc_type' => 'proposal'])['data']['pdf_model']);
            $declared = self::db()->query("SELECT 1 FROM llx_document_model WHERE nom = 'azur' AND type = 'propal'");
            $this->assertSame(1, $declared->num_rows);
            $this->assertTrue($this->callTool('document_setup_set', ['doc_type' => 'proposal', 'pdf_model' => 'nope'])['isError']);
        } finally {
            $this->callTool('document_setup_set', ['doc_type' => 'proposal', 'pdf_model' => $before['pdf_model']]);
            self::db()->query("DELETE FROM llx_document_model WHERE nom = 'azur' AND type = 'propal'");
        }
    }

    public function testVatRateCanBeAddedAndDeactivated(): void
    {
        $id = 0;
        try {
            $add = $this->callTool('dictionary_set', ['dictionary' => 'vat', 'fields' => ['code' => 'DMT', 'taux' => 5.5, 'note' => 'dolimcp test'], 'active' => true]);
            $this->assertFalse($add['isError'], $add['text']);
            $id = (int) $add['data']['id'];
            $this->assertGreaterThan(0, $id);

            $find = fn (bool $activeOnly): array => array_values(array_filter(
                $this->callTool('dictionary_list', ['dictionary' => 'vat', 'active_only' => $activeOnly])['data'],
                static fn (array $r): bool => $r['id'] === $id,
            ));
            $this->assertSame(1, (int) $find(true)[0]['fk_pays']);
            $this->assertEquals(5.5, $find(true)[0]['taux']);

            $off = $this->callTool('dictionary_set', ['dictionary' => 'vat', 'id' => $id, 'fields' => [], 'active' => false]);
            $this->assertFalse($off['isError'], $off['text']);
            $this->assertSame([], $find(true));
            $this->assertSame(0, (int) $find(false)[0]['active']);
        } finally {
            self::db()->query("DELETE FROM llx_c_tva WHERE code = 'DMT'");
        }
    }

    public function testPaymentTermCanBeAdded(): void
    {
        try {
            $add = $this->callTool('dictionary_set', ['dictionary' => 'payment_terms', 'fields' => ['code' => 'DMT45', 'libelle' => '45 days test', 'libelle_facture' => 'Payable in 45 days', 'nbjour' => 45, 'type_cdr' => 0, 'decalage' => 0]]);
            $this->assertFalse($add['isError'], $add['text']);
            $rows = array_filter($this->callTool('dictionary_list', ['dictionary' => 'payment_terms'])['data'], static fn (array $r): bool => $r['code'] === 'DMT45');
            $this->assertCount(1, $rows);
            $this->assertSame(45, (int) array_values($rows)[0]['nbjour']);
        } finally {
            self::db()->query("DELETE FROM llx_c_payment_term WHERE code = 'DMT45'");
        }
    }

    public function testPaymentMethodCanBeAddedAndUpdated(): void
    {
        try {
            $add = $this->callTool('dictionary_set', ['dictionary' => 'payment_methods', 'fields' => ['code' => 'DMT', 'libelle' => 'Test method', 'type' => 2]]);
            $this->assertFalse($add['isError'], $add['text']);
            $id = (int) $add['data']['id'];
            $upd = $this->callTool('dictionary_set', ['dictionary' => 'payment_methods', 'id' => $id, 'fields' => ['libelle' => 'Renamed']]);
            $this->assertFalse($upd['isError'], $upd['text']);
            $rows = array_values(array_filter($this->callTool('dictionary_list', ['dictionary' => 'payment_methods'])['data'], static fn (array $r): bool => $r['id'] === $id));
            $this->assertSame('Renamed', $rows[0]['libelle']);
        } finally {
            self::db()->query("DELETE FROM llx_c_paiement WHERE code = 'DMT'");
        }
    }

    public function testUnknownDictionaryColumnIsRefused(): void
    {
        $r = $this->callTool('dictionary_set', ['dictionary' => 'vat', 'fields' => ['taux' => 1, 'bogus' => 'x']]);
        $this->assertTrue($r['isError']);
        $this->assertStringContainsString('bogus', $r['text']);
        $this->assertStringContainsString('recuperableonly', $r['text']);
        $this->assertSame(0, self::db()->query("SELECT 1 FROM llx_c_tva WHERE taux = 1 AND note IS NULL AND code = ''")->num_rows);
    }

    public function testExtrafieldLifecycle(): void
    {
        $find = fn (): array => array_values(array_filter(
            $this->callTool('extrafield_list', ['element' => 'projet'])['data'],
            static fn (array $e): bool => $e['name'] === 'tjm',
        ));
        $this->callTool('extrafield_delete', ['element' => 'projet', 'name' => 'tjm']);
        try {
            $set = $this->callTool('extrafield_set', ['element' => 'projet', 'name' => 'tjm', 'label' => 'Daily rate', 'type' => 'price', 'position' => 120]);
            $this->assertFalse($set['isError'], $set['text']);
            $this->assertSame('Daily rate', $find()[0]['label']);
            $this->assertSame('price', $find()[0]['type']);

            $upd = $this->callTool('extrafield_set', ['element' => 'projet', 'name' => 'tjm', 'label' => 'Rate per day', 'type' => 'price', 'required' => true]);
            $this->assertFalse($upd['isError'], $upd['text']);
            $this->assertSame('Rate per day', $find()[0]['label']);
            $this->assertTrue($find()[0]['required']);

            $del = $this->callTool('extrafield_delete', ['element' => 'projet', 'name' => 'tjm']);
            $this->assertFalse($del['isError'], $del['text']);
            $this->assertSame([], $find());
        } finally {
            $this->callTool('extrafield_delete', ['element' => 'projet', 'name' => 'tjm']);
        }
    }

    public function testRightsCanBeGrantedAndRevoked(): void
    {
        $user = $this->restrictedUser();
        $rightId = (int) self::db()->query("SELECT id FROM llx_rights_def WHERE module = 'facture' AND perms = 'lire' AND (subperms IS NULL OR subperms = '')")->fetch_row()[0];
        $has = fn (): bool => in_array($rightId, array_column($this->callTool('user_rights_get', ['user_id' => $user['id']])['data'], 'id'), true);
        $this->assertFalse($has());

        $grant = $this->callTool('user_rights_set', ['user_id' => $user['id'], 'grant' => true, 'right_ids' => [$rightId]]);
        $this->assertFalse($grant['isError'], $grant['text']);
        $this->assertTrue($has());
        $row = array_values(array_filter($this->callTool('user_rights_get', ['user_id' => $user['id']])['data'], static fn (array $r): bool => $r['id'] === $rightId))[0];
        $this->assertSame('facture', $row['module']);
        $this->assertSame('lire', $row['permission']);

        $revoke = $this->callTool('user_rights_set', ['user_id' => $user['id'], 'grant' => false, 'right_ids' => [$rightId]]);
        $this->assertFalse($revoke['isError'], $revoke['text']);
        $this->assertFalse($has());

        $this->assertTrue($this->callTool('user_rights_set', ['user_id' => $user['id'], 'grant' => true])['isError']);
        $this->assertTrue($this->callTool('user_rights_set', ['user_id' => 999999, 'grant' => true, 'right_ids' => [$rightId]])['isError']);
    }

    public function testCronJobCanBeDisabledAndEnabled(): void
    {
        $find = fn (): array => array_values(array_filter($this->callTool('cron_list')['data'], static fn (array $j): bool => $j['id'] === 1))[0];
        $this->assertTrue($find()['enabled']);
        $this->assertSame('RecurringInvoicesJob', $find()['label']);
        try {
            $off = $this->callTool('cron_set', ['id' => 1, 'enabled' => false]);
            $this->assertFalse($off['isError'], $off['text']);
            $this->assertFalse($find()['enabled']);

            $on = $this->callTool('cron_set', ['id' => 1, 'enabled' => true]);
            $this->assertFalse($on['isError'], $on['text']);
            $this->assertTrue($find()['enabled']);
            $this->assertTrue($this->callTool('cron_set', ['id' => 999999, 'enabled' => true])['isError']);
        } finally {
            $this->callTool('cron_set', ['id' => 1, 'enabled' => true]);
        }
    }

    public function testNonAdminIsRefused(): void
    {
        $user = $this->restrictedUser();
        foreach ([['document_setup_get', ['doc_type' => 'invoice']], ['dictionary_list', ['dictionary' => 'vat']], ['extrafield_list', []], ['user_rights_get', ['user_id' => 1]], ['cron_list', []]] as [$tool, $args]) {
            $r = $this->callTool($tool, $args, $user['key']);
            $this->assertTrue($r['isError'], $tool);
            $this->assertStringContainsString('Administrator rights required', $r['text']);
        }
    }
}
