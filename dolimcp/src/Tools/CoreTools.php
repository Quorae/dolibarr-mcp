<?php

declare(strict_types=1);

namespace DoliMcp\Tools;

use DoliMcp\Dolibarr\Context;
use DoliMcp\Mcp\Tool;
use DoliMcp\Mcp\ToolRegistry;
use DoliMcp\Mcp\ToolResult;

final class CoreTools
{
    public static function register(ToolRegistry $registry, Context $context): void
    {
        $registry->add(new Tool(
            'whoami',
            'Identify the Dolibarr user behind the API key: login, admin flag, Dolibarr version, entity and enabled modules.',
            ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            static function (array $arguments) use ($context): ToolResult {
                global $conf;

                $modules = array_values(array_map('strval', (array) ($conf->modules ?? [])));
                sort($modules);

                return ToolResult::ok([
                    'login' => (string) $context->user->login,
                    'admin' => (bool) $context->user->admin,
                    'dolibarr_version' => DOL_VERSION,
                    'entity' => (int) $conf->entity,
                    'enabled_modules' => $modules,
                ]);
            },
        ));
    }
}
