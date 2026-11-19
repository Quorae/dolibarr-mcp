<?php

declare(strict_types=1);

namespace DoliMcp\Tests\Integration;

final class DocumentToolsTest extends McpTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        foreach ([
            'MAIN_MAIL_SENDMODE' => 'smtps',
            'MAIN_MAIL_SMTP_SERVER' => 'mailpit',
            'MAIN_MAIL_SMTP_PORT' => '1025',
            'MAIN_MAIL_EMAIL_FROM' => 'billing@example.com',
        ] as $name => $value) {
            self::setConst($name, $value);
        }
    }

    private function thirdparty(?string $email = null): int
    {
        $fields = ['name' => 'Docs ' . bin2hex(random_bytes(4)), 'client' => 1];
        if ($email !== null) {
            $fields['email'] = $email;
        }

        return (int) $this->rest('POST', 'thirdparties', $fields)['body'];
    }

    private function draftInvoice(int $thirdpartyId, bool $withLine = true): int
    {
        $fields = ['socid' => $thirdpartyId, 'date' => time()];
        if ($withLine) {
            $fields['lines'] = [['desc' => 'Consulting', 'qty' => 1, 'subprice' => 100, 'tva_tx' => 20]];
        }
        $r = $this->callTool('object_create', ['type' => 'invoice', 'fields' => $fields]);
        $this->assertFalse($r['isError'], $r['text']);

        return (int) $r['data']['id'];
    }

    private function draftProposal(int $thirdpartyId): int
    {
        $r = $this->callTool('object_create', ['type' => 'proposal', 'fields' => [
            'socid' => $thirdpartyId,
            'date' => time(),
            'duree_validite' => 30,
            'lines' => [['desc' => 'Consulting', 'qty' => 1, 'subprice' => 100, 'tva_tx' => 20]],
        ]]);
        $this->assertFalse($r['isError'], $r['text']);

        return (int) $r['data']['id'];
    }

    private function bankAccountId(): int
    {
        $existing = $this->rest('GET', 'bankaccounts?limit=1');
        if ($existing['status'] === 200 && isset($existing['body'][0]['id'])) {
            return (int) $existing['body'][0]['id'];
        }
        $created = $this->rest('POST', 'bankaccounts', ['ref' => 'DOCS', 'label' => 'Docs account', 'type' => 1, 'courant' => 1, 'currency_code' => 'EUR', 'country_id' => 1, 'date_solde' => time(), 'solde' => 0]);
        $this->assertSame(200, $created['status'], json_encode($created['body']));

        return (int) $created['body'];
    }

    private function action(string $type, int $id, string $action, array $more = []): array
    {
        return $this->callTool('document_action', ['type' => $type, 'id' => $id, 'action' => $action] + $more);
    }

    public function testProposalToPaidInvoice(): void
    {
        $tp = $this->thirdparty();
        $proposalId = $this->draftProposal($tp);

        $validated = $this->action('proposal', $proposalId, 'validate');
        $this->assertFalse($validated['isError'], $validated['text']);
        $this->assertSame('validated', $validated['data']['status_label']);
        $signed = $this->action('proposal', $proposalId, 'close_signed', ['note' => 'ok by phone']);
        $this->assertFalse($signed['isError'], $signed['text']);
        $this->assertSame('signed', $signed['data']['status_label']);

        $created = $this->action('proposal', $proposalId, 'create_invoice');
        $this->assertFalse($created['isError'], $created['text']);
        $invoiceId = (int) $created['data']['id'];
        $this->assertSame('draft', $created['data']['status_label']);
        $this->assertSame($tp, (int) $created['data']['socid']);
        $this->assertEquals(120, $created['data']['total_ttc']);
        $linked = $this->rest('GET', "objectlinks?fk_source=$proposalId&sourcetype=propal&fk_target=$invoiceId&targettype=facture");
        $this->assertSame(200, $linked['status'], json_encode($linked['body']));

        $invoice = $this->action('invoice', $invoiceId, 'validate');
        $this->assertFalse($invoice['isError'], $invoice['text']);
        $this->assertSame('unpaid', $invoice['data']['status_label']);
        $this->assertStringNotContainsString('PROV', (string) $invoice['data']['ref']);

        $paid = $this->callTool('payment_create', [
            'payments' => [['invoice_id' => $invoiceId, 'amount' => 120]],
            'date' => date('Y-m-d'),
            'payment_mode' => 'VIR',
            'bank_account_id' => $this->bankAccountId(),
        ]);
        $this->assertFalse($paid['isError'], $paid['text']);
        $this->assertGreaterThan(0, $paid['data']['payment_id']);
        $this->assertSame('paid', $paid['data']['invoices'][0]['status_label']);
    }

    public function testValidatingAnInvoiceWithoutLinesIsAReadableError(): void
    {
        $tp = $this->thirdparty();
        $id = $this->draftInvoice($tp, false);
        try {
            $r = $this->action('invoice', $id, 'validate');
            $this->assertTrue($r['isError']);
            $this->assertStringNotContainsString('Internal error', $r['text']);
            $this->assertStringNotContainsStringIgnoringCase('<html', $r['text']);
            $this->assertStringNotContainsString('Stack trace', $r['text']);
        } finally {
            $this->rest('DELETE', "invoices/$id");
            $this->rest('DELETE', "thirdparties/$tp");
        }
    }

    public function testCancelAbandonsAValidatedInvoice(): void
    {
        $tp = $this->thirdparty();
        $id = $this->draftInvoice($tp);
        $this->assertFalse($this->action('invoice', $id, 'validate')['isError']);
        $r = $this->action('invoice', $id, 'cancel', ['note' => 'customer withdrew']);
        $this->assertFalse($r['isError'], $r['text']);
        $this->assertSame('abandoned', $r['data']['status_label']);
    }

    public function testPdfIsGeneratedListedAndDownloaded(): void
    {
        $tp = $this->thirdparty();
        $id = $this->draftInvoice($tp);
        $this->assertFalse($this->action('invoice', $id, 'validate')['isError']);

        $pdf = $this->callTool('document_pdf', ['type' => 'invoice', 'id' => $id]);
        $this->assertFalse($pdf['isError'], $pdf['text']);
        $name = $pdf['data']['filename'];
        $this->assertStringEndsWith('.pdf', $name);
        $this->assertGreaterThan(0, $pdf['data']['size']);
        $this->assertArrayNotHasKey('content', $pdf['data']);

        $list = $this->callTool('file_list', ['type' => 'invoice', 'id' => $id]);
        $this->assertFalse($list['isError'], $list['text']);
        $this->assertContains($name, array_column($list['data'], 'name'));

        $file = $this->callTool('file_get', ['type' => 'invoice', 'id' => $id, 'filename' => $name]);
        $this->assertFalse($file['isError'], $file['text']);
        $resource = array_values(array_filter($file['content'], static fn (array $b): bool => $b['type'] === 'resource'))[0]['resource'];
        $this->assertSame('application/pdf', $resource['mimeType']);
        $this->assertStringStartsWith('%PDF', base64_decode($resource['blob']));
    }

    public function testAttachedFileIsListed(): void
    {
        $tp = $this->thirdparty();
        $id = $this->draftInvoice($tp);
        try {
            $r = $this->callTool('file_attach', ['type' => 'invoice', 'id' => $id, 'filename' => 'note.txt', 'content_base64' => base64_encode('hello')]);
            $this->assertFalse($r['isError'], $r['text']);
            $list = $this->callTool('file_list', ['type' => 'invoice', 'id' => $id]);
            $this->assertContains('note.txt', array_column($list['data'], 'name'));

            $again = $this->callTool('file_attach', ['type' => 'invoice', 'id' => $id, 'filename' => 'note.txt', 'content_base64' => base64_encode('bye')]);
            $this->assertTrue($again['isError'], 'a second upload without overwrite must fail');
            $over = $this->callTool('file_attach', ['type' => 'invoice', 'id' => $id, 'filename' => 'note.txt', 'content_base64' => base64_encode('bye'), 'overwrite' => true]);
            $this->assertFalse($over['isError'], $over['text']);
        } finally {
            $this->rest('DELETE', "invoices/$id");
            $this->rest('DELETE', "thirdparties/$tp");
        }
    }

    public function testSendDeliversAnEmailWithThePdf(): void
    {
        $address = 'client-' . bin2hex(random_bytes(4)) . '@example.com';
        $tp = $this->thirdparty($address);
        $id = $this->draftProposal($tp);
        $this->assertFalse($this->action('proposal', $id, 'validate')['isError']);

        $sent = $this->callTool('document_send', ['type' => 'proposal', 'id' => $id]);
        $this->assertFalse($sent['isError'], $sent['text']);

        $search = $this->mailpit('search?query=' . urlencode("to:$address"));
        $this->assertSame(1, $search['messages_count'] ?? 0, json_encode($search));
        $message = $this->mailpit('message/' . $search['messages'][0]['ID']);
        $this->assertNotEmpty($message['Subject']);
        $this->assertCount(1, $message['Attachments']);
        $this->assertStringEndsWith('.pdf', $message['Attachments'][0]['FileName']);

        $events = $this->rest('GET', 'agendaevents?sqlfilters=' . urlencode("(t.fk_soc:=:$tp)"));
        $this->assertSame(200, $events['status'], json_encode($events['body']));
        $this->assertNotEmpty($events['body']);
        $this->assertContains('AC_PROPAL_SENTBYMAIL', array_column($events['body'], 'code'));
    }

    private function mailpit(string $path): array
    {
        $raw = file_get_contents((string) getenv('MAILPIT_URL') . '/api/v1/' . $path);

        return json_decode((string) $raw, true) ?? [];
    }
}
