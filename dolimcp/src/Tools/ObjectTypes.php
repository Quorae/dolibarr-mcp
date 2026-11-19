<?php

declare(strict_types=1);

namespace DoliMcp\Tools;

use DoliMcp\Mcp\ToolError;
use DoliMcp\Support\Compactor;

/** The Dolibarr object types handled by the generic tools: REST path and status labels. */
final class ObjectTypes
{
    public const PATHS = [
        'thirdparty' => 'thirdparties',
        'contact' => 'contacts',
        'product' => 'products',
        'proposal' => 'proposals',
        'invoice' => 'invoices',
        'project' => 'projects',
        'task' => 'tasks',
        'event' => 'agendaevents',
        'bank_account' => 'bankaccounts',
        'user' => 'users',
    ];

    private const STATUS_LABELS = [
        'invoice' => [0 => 'draft', 1 => 'unpaid', 2 => 'paid', 3 => 'abandoned'],
        'proposal' => [0 => 'draft', 1 => 'validated', 2 => 'signed', 3 => 'not_signed', 4 => 'billed'],
        'project' => [0 => 'draft', 1 => 'open', 2 => 'closed'],
    ];

    // Dolibarr objects expose their status under one of these names, depending on the class.
    private const STATUS_FIELDS = ['status', 'statut', 'fk_statut'];

    /** The id a REST POST answers with: a positive integer, possibly as a numeric string. */
    public static function createdId(string $type, mixed $answer): int
    {
        if ((is_int($answer) || is_string($answer)) && ctype_digit((string) $answer) && (int) $answer > 0) {
            return (int) $answer;
        }

        throw new ToolError("Dolibarr did not return the new $type id: " . mb_substr(json_encode($answer) ?: '', 0, 200));
    }

    public static function statusLabel(string $type, int $status): ?string
    {
        return self::STATUS_LABELS[$type][$status] ?? null;
    }

    /** Compacts an object (or a list of objects) and adds status_label where the type has labels. */
    public static function present(string $type, mixed $object): mixed
    {
        $object = Compactor::compact($object);
        if (!is_array($object) || !isset(self::STATUS_LABELS[$type])) {
            return $object;
        }
        if (array_is_list($object)) {
            return array_map(static fn (mixed $item): mixed => self::present($type, $item), $object);
        }
        foreach (self::STATUS_FIELDS as $field) {
            if (isset($object[$field]) && is_numeric($object[$field])) {
                $label = self::statusLabel($type, (int) $object[$field]);
                if ($label !== null) {
                    $object['status_label'] = $label;
                }
                break;
            }
        }

        return $object;
    }
}
