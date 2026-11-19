<?php

declare(strict_types=1);

namespace DoliMcp\Tools;

use DoliMcp\Dolibarr\Context;
use DoliMcp\Dolibarr\RestError;
use DoliMcp\Mcp\Tool;
use DoliMcp\Mcp\ToolError;
use DoliMcp\Mcp\ToolRegistry;
use DoliMcp\Mcp\ToolResult;
use DoliMcp\Support\Compactor;

/** Search, read, create, update and delete the main Dolibarr objects through the REST API. */
final class ObjectTools
{
    public static function register(ToolRegistry $registry, Context $context): void
    {
        $type = ['type' => 'string', 'enum' => array_keys(ObjectTypes::PATHS)];
        $id = ['type' => 'integer'];
        $path = static fn (array $a): string => ObjectTypes::PATHS[(string) $a['type']];

        $registry->add(new Tool(
            'object_search',
            'List objects of a type. sqlfilters uses the Dolibarr syntax, e.g. "(t.nom:like:\'%acme%\')"; filters passes the list-specific parameters of the REST API (e.g. thirdparty_ids, status). Returns [] when nothing matches.',
            [
                'type' => 'object',
                'properties' => [
                    'type' => $type,
                    'sqlfilters' => ['type' => 'string'],
                    'sortfield' => ['type' => 'string'],
                    'sortorder' => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
                    'limit' => ['type' => 'integer'],
                    'page' => ['type' => 'integer'],
                    'filters' => ['type' => 'object'],
                ],
                'required' => ['type'],
                'additionalProperties' => false,
            ],
            static function (array $a) use ($context, $path): ToolResult {
                $query = (array) ($a['filters'] ?? []);
                foreach (['sqlfilters', 'sortfield', 'sortorder'] as $key) {
                    if (isset($a[$key])) {
                        $query[$key] = $a[$key];
                    }
                }
                $query['limit'] = $a['limit'] ?? 50;
                $query['page'] = $a['page'] ?? 0;

                try {
                    $list = $context->rest->request('GET', $path($a), $query);
                } catch (RestError $e) {
                    if ($e->isEmptyResult()) { // Dolibarr answers a JSON 404 to an empty list
                        return ToolResult::ok([]);
                    }
                    throw $e;
                }

                return ToolResult::ok(ObjectTypes::present((string) $a['type'], $list));
            },
        ));

        $registry->add(new Tool(
            'object_get',
            'Read one object by id.',
            ['type' => 'object', 'properties' => ['type' => $type, 'id' => $id], 'required' => ['type', 'id'], 'additionalProperties' => false],
            static fn (array $a): ToolResult => ToolResult::ok(ObjectTypes::present((string) $a['type'], $context->rest->request('GET', $path($a) . '/' . (int) $a['id']))),
        ));

        $registry->add(new Tool(
            'object_create',
            'Create an object and return it. An invoice accepts "lines". A credit note is an invoice with type 2 and fk_facture_source; a deposit invoice has type 3. A service is a product with type 1. Custom fields go in array_options (options_<name>). Dates are YYYY-MM-DD (or YYYY-MM-DD HH:MM), like everywhere in this server.',
            ['type' => 'object', 'properties' => ['type' => $type, 'fields' => ['type' => 'object']], 'required' => ['type', 'fields'], 'additionalProperties' => false],
            static function (array $a) use ($context, $path): ToolResult {
                $newId = $context->rest->request('POST', $path($a), [], self::timestamps((array) $a['fields']));

                return ToolResult::ok(ObjectTypes::present((string) $a['type'], $context->rest->request('GET', $path($a) . '/' . ObjectTypes::createdId((string) $a['type'], $newId))));
            },
            true,
        ));

        $registry->add(new Tool(
            'object_update',
            'Update fields of an object and return it. Custom fields go in array_options (options_<name>). Dates are YYYY-MM-DD (or YYYY-MM-DD HH:MM), like everywhere in this server.',
            ['type' => 'object', 'properties' => ['type' => $type, 'id' => $id, 'fields' => ['type' => 'object']], 'required' => ['type', 'id', 'fields'], 'additionalProperties' => false],
            static function (array $a) use ($context, $path): ToolResult {
                $url = $path($a) . '/' . (int) $a['id'];
                $context->rest->request('PUT', $url, [], self::timestamps((array) $a['fields']));

                return ToolResult::ok(ObjectTypes::present((string) $a['type'], $context->rest->request('GET', $url)));
            },
            true,
        ));

        $registry->add(new Tool(
            'object_delete',
            'Delete an object by id.',
            ['type' => 'object', 'properties' => ['type' => $type, 'id' => $id], 'required' => ['type', 'id'], 'additionalProperties' => false],
            static function (array $a) use ($context, $path): ToolResult {
                $context->rest->request('DELETE', $path($a) . '/' . (int) $a['id']);

                return ToolResult::ok(['deleted' => true, 'type' => $a['type'], 'id' => (int) $a['id']]);
            },
            true,
        ));
    }

    /**
     * Dolibarr's REST API takes timestamps; the tools show and accept YYYY-MM-DD [HH:MM] in the server time zone.
     * Text dates would otherwise reach calculate_date_lim_reglement() and similar code as strings.
     */
    private static function timestamps(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::timestamps($value);
                continue;
            }
            // Custom fields (options_<name>) hold free text too: only a well-formed date is converted there.
            $custom = is_string($key) && str_starts_with($key, 'options_');
            if (!is_string($key) || !is_string($value) || $value === '' || ctype_digit($value) || !Compactor::isDateKey($custom ? substr($key, 8) : $key)) {
                continue;
            }
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $data[$key] = dol_mktime((int) ($m[4] ?? 0), (int) ($m[5] ?? 0), 0, (int) $m[2], (int) $m[3], (int) $m[1]);
            } elseif (!$custom) {
                throw new ToolError("Invalid date \"$value\" for $key: use YYYY-MM-DD (or YYYY-MM-DD HH:MM).");
            }
        }

        return $data;
    }
}
