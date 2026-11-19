<?php

declare(strict_types=1);

namespace DoliMcp\Mcp;

final class Tool
{
    /** @param \Closure(array):ToolResult $handler */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly \Closure $handler,
        public readonly bool $writes = false,
    ) {
    }
}
