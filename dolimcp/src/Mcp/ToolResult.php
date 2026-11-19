<?php

declare(strict_types=1);

namespace DoliMcp\Mcp;

final class ToolResult
{
    private function __construct(
        public readonly bool $isError,
        public readonly mixed $data,
        public readonly ?array $file = null,
    ) {
    }

    public static function ok(mixed $data): self
    {
        return new self(false, $data);
    }

    public static function file(string $uri, string $mimeType, string $base64, array $meta = []): self
    {
        return new self(false, $meta, ['uri' => $uri, 'mimeType' => $mimeType, 'blob' => $base64]);
    }

    public static function error(string $message): self
    {
        return new self(true, $message);
    }
}
