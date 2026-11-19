<?php

declare(strict_types=1);

namespace DoliMcp\Tools;

use DoliMcp\Dolibarr\Context;
use DoliMcp\Dolibarr\RestError;
use DoliMcp\Mcp\Tool;
use DoliMcp\Mcp\ToolError;
use DoliMcp\Mcp\ToolRegistry;

/** Custom fields of Dolibarr objects, through the REST API setup/extrafields (the one that knows how to alter the tables). */
final class ExtrafieldTools
{
    // The REST API names these objects like their tables; the tools take the usual names.
    private const ELEMENTS = ['thirdparty' => 'societe', 'contact' => 'socpeople'];

    public static function register(ToolRegistry $registry, Context $context): void
    {
        $element = ['type' => 'string', 'description' => 'Object type as in Dolibarr tables: thirdparty, contact, product, propal, facture, projet, projet_task, commande, user...'];
        $registry->add(new Tool('extrafield_list', 'List the custom fields, of one object type or of all.',
            SetupTools::schema(['element' => $element]),
            SetupTools::admin($context, static fn (array $a): array => self::list($context, (string) ($a['element'] ?? '')))));
        $registry->add(new Tool('extrafield_set',
            'Create a custom field, or change it when it already exists (fields not given keep their value). name: lowercase letters, digits, underscore. type: varchar, text, html, int, double, price, boolean, date, datetime, select, checkbox, radio, url, email, phone... size: length (e.g. 255), or "24,8" for numbers. options: for select, checkbox and radio, an object {value: label}. visible: Dolibarr visibility (1 = visible, 0 = never, 3 = view only, -1 = hidden from lists...). The values are then read and written through the objects API under options_<name>.',
            SetupTools::schema([
                'element' => $element, 'name' => ['type' => 'string'], 'label' => ['type' => 'string'], 'type' => ['type' => 'string'],
                'size' => ['type' => 'string'], 'options' => ['type' => 'object'], 'position' => ['type' => 'integer'],
                'required' => ['type' => 'boolean'], 'visible' => ['type' => ['string', 'integer']],
            ], ['element', 'name', 'label', 'type']),
            SetupTools::admin($context, static fn (array $a): array => self::set($context, $a)), true));
        $registry->add(new Tool('extrafield_delete', 'Delete a custom field and the values stored in it. This cannot be undone.',
            SetupTools::schema(['element' => $element, 'name' => ['type' => 'string']], ['element', 'name']),
            SetupTools::admin($context, static fn (array $a): array => self::delete($context, (string) $a['element'], (string) $a['name'])), true));
    }

    /** @return list<array<string, mixed>> */
    private static function raw(Context $context, string $element): array
    {
        $answer = self::call(static fn (): mixed => $context->rest->request('GET', 'setup/extrafields', $element === '' ? [] : ['elementtype' => self::element($element)]));
        $fields = [];
        foreach ((array) $answer as $byName) {
            foreach ((array) $byName as $name => $field) {
                $fields[] = ['name' => $name] + $field;
            }
        }

        return $fields;
    }

    private static function list(Context $context, string $element): array
    {
        return array_map(static fn (array $f): array => [
            'id' => (int) $f['id'], 'element' => $f['elementtype'], 'name' => $f['name'], 'label' => $f['label'], 'type' => $f['type'],
            'size' => (string) $f['size'], 'options' => is_array($f['param']) ? ($f['param']['options'] ?? []) : [],
            'position' => (int) $f['pos'], 'required' => (bool) $f['required'], 'visible' => (string) $f['list'],
        ], self::raw($context, $element));
    }

    private static function set(Context $context, array $a): array
    {
        $element = self::element((string) $a['element']);
        $name = (string) $a['name'];
        self::checkName($name);
        $existing = array_values(array_filter(self::raw($context, $element), static fn (array $f): bool => $f['name'] === $name))[0] ?? null;

        // The API replaces every attribute on update: start from the current ones so that only what is given changes.
        $body = [
            'default' => '', 'computed' => '', 'unique' => 0, 'alwayseditable' => 0, 'perms' => '', 'printable' => 0,
            'totalizable' => 0, 'showintooltip' => 0, 'langfile' => '', 'help' => '', 'size' => '', 'pos' => 0, 'required' => 0, 'list' => '1', 'param' => '',
        ];
        if ($existing !== null) {
            foreach (['default', 'computed', 'unique', 'alwayseditable', 'perms', 'printable', 'totalizable', 'showintooltip', 'help', 'size', 'pos', 'required', 'list', 'param'] as $key) {
                $body[$key] = $existing[$key] ?? $body[$key];
            }
            $body['langfile'] = $existing['langs'] ?? '';
        }
        $body['label'] = (string) $a['label'];
        $body['type'] = (string) $a['type'];
        foreach (['size' => 'size', 'position' => 'pos', 'visible' => 'list'] as $arg => $key) {
            if (isset($a[$arg])) {
                $body[$key] = (string) $a[$arg];
            }
        }
        if (isset($a['required'])) {
            $body['required'] = $a['required'] ? 1 : 0;
        }
        if (isset($a['options'])) {
            $body['param'] = ['options' => (array) $a['options']];
        }

        self::call(static fn (): mixed => $context->rest->request($existing === null ? 'POST' : 'PUT', "setup/extrafields/$element/$name", [], $body));

        return array_values(array_filter(self::list($context, $element), static fn (array $f): bool => $f['name'] === $name))[0]
            ?? throw new ToolError('The custom field was not found after saving.');
    }

    private static function delete(Context $context, string $element, string $name): array
    {
        $element = self::element($element);
        self::checkName($name);
        self::call(static fn (): mixed => $context->rest->request('DELETE', "setup/extrafields/$element/$name"));

        return ['deleted' => $name, 'element' => $element];
    }

    private static function element(string $element): string
    {
        return self::ELEMENTS[$element] ?? $element;
    }

    private static function checkName(string $name): void
    {
        if (preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
            throw new ToolError('A custom field name is made of lowercase letters, digits and underscores.');
        }
    }

    private static function call(\Closure $request): mixed
    {
        try {
            return $request();
        } catch (RestError $e) {
            throw new ToolError($e->getMessage());
        }
    }
}
