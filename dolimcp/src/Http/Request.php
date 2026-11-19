<?php

declare(strict_types=1);

namespace DoliMcp\Http;

final class Request
{
    /** @param array<string,string> $headers header names in lower case */
    public function __construct(
        public readonly string $method,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }
}
