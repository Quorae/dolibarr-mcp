<?php

declare(strict_types=1);

namespace DoliMcp\Tools;

use DoliMcp\Dolibarr\Context;
use DoliMcp\Mcp\Tool;
use DoliMcp\Mcp\ToolError;
use DoliMcp\Mcp\ToolRegistry;
use DoliMcp\Mcp\ToolResult;

/** Proposal and invoice lifecycle actions, and customer payments. File and email tools: DocumentFiles, DocumentMail. */
final class DocumentTools
{
    /** The two document types: REST path, Dolibarr "modulepart" of their files, and rights module. */
    public const TYPES = [
        'proposal' => ['path' => 'proposals', 'modulepart' => 'propal', 'right' => 'propal'],
        'invoice' => ['path' => 'invoices', 'modulepart' => 'invoice', 'right' => 'facture'],
    ];

    private const PROPOSAL_ACTIONS = ['validate', 'set_draft', 'close_signed', 'close_unsigned', 'set_invoiced', 'create_invoice'];
    private const INVOICE_ACTIONS = ['validate', 'set_draft', 'set_paid', 'set_unpaid', 'cancel'];

    // Line fields copied from a proposal to the invoice created from it.
    private const LINE_FIELDS = [
        'fk_product', 'product_type', 'desc', 'label', 'qty', 'subprice', 'tva_tx', 'localtax1_tx', 'localtax2_tx',
        'remise_percent', 'multicurrency_subprice', 'fk_unit', 'date_start', 'date_end', 'special_code', 'rang', 'info_bits',
    ];

    public static function register(ToolRegistry $registry, Context $context): void
    {
        $registry->add(new Tool(
            'document_action',
            'Run a lifecycle action on a proposal or an invoice and return it. Proposal: validate, set_draft, close_signed, close_unsigned (note = reason), set_invoiced, create_invoice (draft invoice with the same third party, project, customer reference, bank account, currency and rate, lines (VAT, discount), payment terms and mode, linked to the proposal; the converted amounts are recomputed by Dolibarr, and extra fields and the other data of the proposal are not copied). Invoice: validate, set_draft, set_paid (close_code and note when paying less than the total), set_unpaid, cancel (close_code defaults to "abandon"; only for a validated invoice with no payment).',
            [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['proposal', 'invoice']],
                    'id' => ['type' => 'integer'],
                    'action' => ['type' => 'string', 'enum' => array_values(array_unique([...self::PROPOSAL_ACTIONS, ...self::INVOICE_ACTIONS]))],
                    'close_code' => ['type' => 'string'],
                    'note' => ['type' => 'string'],
                ],
                'required' => ['type', 'id', 'action'],
                'additionalProperties' => false,
            ],
            static fn (array $a): ToolResult => ToolResult::ok(self::act($context, $a)),
            true,
        ));

        $registry->add(new Tool(
            'payment_create',
            'Record one customer payment spread over one or several invoices of the same customer. payment_mode is a dictionary code (VIR, CHQ, CB, LIQ, PRE...); date is YYYY-MM-DD. Invoices fully paid are closed unless close_paid_invoices is false. Returns the payment id and the invoices as they are after the payment.',
            [
                'type' => 'object',
                'properties' => [
                    'payments' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => ['invoice_id' => ['type' => 'integer'], 'amount' => ['type' => 'number']],
                            'required' => ['invoice_id', 'amount'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'date' => ['type' => 'string'],
                    'payment_mode' => ['type' => 'string'],
                    'bank_account_id' => ['type' => 'integer'],
                    'reference' => ['type' => 'string'],
                    'comment' => ['type' => 'string'],
                    'close_paid_invoices' => ['type' => 'boolean'],
                ],
                'required' => ['payments', 'date', 'payment_mode', 'bank_account_id'],
                'additionalProperties' => false,
            ],
            static fn (array $a): ToolResult => ToolResult::ok(self::pay($context, $a)),
            true,
        ));
    }

    private static function act(Context $context, array $a): mixed
    {
        $type = (string) $a['type'];
        $id = (int) $a['id'];
        $action = (string) $a['action'];
        $allowed = $type === 'proposal' ? self::PROPOSAL_ACTIONS : self::INVOICE_ACTIONS;
        if (!in_array($action, $allowed, true)) {
            throw new ToolError("The action $action does not exist for a $type; use one of: " . implode(', ', $allowed));
        }
        $url = self::TYPES[$type]['path'] . '/' . $id;
        $note = (string) ($a['note'] ?? '');
        $code = (string) ($a['close_code'] ?? '');

        if ($action === 'create_invoice') {
            return ObjectTypes::present('invoice', self::invoiceFromProposal($context, $id));
        }
        if ($action === 'cancel') {
            self::cancelInvoice($context, $id, $code !== '' ? $code : 'abandon', $note);

            return ObjectTypes::present($type, $context->rest->request('GET', $url));
        }

        [$route, $body] = match ([$type, $action]) {
            ['proposal', 'validate'], ['invoice', 'validate'] => ['validate', $type === 'invoice' ? ['idwarehouse' => 0] : []],
            ['proposal', 'set_draft'], ['invoice', 'set_draft'] => ['settodraft', []],
            ['proposal', 'close_signed'] => ['close', ['status' => 2, 'note_private' => $note]],
            ['proposal', 'close_unsigned'] => ['close', ['status' => 3, 'note_private' => $note]],
            ['proposal', 'set_invoiced'] => ['setinvoiced', []],
            ['invoice', 'set_paid'] => ['settopaid', ['close_code' => $code, 'close_note' => $note]],
            ['invoice', 'set_unpaid'] => ['settounpaid', []],
        };
        $context->rest->request('POST', "$url/$route", [], $body === [] ? null : $body);

        return ObjectTypes::present($type, $context->rest->request('GET', $url));
    }

    /** Draft invoice copied from the proposal, then linked to it. */
    private static function invoiceFromProposal(Context $context, int $proposalId): array
    {
        $proposal = (array) $context->rest->request('GET', "proposals/$proposalId");
        $fields = ['socid' => $proposal['socid'], 'date' => time(), 'type' => 0];
        foreach (['fk_project', 'cond_reglement_id', 'mode_reglement_id', 'note_public', 'note_private', 'ref_client', 'fk_account', 'multicurrency_code', 'multicurrency_tx'] as $key) {
            if (!empty($proposal[$key])) {
                $fields[$key] = $proposal[$key];
            }
        }
        $fields['lines'] = array_map(
            static fn (array $line): array => array_filter(
                array_intersect_key($line, array_flip(self::LINE_FIELDS)),
                static fn (mixed $value): bool => $value !== null && $value !== '',
            ),
            array_values((array) ($proposal['lines'] ?? [])),
        );

        $invoiceId = ObjectTypes::createdId('invoice', $context->rest->request('POST', 'invoices', [], $fields));
        try {
            $context->rest->request('POST', 'objectlinks', [], [
                'fk_source' => $proposalId, 'sourcetype' => 'propal', 'fk_target' => $invoiceId, 'targettype' => 'facture',
            ]);
        } catch (ToolError $e) {
            $ref = (string) (((array) $context->rest->request('GET', "invoices/$invoiceId"))['ref'] ?? '');
            throw new ToolError("The draft invoice $invoiceId ($ref) was created from proposal $proposalId, but linking it to the proposal failed: " . $e->getMessage() . '. The invoice is valid; only the link is missing.');
        }

        return (array) $context->rest->request('GET', "invoices/$invoiceId");
    }

    /** No REST route: same rights and conditions as the "Classify abandoned" action of the invoice card. */
    private static function cancelInvoice(Context $context, int $id, string $code, string $note): void
    {
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        $user = $context->user;
        if (!$user->hasRight('facture', 'creer')) {
            throw new ToolError('Your Dolibarr user may not edit invoices.');
        }
        $invoice = new \Facture($context->db);
        if ($invoice->fetch($id) <= 0 || !checkUserAccessToObject($user, ['facture'], $id)) {
            throw new ToolError("Invoice $id not found.");
        }
        if ((int) $invoice->status !== \Facture::STATUS_VALIDATED || !empty($invoice->paye)) {
            throw new ToolError('Only a validated, unpaid invoice can be cancelled.');
        }
        if ($invoice->getSommePaiement() > 0 || $invoice->getSumCreditNotesUsed() > 0) {
            throw new ToolError('This invoice already has a payment or a credit note; classify it as paid instead (set_paid).');
        }
        if (getDolGlobalString('INVOICE_CAN_NEVER_BE_CANCELED')) {
            throw new ToolError('Dolibarr is set up so that invoices can never be cancelled.');
        }
        if ($invoice->setCanceled($user, $code, $note) < 0) {
            throw new ToolError(self::failure($invoice, 'Dolibarr could not cancel the invoice.'));
        }
    }

    public static function failure(object $object, string $fallback): string
    {
        $messages = array_filter([(string) ($object->error ?? ''), ...array_map('strval', (array) ($object->errors ?? []))]);

        return $messages === [] ? $fallback : implode(' ', array_unique($messages));
    }

    private static function pay(Context $context, array $a): array
    {
        $timestamp = strtotime(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $a['date']) === 1 ? $a['date'] . ' 12:00:00' : (string) $a['date']);
        if ($timestamp === false) {
            throw new ToolError('The date "' . $a['date'] . '" is not valid; use YYYY-MM-DD.');
        }
        $modes = $context->rest->request('GET', 'setup/dictionary/payment_types', ['sqlfilters' => "(t.code:=:'" . str_replace("'", '', (string) $a['payment_mode']) . "')"]);
        $modeId = $modes[0]['id'] ?? null;
        if ($modeId === null) {
            throw new ToolError('No payment mode has the code "' . $a['payment_mode'] . '"; use a code of the Dolibarr dictionary (VIR, CHQ, CB, LIQ, PRE...).');
        }

        $amounts = [];
        foreach ((array) $a['payments'] as $payment) {
            $invoiceId = (string) (int) $payment['invoice_id'];
            $amounts[$invoiceId] = ['amount' => (string) (($amounts[$invoiceId]['amount'] ?? 0) + $payment['amount']), 'multicurrency_amount' => ''];
        }
        if ($amounts === []) {
            throw new ToolError('payments must contain at least one invoice.');
        }

        $paymentId = $context->rest->request('POST', 'invoices/paymentsdistributed', [], [
            'arrayofamounts' => $amounts,
            'datepaye' => $timestamp,
            'paymentid' => (int) $modeId,
            'closepaidinvoices' => ($a['close_paid_invoices'] ?? true) ? 'yes' : 'no',
            'accountid' => (int) $a['bank_account_id'],
            'num_payment' => (string) ($a['reference'] ?? ''),
            'comment' => (string) ($a['comment'] ?? ''),
        ]);

        return [
            'payment_id' => ObjectTypes::createdId('payment', $paymentId),
            'invoices' => array_map(
                static fn (string $id): mixed => ObjectTypes::present('invoice', $context->rest->request('GET', 'invoices/' . $id)),
                array_map('strval', array_keys($amounts)),
            ),
        ];
    }
}
