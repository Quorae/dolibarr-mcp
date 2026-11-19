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

    private function draftProposal(int $thirdpartyId, array $more = []): int
    {
        $r = $this->callTool('object_create', ['type' => 'proposal', 'fields' => $more + [
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
            $this->assertGreaterThan(10, strlen(trim($r['text'])));
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
        $ref = (string) $this->rest('GET', "proposals/$id")['body']['ref'];
        $this->assertStringContainsString($ref, $message['Subject']);
        $this->assertGreaterThan(3, strlen(trim($message['Text'])));
        $this->assertStringContainsString($ref, $message['Text']);
        $this->assertCount(1, $message['Attachments']);
        $this->assertStringEndsWith('.pdf', $message['Attachments'][0]['FileName']);

        $events = $this->rest('GET', 'agendaevents?sqlfilters=' . urlencode("(t.fk_soc:=:$tp)"));
        $this->assertSame(200, $events['status'], json_encode($events['body']));
        $this->assertNotEmpty($events['body']);
        $this->assertContains('AC_PROPAL_SENTBYMAIL', array_column($events['body'], 'code'));
    }


    public function testInvoiceFromProposalKeepsProjectTermsAndLines(): void
    {
        $tp = $this->thirdparty();
        $project = $this->rest('POST', 'projects', ['ref' => 'DOC' . bin2hex(random_bytes(3)), 'title' => 'Docs project', 'socid' => $tp]);
        $this->assertSame(200, $project['status'], json_encode($project['body']));
        $proposalId = $this->draftProposal($tp, [
            'socid' => $tp, 'date' => time(), 'duree_validite' => 30,
            'fk_project' => (int) $project['body'], 'cond_reglement_id' => 2, 'mode_reglement_id' => 2, 'ref_client' => 'PO-4242',
            'lines' => [['desc' => 'Discounted', 'qty' => 2, 'subprice' => 50, 'tva_tx' => 5.5, 'remise_percent' => 10]],
        ]);
        $this->assertFalse($this->action('proposal', $proposalId, 'validate')['isError']);
        $created = $this->action('proposal', $proposalId, 'create_invoice');
        $this->assertFalse($created['isError'], $created['text']);
        $invoice = $this->rest('GET', 'invoices/' . (int) $created['data']['id'])['body'];
        $this->assertSame((int) $project['body'], (int) $invoice['fk_project']);
        $this->assertSame(2, (int) $invoice['cond_reglement_id']);
        $this->assertSame(2, (int) $invoice['mode_reglement_id']);
        $this->assertSame('PO-4242', $invoice['ref_client']);
        $this->assertCount(1, $invoice['lines']);
        $this->assertEquals(5.5, $invoice['lines'][0]['tva_tx']);
        $this->assertEquals(10, $invoice['lines'][0]['remise_percent']);
        $this->assertEquals(90, $invoice['total_ht']);
        $this->rest('DELETE', 'invoices/' . (int) $created['data']['id']);
    }

    public function testSendHonoursToCcAndSubject(): void
    {
        $stamp = bin2hex(random_bytes(4));
        $tp = $this->thirdparty("default-$stamp@example.com");
        $id = $this->draftProposal($tp);
        $this->assertFalse($this->action('proposal', $id, 'validate')['isError']);

        $sent = $this->callTool('document_send', ['type' => 'proposal', 'id' => $id, 'to' => "to-$stamp@example.com", 'cc' => "cc-$stamp@example.com", 'subject' => "Custom $stamp", 'message' => 'Hello there', 'attach_pdf' => false]);
        $this->assertFalse($sent['isError'], $sent['text']);

        $this->assertSame(0, $this->mailpit('search?query=' . urlencode("to:default-$stamp@example.com"))['messages_count']);
        $search = $this->mailpit('search?query=' . urlencode("to:to-$stamp@example.com"));
        $this->assertSame(1, $search['messages_count']);
        $message = $this->mailpit('message/' . $search['messages'][0]['ID']);
        $this->assertSame("Custom $stamp", $message['Subject']);
        $this->assertSame("cc-$stamp@example.com", $message['Cc'][0]['Address']);
        $this->assertSame([], $message['Attachments']);
        $this->assertStringContainsString('Hello there', $message['Text']);
    }

    public function testSendRejectsHeaderInjectionAndInvalidAddresses(): void
    {
        $stamp = bin2hex(random_bytes(4));
        $tp = $this->thirdparty("rejected-$stamp@example.com");
        $id = $this->draftProposal($tp);
        $this->assertFalse($this->action('proposal', $id, 'validate')['isError']);

        $subject = $this->callTool('document_send', ['type' => 'proposal', 'id' => $id, 'subject' => "Hi\r\nBcc: evil-$stamp@example.com"]);
        $this->assertTrue($subject['isError']);
        $this->assertStringContainsString('subject', $subject['text']);
        $address = $this->callTool('document_send', ['type' => 'proposal', 'id' => $id, 'to' => "ok-$stamp@example.com, not-an-address"]);
        $this->assertTrue($address['isError']);
        $this->assertStringContainsString('not-an-address', $address['text']);
        $this->assertSame(0, $this->mailpit('search?query=' . urlencode("$stamp"))['messages_count']);
    }

    private function mailpit(string $path): array
    {
        $raw = file_get_contents((string) getenv('MAILPIT_URL') . '/api/v1/' . $path);

        return json_decode((string) $raw, true) ?? [];
    }

    public function testLargeFileAttachmentIsStillJournaled(): void
    {
        $tp = $this->thirdparty();
        $id = $this->draftInvoice($tp);
        try {
            $r = $this->callTool('file_attach', ['type' => 'invoice', 'id' => $id, 'filename' => 'big.txt', 'content_base64' => base64_encode(str_repeat('0123456789abcdef', 6400))]);
            $this->assertFalse($r['isError'], $r['text']);
            $row = self::db()->query("SELECT params FROM llx_dolimcp_journal WHERE tool = 'file_attach' ORDER BY rowid DESC LIMIT 1")->fetch_assoc();
            $this->assertNotNull($row, 'the journal row must exist');
            $this->assertStringContainsString('big.txt', $row['params']);
            $this->assertStringContainsString('bytes omitted', $row['params']);
        } finally {
            $this->rest('DELETE', "invoices/$id");
            $this->rest('DELETE', "thirdparties/$tp");
        }
    }
}
