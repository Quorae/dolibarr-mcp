<?php

declare(strict_types=1);

namespace DoliMcp\Dolibarr;

use DoliMcp\Mcp\ToolError;

/** A Dolibarr REST API answer outside 2xx; the message is Dolibarr's own. */
final class RestError extends ToolError
{
    public function __construct(string $message, public readonly int $status, private readonly bool $dolibarrNotFound = false)
    {
        parent::__construct($message);
    }

    /** Dolibarr answers a JSON 404 to an empty list; any other 404 (web server page, wrong URL) is a real error. */
    public function isEmptyResult(): bool
    {
        return $this->dolibarrNotFound;
    }
}
