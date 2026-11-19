<?php

declare(strict_types=1);

namespace DoliMcp\Dolibarr;

use DoliMcp\Mcp\Tool;
use DoliMcp\Mcp\ToolResult;
use DoliMcp\Support\Compactor;

/** Audit trail of the writing tool calls, in the module's own table. */
final class Journal
{
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

        $params = json_encode(Compactor::compact($arguments), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $outcome = $result->isError ? mb_substr((string) $result->data, 0, 255) : 'ok';

        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'dolimcp_journal (entity, datec, fk_user, tool, params, outcome) VALUES ('
            . (int) $conf->entity . ", '" . $this->db->idate(dol_now()) . "', " . (int) $this->user->id . ", '"
            . $this->db->escape($tool->name) . "', '" . $this->db->escape((string) $params) . "', '" . $this->db->escape($outcome) . "')";
        if (!$this->db->query($sql)) {
            throw new \RuntimeException($this->db->lasterror());
        }
    }
}
