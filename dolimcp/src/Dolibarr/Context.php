<?php

declare(strict_types=1);

namespace DoliMcp\Dolibarr;

/** Everything a tool needs from the authenticated Dolibarr request. */
final class Context
{
    public function __construct(
        public readonly \DoliDB $db,
        public readonly \User $user,
        public readonly string $apiKey,
    ) {
    }
}
