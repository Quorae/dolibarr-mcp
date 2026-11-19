<?php

declare(strict_types=1);

namespace DoliMcp\Dolibarr;

use DoliMcp\Mcp\Tool;
use DoliMcp\Mcp\ToolResult;
use DoliMcp\Support\Compactor;

/** Audit trail of the writing tool calls, in the module's own table. */
final class Journal
{
    private const MAX_PARAMS = 60000;
    private const MAX_VALUE = 1024;
    private const BULK_KEYS = ['content_base64', 'filecontent'];

    public function __construct(private readonly \DoliDB $db, private readonly \User $user)
    {
    }

    public function record(Tool $tool, array $arguments, ToolResult $result): void
    {
        // api_request is flagged as writing, but its GET calls change nothing.
        if ($tool->name === 'api_request' && strtoupper((string) ($arguments['method'] ?? '')) === 'GET') {
            return;
        }

        global $conf;

        $params = (string) json_encode(self::omitBulk(Compactor::compact($arguments)), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (strlen($params) > self::MAX_PARAMS) { // the column is a 64 KB text
            $params = (string) json_encode(['truncated' => true, 'excerpt' => mb_strcut($params, 0, self::MAX_PARAMS - 100)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $outcome = $result->isError ? mb_substr((string) $result->data, 0, 255) : 'ok';

        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'dolimcp_journal (entity, datec, fk_user, tool, params, outcome) VALUES ('
            . (int) $conf->entity . ", '" . $this->db->idate(dol_now()) . "', " . (int) $this->user->id . ", '"
            . $this->db->escape($tool->name) . "', '" . $this->db->escape((string) $params) . "', '" . $this->db->escape($outcome) . "')";
        if (!$this->db->query($sql)) {
            throw new \RuntimeException($this->db->lasterror());
        }
    }

    /** File contents and other long strings would not fit the journal: only their size is kept. */
    private static function omitBulk(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $item) {
                $out[$k] = self::omitBulk($item, (string) $k);
            }

            return $out;
        }
        if (is_string($value) && (strlen($value) > self::MAX_VALUE || in_array($key, self::BULK_KEYS, true))) {
            return '<' . strlen($value) . ' bytes omitted>';
        }

        return $value;
    }
}
