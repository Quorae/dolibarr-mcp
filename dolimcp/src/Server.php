<?php

declare(strict_types=1);

namespace DoliMcp;

use DoliMcp\Dolibarr\Context;
use DoliMcp\Dolibarr\Journal;
use DoliMcp\Mcp\Tool;
use DoliMcp\Mcp\ToolResult;
use DoliMcp\Tools\ApiTools;
use DoliMcp\Tools\ObjectTools;
use DoliMcp\Mcp\Protocol;
use DoliMcp\Mcp\ToolRegistry;
use DoliMcp\Tools\CoreTools;

/** Single place where every tool family registers itself. */
final class Server
{
    public static function registry(Context $context): ToolRegistry
    {
        $registry = new ToolRegistry();
        CoreTools::register($registry, $context);
        ApiTools::register($registry, $context);
        ObjectTools::register($registry, $context);

        return $registry;
    }

    public static function protocol(Context $context): Protocol
    {
        return new Protocol(
            self::registry($context),
            self::version($context),
            static fn (Tool $tool, array $arguments, ToolResult $result) => (new Journal($context->db, $context->user))->record($tool, $arguments, $result),
        );
    }

    // The module descriptor is the single source of the version.
    private static function version(Context $context): string
    {
        require_once __DIR__ . '/../core/modules/modDoliMcp.class.php';

        return (string) (new \modDoliMcp($context->db))->version;
    }
}
