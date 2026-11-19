<?php

declare(strict_types=1);

namespace DoliMcp\Dolibarr;

/** Everything a tool needs from the authenticated Dolibarr request. */
final class Context
{
    public readonly RestClient $rest;

    public function __construct(
        public readonly \DoliDB $db,
        public readonly \User $user,
        public readonly string $apiKey,
    ) {
        $url = getDolGlobalString('DOLIMCP_API_URL') ?: 'http://127.0.0.1' . DOL_URL_ROOT . '/api/index.php';
        $this->rest = new RestClient($url, $apiKey);
    }
}
