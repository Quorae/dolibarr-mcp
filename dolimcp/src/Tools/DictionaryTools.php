<?php

declare(strict_types=1);

namespace DoliMcp\Tools;

use DoliMcp\Dolibarr\Context;
use DoliMcp\Mcp\Tool;
use DoliMcp\Mcp\ToolError;
use DoliMcp\Mcp\ToolRegistry;

/**
 * VAT rates, payment terms and payment methods. Dolibarr has no class for dictionaries: like admin/dict.php, the rows
 * are written with SQL, limited to the columns of its $tabfieldinsert for the dictionary (entity and active aside).
 */
final class DictionaryTools
{
    private const DICTIONARIES = [
        'vat' => [
            'table' => 'c_tva', 'id' => 'rowid',
            'fields' => ['fk_pays', 'fk_department_buyer', 'code', 'type_vat', 'taux', 'localtax1_type', 'localtax1', 'localtax2_type', 'localtax2', 'recuperableonly', 'einvoice_vatex', 'accountancy_code_sell', 'accountancy_code_buy', 'use_default', 'note'],
        ],
        'payment_terms' => [
            'table' => 'c_payment_term', 'id' => 'rowid',
            'fields' => ['code', 'libelle', 'libelle_facture', 'deposit_percent', 'nbjour', 'type_cdr', 'decalage', 'sortorder'],
        ],
        'payment_methods' => [
            'table' => 'c_paiement', 'id' => 'id',
            'fields' => ['code', 'libelle', 'type', 'accountancy_code'],
        ],
    ];
    private const INTEGERS = ['fk_pays', 'fk_department_buyer', 'type_vat', 'localtax1_type', 'localtax2_type', 'recuperableonly', 'use_default', 'nbjour', 'type_cdr', 'decalage', 'sortorder', 'type'];
    private const DECIMALS = ['taux', 'localtax1', 'deposit_percent'];

    public static function register(ToolRegistry $registry, Context $context): void
    {
        $names = ['type' => 'string', 'enum' => array_keys(self::DICTIONARIES)];
        $registry->add(new Tool('dictionary_list',
            'List the rows of a Dolibarr dictionary: vat (rates of the company country), payment_terms, payment_methods. Each row has an id for dictionary_set.',
            SetupTools::schema(['dictionary' => $names, 'active_only' => ['type' => 'boolean', 'default' => false]], ['dictionary']),
            SetupTools::admin($context, static fn (array $a): array => self::list($context, (string) $a['dictionary'], (bool) ($a['active_only'] ?? false)))));
        $registry->add(new Tool('dictionary_set',
            'Add a row to a dictionary, or change the row `id`. `fields` takes only the columns Dolibarr\'s dictionary page edits: vat ' . implode(', ', self::DICTIONARIES['vat']['fields']) . ' (fk_pays defaults to the company country); payment_terms ' . implode(', ', self::DICTIONARIES['payment_terms']['fields']) . '; payment_methods ' . implode(', ', self::DICTIONARIES['payment_methods']['fields']) . '. `active` enables or disables the row (new rows are active). Returns the row id.',
            SetupTools::schema(['dictionary' => $names, 'id' => ['type' => 'integer'], 'fields' => ['type' => 'object'], 'active' => ['type' => 'boolean']], ['dictionary', 'fields']),
            SetupTools::admin($context, static fn (array $a): array => self::set($context, (string) $a['dictionary'], isset($a['id']) ? (int) $a['id'] : null, (array) $a['fields'], isset($a['active']) ? (bool) $a['active'] : null)), true));
    }

    private static function dictionary(string $name): array
    {
        return self::DICTIONARIES[$name] ?? throw new ToolError('dictionary must be one of ' . implode(', ', array_keys(self::DICTIONARIES)) . '.');
    }

    private static function table(array $dictionary): string
    {
        return MAIN_DB_PREFIX . $dictionary['table'];
    }

    private static function companyCountryId(): int
    {
        return (int) explode(':', getDolGlobalString('MAIN_INFO_SOCIETE_COUNTRY'))[0];
    }

    /** @return list<array<string, mixed>> */
    private static function list(Context $context, string $name, bool $activeOnly): array
    {
        $dictionary = self::dictionary($name);
        $db = $context->db;
        $where = ['entity = ' . SetupTools::entity()];
        if ($name === 'vat' && self::companyCountryId() > 0) {
            $where[] = 'fk_pays = ' . self::companyCountryId();
        }
        if ($activeOnly) {
            $where[] = 'active = 1';
        }
        $resql = $db->query('SELECT * FROM ' . self::table($dictionary) . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $dictionary['id']);
        if (!$resql) {
            throw new ToolError('Could not read the dictionary: ' . $db->lasterror());
        }
        $rows = [];
        while ($row = $db->fetch_array($resql)) {
            $id = (int) $row[$dictionary['id']];
            unset($row[$dictionary['id']]);
            $row['active'] = (int) $row['active'];
            $rows[] = ['id' => $id] + $row;
        }

        return $rows;
    }

    /** @return array{id: int} */
    private static function set(Context $context, string $name, ?int $id, array $fields, ?bool $active): array
    {
        $dictionary = self::dictionary($name);
        $db = $context->db;
        $unknown = array_diff(array_keys($fields), $dictionary['fields']);
        if ($unknown !== []) {
            throw new ToolError('Unknown field ' . implode(', ', $unknown) . ' for ' . $name . '; allowed: ' . implode(', ', $dictionary['fields']) . '.');
        }
        $values = [];
        foreach ($fields as $field => $value) {
            $values[$field] = self::sql($db, $name, $field, $value);
        }
        if ($active !== null) {
            $values['active'] = $active ? '1' : '0';
        }

        $table = self::table($dictionary);
        $entity = SetupTools::entity();
        if ($id !== null) {
            if ($values === []) {
                throw new ToolError('Give at least one field or active.');
            }
            $set = implode(', ', array_map(static fn (string $c, string $v): string => "$c = $v", array_keys($values), $values));
            $found = $db->query("SELECT 1 FROM $table WHERE {$dictionary['id']} = $id AND entity = $entity");
            if (!$found || $db->num_rows($found) === 0) {
                throw new ToolError("No row $id in the $name dictionary.");
            }
            $done = $db->query("UPDATE $table SET $set WHERE {$dictionary['id']} = $id AND entity = $entity");

            return $done ? ['id' => $id] : throw new ToolError(self::error($db));
        }

        if ($name === 'vat') {
            $values['fk_pays'] ??= (string) self::companyCountryId();
            if ($values['fk_pays'] === '0' || $values['fk_pays'] === 'null') {
                throw new ToolError('Set the company country first, or give fk_pays.');
            }
            $values['code'] ??= "''"; // the VAT code may be empty
        }
        $values += ['active' => '1', 'entity' => (string) $entity];
        if ($name === 'payment_methods') { // c_paiement.id is not auto-incremented: next free id, like dict.php
            $max = $db->query("SELECT MAX(id) AS newid FROM $table");
            $values = ['id' => (string) (((int) ($max ? $db->fetch_object($max)->newid : 0)) + 1)] + $values;
        }
        $done = $db->query("INSERT INTO $table (" . implode(', ', array_keys($values)) . ') VALUES (' . implode(', ', $values) . ')');
        if (!$done) {
            throw new ToolError(self::error($db));
        }

        return ['id' => (int) ($values['id'] ?? $db->last_insert_id($table))];
    }

    private static function error(\DoliDB $db): string
    {
        return $db->errno() === 'DB_ERROR_RECORD_ALREADY_EXISTS' ? 'This row already exists in the dictionary.' : 'Could not write the dictionary: ' . $db->lasterror();
    }

    /** The SQL literal of a value, escaped or cast by column type; an empty value is null (but an empty VAT code is kept), like dict.php. */
    private static function sql(\DoliDB $db, string $dictionary, string $field, mixed $value): string
    {
        if (is_array($value)) {
            throw new ToolError("Field $field takes a single value.");
        }
        $value = is_bool($value) ? ($value ? '1' : '0') : trim((string) $value);
        if ($value === '') {
            return $dictionary === 'vat' && $field === 'code' ? "''" : 'null';
        }
        if (in_array($field, self::INTEGERS, true)) {
            if (!is_numeric($value)) {
                throw new ToolError("Field $field must be a number.");
            }

            return (string) (int) $value;
        }
        if (in_array($field, self::DECIMALS, true)) {
            if (!is_numeric($value)) {
                throw new ToolError("Field $field must be a number.");
            }

            return (string) (float) price2num($value, 8);
        }

        return "'" . $db->escape($value) . "'";
    }
}
