<?php

declare(strict_types=1);

namespace DoliMcp\Dolibarr;

use DoliMcp\Mcp\ToolError;

/** A Dolibarr REST API answer with status >= 400; the message is Dolibarr's own. */
final class RestError extends ToolError
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
