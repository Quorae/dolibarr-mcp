<?php

declare(strict_types=1);

namespace DoliMcp\Dolibarr;

use DoliMcp\Mcp\ToolError;
use DoliMcp\Support\Compactor;

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
        // A bad setting must not lock the administrator out of const_set: only the REST calls fail.
        $this->rest = new RestClient($url, $apiKey, self::isHttpUrl($url) ? null : static function (): never {
            throw new ToolError('The DOLIMCP_API_URL setting is not an http(s) URL; fix it with const_set or in the module setup page.');
        });

        // Dolibarr returns the clear text of password custom fields: they are masked wherever Compactor runs.
        $secrets = [];
        $resql = $db->query("SELECT name FROM " . MAIN_DB_PREFIX . "extrafields WHERE type = 'password' AND entity IN (" . getEntity('extrafields') . ')');
        while ($resql && ($row = $db->fetch_object($resql))) {
            $secrets[] = 'options_' . $row->name;
        }
        Compactor::setExtraSecretFields($secrets);
    }

    private static function isHttpUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
