<?php

declare(strict_types=1);

namespace DoliMcp\Http;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public static function json(int $status, array $payload): self
    {
        return new self(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    public static function empty(int $status): self
    {
        return new self($status, [], '');
    }
}
