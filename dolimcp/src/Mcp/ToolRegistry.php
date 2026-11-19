<?php

declare(strict_types=1);

namespace DoliMcp\Mcp;

final class ToolRegistry
{
    /** @var array<string,Tool> */
    private array $tools = [];

    public function add(Tool $tool): void
    {
        $this->tools[$tool->name] = $tool;
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /** @return list<Tool> sorted by name */
    public function all(): array
    {
        $tools = $this->tools;
        ksort($tools);
        return array_values($tools);
    }
}
