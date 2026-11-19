<?php

declare(strict_types=1);

namespace DoliMcp\Tools;

use DoliMcp\Dolibarr\Context;
use DoliMcp\Mcp\Tool;
use DoliMcp\Mcp\ToolError;
use DoliMcp\Mcp\ToolRegistry;
use DoliMcp\Mcp\ToolResult;

/** Invoices logged time the way the "Generate invoice" mass action of projet/tasks/time.php does. */
final class TimeInvoice
{
    public static function register(ToolRegistry $registry, Context $context): void
    {
        $registry->add(new Tool(
            'time_invoice',
            'Invoice the logged time of a project: a draft invoice for the project third party (or lines added to the draft invoice_id), one line per task (per_task, default) or per time entry (per_entry). Takes the time entries not yet invoiced, or those of time_entry_ids, up to the date until. With product_id (a service with a duration), quantity = hours / service duration and the price is the sale price of the service for this customer; otherwise quantity is in hours at hourly_price. vat_rate overrides the VAT rate. Each entry is then linked to its invoice line. Returns the invoice and the number of entries linked.',
            [
                'type' => 'object',
                'properties' => [
                    'project_id' => ['type' => 'integer'],
                    'time_entry_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'until' => ['type' => 'string', 'description' => 'YYYY-MM-DD, inclusive.'],
                    'mode' => ['type' => 'string', 'enum' => ['per_task', 'per_entry']],
                    'product_id' => ['type' => 'integer'],
                    'hourly_price' => ['type' => 'number'],
                    'vat_rate' => ['type' => 'number'],
                    'invoice_id' => ['type' => 'integer'],
                ],
                'required' => ['project_id'],
                'additionalProperties' => false,
            ],
            static fn (array $a): ToolResult => ToolResult::ok(self::invoice($context, $a)),
            true,
        ));
    }

    private static function invoice(Context $context, array $a): array
    {
        global $mysoc;
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
        $user = $context->user;
        $db = $context->db;
        TimeTools::requireRight($context, $user->hasRight('facture', 'creer'), 'create invoices');
        TimeTools::requireRight($context, $user->hasRight('projet', 'lire'), 'read projects');

        $projectId = (int) $a['project_id'];
        $project = new \Project($db);
        $visible = TimeTools::visibleProjects($context);
        if ($project->fetch($projectId) <= 0 || ($visible !== null && !in_array($projectId, $visible, true))) {
            throw new ToolError("Project $projectId not found.");
        }
        if (!($project->socid > 0)) {
            throw new ToolError("Project {$project->ref} has no third party to invoice; set one on the project first.");
        }
        $project->fetch_thirdparty();
        $thirdparty = $project->thirdparty;

        // Price: a service, or an hourly price.
        $productId = (int) ($a['product_id'] ?? 0);
        $product = new \Product($db);
        $hoursPerUnit = 1.0;
        if ($productId > 0) {
            if ($product->fetch($productId) <= 0) {
                throw new ToolError("Product $productId not found.");
            }
            $hoursPerUnit = (float) $product->getProductDurationHours();
            if ($hoursPerUnit <= 0) {
                throw new ToolError("The service {$product->ref} has no duration, so the quantity cannot be computed; set its duration (for example 1 day) or use hourly_price instead of product_id.");
            }
            $price = $product->getSellPrice($mysoc, $thirdparty, 0);
            $unitPrice = (float) ($price['pu_ht'] ?: 0);
            $vat = (float) ($price['tva_tx'] ?? 0);
        } elseif (isset($a['hourly_price'])) {
            $unitPrice = (float) $a['hourly_price'];
            $vat = (float) get_default_tva($mysoc, $thirdparty);
        } else {
            throw new ToolError('Give product_id (a service with a duration) or hourly_price to price the time.');
        }
        if (isset($a['vat_rate'])) {
            $vat = (float) $a['vat_rate'];
        }
        $localtax1 = (float) get_localtax($vat, 1, $thirdparty);
        $localtax2 = (float) get_localtax($vat, 2, $thirdparty);

        $entries = self::entries($context, $projectId, $a);
        if ($entries === []) {
            throw new ToolError("Nothing to invoice: project {$project->ref} has no logged time that is not yet invoiced" . (isset($a['time_entry_ids']) || isset($a['until']) ? ' for this selection' : '') . '.');
        }

        // One group per line: a task, or a single entry.
        $perEntry = ($a['mode'] ?? 'per_task') === 'per_entry';
        $groups = [];
        foreach ($entries as $entry) {
            $groups[$perEntry ? 'e' . $entry['rowid'] : 't' . $entry['fk_element']][] = $entry;
        }

        $db->begin();
        try {
            $invoice = new \Facture($db);
            if (!empty($a['invoice_id'])) {
                if ($invoice->fetch((int) $a['invoice_id']) <= 0 || (int) $invoice->socid !== (int) $project->socid) {
                    throw new ToolError('Invoice ' . $a['invoice_id'] . ' not found for the third party of this project.');
                }
                if ((int) $invoice->status !== \Facture::STATUS_DRAFT) {
                    throw new ToolError('Invoice ' . $a['invoice_id'] . ' is not a draft; lines can only be added to a draft invoice.');
                }
            } else {
                $invoice->socid = $thirdparty->id;
                $invoice->date = dol_now();
                $invoice->fk_project = $project->id;
                $invoice->cond_reglement_id = $thirdparty->cond_reglement_id;
                $invoice->mode_reglement_id = $thirdparty->mode_reglement_id;
                $invoice->fk_account = $thirdparty->fk_account;
                if ($invoice->create($user) <= 0) {
                    throw new ToolError(DocumentTools::failure($invoice, 'Dolibarr could not create the invoice.'));
                }
            }

            foreach ($groups as $group) {
                $seconds = array_sum(array_column($group, 'element_duration'));
                $cost = array_sum(array_map(static fn (array $e): float => (float) $e['element_duration'] * (float) $e['thm'], $group)) / $seconds;
                if ($perEntry) {
                    $description = trim($group[0]['task_ref'] . ' - ' . $group[0]['task_label'] . ' - ' . $group[0]['login'] . "\n" . $group[0]['note']);
                    $quantity = round($seconds / 3600 / $hoursPerUnit, 2);
                } else {
                    $description = $group[0]['task_ref'] . ' - ' . $group[0]['task_label'];
                    $quantity = (float) price2num($seconds / 3600 / $hoursPerUnit, 'MS');
                }
                $lineId = $invoice->addline($description, $unitPrice, $quantity, $vat, $localtax1, $localtax2, $productId > 0 ? $productId : 0, 0, '', '', 0, 0, 0, 'HT', 0, \Product::TYPE_SERVICE, -1, 0, '', 0, 0, null, (float) price2num($cost * $hoursPerUnit, 'MU'));
                if ($lineId <= 0) {
                    throw new ToolError(DocumentTools::failure($invoice, 'Dolibarr could not add a line to the invoice.'));
                }
                // Exactly the link projet/tasks/time.php writes, limited to the entries of this line.
                $ids = implode(',', array_map('intval', array_column($group, 'rowid')));
                // The guard keeps two concurrent calls from invoicing the same entries twice.
                $linked = $db->query('UPDATE ' . MAIN_DB_PREFIX . 'element_time SET invoice_line_id = ' . (int) $lineId . ', invoice_id = ' . (int) $invoice->id . " WHERE rowid IN ($ids) AND (invoice_id IS NULL OR invoice_id = 0)");
                if (!$linked) {
                    throw new ToolError('Could not link the time entries to the invoice: ' . $db->lasterror());
                }
                if ($db->affected_rows($linked) !== count($group)) {
                    throw new ToolError('Some of these time entries were invoiced in the meantime; nothing was invoiced, try again.');
                }
            }
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        if ($db->commit() <= 0) {
            throw new ToolError('Dolibarr could not save the invoice: ' . $db->lasterror());
        }

        return [
            'invoice' => ObjectTypes::present('invoice', $context->rest->request('GET', 'invoices/' . $invoice->id)),
            'linked_entries' => count($entries),
        ];
    }

    /** @return list<array<string, mixed>> the entries to invoice, oldest first */
    private static function entries(Context $context, int $projectId, array $a): array
    {
        $db = $context->db;
        $where = ["pt.fk_projet = $projectId", "t.elementtype = 'task'", 'pt.entity IN (' . getEntity('project') . ')', '(t.invoice_id IS NULL OR t.invoice_id = 0)'];
        if (isset($a['time_entry_ids'])) {
            $where[] = 't.rowid IN (' . implode(',', array_map('intval', (array) $a['time_entry_ids']) ?: [0]) . ')';
        }
        if (isset($a['until'])) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $a['until']) !== 1) {
                throw new ToolError('The date "' . $a['until'] . '" is not valid; use YYYY-MM-DD.');
            }
            $where[] = "DATE(t.element_date) <= '" . $db->escape((string) $a['until']) . "'";
        }
        $resql = $db->query('SELECT t.rowid, t.fk_element, t.element_duration, t.thm, t.note, pt.ref AS task_ref, pt.label AS task_label, u.login'
            . ' FROM ' . MAIN_DB_PREFIX . 'element_time AS t'
            . ' JOIN ' . MAIN_DB_PREFIX . 'projet_task AS pt ON pt.rowid = t.fk_element'
            . ' LEFT JOIN ' . MAIN_DB_PREFIX . 'user AS u ON u.rowid = t.fk_user'
            . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY t.element_date, t.rowid');
        if (!$resql) {
            throw new ToolError('Could not read the time entries: ' . $db->lasterror());
        }
        $rows = [];
        while ($row = $db->fetch_array($resql)) {
            if ((int) $row['element_duration'] > 0) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
