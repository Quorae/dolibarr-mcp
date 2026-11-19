<?php

declare(strict_types=1);

namespace DoliMcp\Tools;

use DoliMcp\Dolibarr\Context;
use DoliMcp\Dolibarr\RestError;
use DoliMcp\Mcp\Tool;
use DoliMcp\Mcp\ToolRegistry;
use DoliMcp\Mcp\ToolResult;

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
                    if ($e->status === 404) { // Dolibarr answers 404 to an empty list
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
            'Create an object and return it. An invoice accepts "lines". A credit note is an invoice with type 2 and fk_facture_source; a deposit invoice has type 3. A service is a product with type 1. Custom fields go in array_options (options_<name>).',
            ['type' => 'object', 'properties' => ['type' => $type, 'fields' => ['type' => 'object']], 'required' => ['type', 'fields'], 'additionalProperties' => false],
            static function (array $a) use ($context, $path): ToolResult {
                $newId = $context->rest->request('POST', $path($a), [], (array) $a['fields']);

                return ToolResult::ok(ObjectTypes::present((string) $a['type'], $context->rest->request('GET', $path($a) . '/' . (int) $newId)));
            },
            true,
        ));

        $registry->add(new Tool(
            'object_update',
            'Update fields of an object and return it. Custom fields go in array_options (options_<name>).',
            ['type' => 'object', 'properties' => ['type' => $type, 'id' => $id, 'fields' => ['type' => 'object']], 'required' => ['type', 'id', 'fields'], 'additionalProperties' => false],
            static function (array $a) use ($context, $path): ToolResult {
                $url = $path($a) . '/' . (int) $a['id'];
                $context->rest->request('PUT', $url, [], (array) $a['fields']);

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
}
