<?php

declare(strict_types=1);

namespace DoliMcp\Tools;

use DoliMcp\Dolibarr\Context;
use DoliMcp\Mcp\Tool;
use DoliMcp\Mcp\ToolError;
use DoliMcp\Mcp\ToolRegistry;

/** Who may do what (user rights) and what runs by itself (scheduled jobs). */
final class AccessTools
{
    private const FREQUENCY_UNITS = [60 => 'minute', 3600 => 'hour', 86400 => 'day', 604800 => 'week', 2678400 => 'month'];

    public static function register(ToolRegistry $registry, Context $context): void
    {
        require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
        require_once DOL_DOCUMENT_ROOT . '/cron/class/cronjob.class.php';

        $registry->add(new Tool('user_rights_get',
            'List the rights granted directly to a user (not through a group). With all=true, list every right Dolibarr defines with a granted flag: that is where the ids for user_rights_set come from.',
            SetupTools::schema(['user_id' => ['type' => 'integer'], 'all' => ['type' => 'boolean']], ['user_id']),
            SetupTools::admin($context, static fn (array $a): array => self::rights($context, self::user($context, (int) $a['user_id']), (bool) ($a['all'] ?? false)))));
        $registry->add(new Tool('user_rights_set',
            'Grant (grant=true) or revoke (grant=false) rights of a user: the right_ids from user_rights_get(all=true), and/or every right of a module (e.g. facture). Returns the rights of the user afterwards.',
            SetupTools::schema(['user_id' => ['type' => 'integer'], 'grant' => ['type' => 'boolean'], 'right_ids' => ['type' => 'array', 'items' => ['type' => 'integer']], 'module' => ['type' => 'string']], ['user_id', 'grant']),
            SetupTools::admin($context, static fn (array $a): array => self::setRights($context, (int) $a['user_id'], (bool) $a['grant'], array_map('intval', (array) ($a['right_ids'] ?? [])), (string) ($a['module'] ?? ''))), true));
        $registry->add(new Tool('cron_list', 'List the scheduled jobs with their frequency, state and last and next run.',
            SetupTools::schema([]),
            SetupTools::admin($context, static fn (array $a): array => self::jobs($context))));
        $registry->add(new Tool('cron_set', 'Enable or disable a scheduled job by its id from cron_list.',
            SetupTools::schema(['id' => ['type' => 'integer'], 'enabled' => ['type' => 'boolean']], ['id', 'enabled']),
            SetupTools::admin($context, static fn (array $a): array => self::jobSet($context, (int) $a['id'], (bool) $a['enabled'])), true));
    }

    private static function user(Context $context, int $id): \User
    {
        $user = new \User($context->db);
        if ($user->fetch($id) <= 0) {
            throw new ToolError("No user $id.");
        }

        return $user;
    }

    /** @return list<array{id: int, module: string, permission: string, granted?: bool}> */
    private static function rights(Context $context, \User $user, bool $all): array
    {
        $db = $context->db;
        $entity = SetupTools::entity();
        $resql = $db->query('SELECT d.id, d.module, d.perms, d.subperms, ur.fk_id AS granted FROM ' . MAIN_DB_PREFIX . 'rights_def AS d'
            . ' ' . ($all ? 'LEFT' : 'INNER') . ' JOIN ' . MAIN_DB_PREFIX . 'user_rights AS ur ON ur.fk_id = d.id AND ur.fk_user = ' . (int) $user->id . " AND ur.entity = $entity"
            . " WHERE d.entity = $entity ORDER BY d.module, d.perms, d.subperms");
        if (!$resql) {
            throw new ToolError('Could not read the rights: ' . $db->lasterror());
        }
        $rights = [];
        while ($row = $db->fetch_object($resql)) {
            $right = ['id' => (int) $row->id, 'module' => (string) $row->module, 'permission' => $row->perms . ($row->subperms ? '.' . $row->subperms : '')];
            $rights[] = $all ? $right + ['granted' => $row->granted !== null] : $right;
        }

        return $rights;
    }

    private static function setRights(Context $context, int $userId, bool $grant, array $ids, string $module): array
    {
        if ($ids === [] && $module === '') {
            throw new ToolError('Give right_ids and/or module.');
        }
        $user = self::user($context, $userId);
        $db = $context->db;
        $entity = SetupTools::entity();
        // Unknown ids or modules would be silently ignored by Dolibarr: check them first.
        if ($ids !== []) {
            $resql = $db->query('SELECT id FROM ' . MAIN_DB_PREFIX . "rights_def WHERE entity = $entity AND id IN (" . implode(',', $ids) . ')');
            $known = [];
            while ($resql && ($row = $db->fetch_object($resql))) {
                $known[] = (int) $row->id;
            }
            if ($unknown = array_diff($ids, $known)) {
                throw new ToolError('Unknown right id ' . implode(', ', $unknown) . '; user_rights_get with all=true lists them.');
            }
        }
        if ($module !== '') {
            $resql = $db->query('SELECT 1 FROM ' . MAIN_DB_PREFIX . "rights_def WHERE entity = $entity AND module = '" . $db->escape($module) . "'");
            if (!$resql || $db->num_rows($resql) === 0) {
                throw new ToolError("Unknown module $module.");
            }
        }

        $apply = static fn (int $id, string $moduleName): int => $grant ? $user->addrights($id, $moduleName, '', $entity) : $user->delrights($id, $moduleName, '', $entity);
        foreach ($ids as $id) {
            if ($apply($id, '') < 0) {
                throw new ToolError('Dolibarr could not change the right ' . $id . ': ' . $user->error);
            }
        }
        if ($module !== '' && $apply(0, $module) < 0) {
            throw new ToolError("Dolibarr could not change the rights of $module: " . $user->error);
        }

        return self::rights($context, $user, false);
    }

    /** @return list<array<string, mixed>> */
    private static function jobs(Context $context): array
    {
        $cron = new \Cronjob($context->db);
        if ($cron->fetchAll('ASC', 't.rowid', 0, 0, -1) < 0) {
            throw new ToolError('Could not read the scheduled jobs: ' . $cron->error);
        }

        return array_map(static fn (\Cronjob $job): array => self::job($job), $cron->lines);
    }

    private static function job(\Cronjob $job): array
    {
        $date = static fn (mixed $timestamp): ?string => empty($timestamp) ? null : gmdate('Y-m-d H:i:s', (int) $timestamp) . ' UTC';
        $unit = self::FREQUENCY_UNITS[(int) $job->unitfrequency] ?? ((int) $job->unitfrequency . ' s');

        return [
            'id' => (int) $job->id,
            'label' => (string) $job->label,
            'command' => $job->jobtype === 'method' ? $job->classesname . '::' . $job->methodename : (string) $job->command,
            'frequency' => 'every ' . $job->frequency . ' ' . $unit . ((int) $job->frequency > 1 ? 's' : ''),
            'enabled' => (int) $job->status === \Cronjob::STATUS_ENABLED,
            'last_run' => $date($job->datelastrun),
            'last_result' => $job->lastresult === null || $job->lastresult === '' ? null : (string) $job->lastresult,
            'next_run' => $date($job->datenextrun),
        ];
    }

    private static function jobSet(Context $context, int $id, bool $enabled): array
    {
        $cron = new \Cronjob($context->db);
        if ($cron->fetch($id) <= 0) {
            throw new ToolError("No scheduled job $id.");
        }
        $cron->status = $enabled ? \Cronjob::STATUS_ENABLED : \Cronjob::STATUS_DISABLED;
        if ($cron->update($context->user) < 0) {
            throw new ToolError('Dolibarr could not change the job: ' . DocumentTools::failure($cron, 'unknown error'));
        }

        return self::job($cron);
    }
}
