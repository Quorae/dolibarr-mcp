<?php

declare(strict_types=1);

namespace DoliMcp\Tools;

use DoliMcp\Dolibarr\Context;
use DoliMcp\Mcp\Tool;
use DoliMcp\Mcp\ToolError;
use DoliMcp\Mcp\ToolRegistry;
use DoliMcp\Mcp\ToolResult;
use DoliMcp\Support\Compactor;
use DoliMcp\Support\Duration;

/** Projects, tasks and the time logged on them. Invoicing logged time: TimeInvoice. */
final class TimeTools
{
    private const DATE_HELP = 'Dates are YYYY-MM-DD.';

    public static function register(ToolRegistry $registry, Context $context): void
    {
        require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
        require_once DOL_DOCUMENT_ROOT . '/projet/class/task.class.php';
        $duration = ['type' => ['integer', 'string'], 'description' => 'Minutes (90) or text: 3h30, 2h, 45m, 1.5h.'];
        $date = ['type' => 'string', 'description' => self::DATE_HELP];
        $id = ['type' => 'integer'];
        $schema = static fn (array $properties, array $required = []): array => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];

        $registry->add(new Tool(
            'project_list',
            'List the projects you can log time on (draft and open; closed ones with include_closed) with their open tasks and the logged time not yet invoiced (unbilled_minutes).',
            $schema(['include_closed' => ['type' => 'boolean']]),
            static fn (array $a): ToolResult => ToolResult::ok(self::projects($context, (bool) ($a['include_closed'] ?? false))),
        ));
        $registry->add(new Tool(
            'time_entry_list',
            'List logged time entries, oldest first. Filters: project_id, task_id, from and to (inclusive dates), invoiced (true = already on an invoice, false = not yet). ' . self::DATE_HELP,
            $schema(['project_id' => $id, 'task_id' => $id, 'from' => $date, 'to' => $date, 'invoiced' => ['type' => 'boolean'], 'limit' => $id]),
            static fn (array $a): ToolResult => ToolResult::ok(self::entries($context, $a)),
        ));
        $registry->add(new Tool(
            'time_entry_get',
            'Read one logged time entry.',
            $schema(['id' => $id], ['id']),
            static fn (array $a): ToolResult => ToolResult::ok(self::present(self::row($context, (int) $a['id']))),
        ));
        $registry->add(new Tool(
            'time_entry_create',
            'Log time on a project task, in your name. Give task_id, or project_id when the project has a single open task (otherwise the error lists its tasks). The date is stored at midday, without a start hour. ' . self::DATE_HELP,
            $schema(['project_id' => $id, 'task_id' => $id, 'date' => $date, 'duration' => $duration, 'description' => ['type' => 'string']], ['date', 'duration']),
            static fn (array $a): ToolResult => ToolResult::ok(self::create($context, $a)),
            true,
        ));
        $registry->add(new Tool(
            'time_entry_update',
            'Change the date, duration, description or task of a logged time entry; what you do not give stays as it is. ' . self::DATE_HELP,
            $schema(['id' => $id, 'date' => $date, 'duration' => $duration, 'description' => ['type' => 'string'], 'task_id' => $id], ['id']),
            static fn (array $a): ToolResult => ToolResult::ok(self::update($context, $a)),
            true,
        ));
        $registry->add(new Tool(
            'time_entry_delete',
            'Delete a logged time entry.',
            $schema(['id' => $id], ['id']),
            static fn (array $a): ToolResult => ToolResult::ok(self::delete($context, (int) $a['id'])),
            true,
        ));

        TimeInvoice::register($registry, $context);
    }

    /** Projects the user may see: null when all of them. */
    public static function visibleProjects(Context $context): ?array
    {
        if ($context->user->hasRight('projet', 'all', 'lire')) {
            return null;
        }

        return array_map('intval', (array) (new \Project($context->db))->getProjectsAuthorizedForUser($context->user, 0, 0));
    }

    public static function requireRight(Context $context, int|bool|null $allowed, string $what): void
    {
        if (!$allowed) {
            throw new ToolError("Your Dolibarr user may not $what.");
        }
    }

    // ---- reading ----

    private static function projects(Context $context, bool $includeClosed): array
    {
        self::requireRight($context, $context->user->hasRight('projet', 'lire'), 'read projects');
        $db = $context->db;
        $visible = self::visibleProjects($context);
        $sql = 'SELECT p.rowid, p.ref, p.title, p.fk_statut, s.nom FROM ' . MAIN_DB_PREFIX . 'projet AS p'
            . ' LEFT JOIN ' . MAIN_DB_PREFIX . 'societe AS s ON s.rowid = p.fk_soc'
            . ' WHERE p.entity IN (' . getEntity('project') . ')'
            . ($includeClosed ? '' : ' AND p.fk_statut < 2')
            . ($visible === null ? '' : ' AND p.rowid IN (' . implode(',', $visible ?: [0]) . ')')
            . ' ORDER BY p.ref';
        $resql = $db->query($sql);
        if (!$resql) {
            throw new ToolError('Could not read the projects: ' . $db->lasterror());
        }
        $projects = [];
        while ($row = $db->fetch_object($resql)) {
            $projects[(int) $row->rowid] = [
                'id' => (int) $row->rowid,
                'ref' => (string) $row->ref,
                'title' => (string) $row->title,
                'thirdparty' => (string) $row->nom,
                'status_label' => ObjectTypes::statusLabel('project', (int) $row->fk_statut),
                'tasks' => [],
                'unbilled_minutes' => 0,
            ];
        }
        if ($projects === []) {
            return [];
        }
        $ids = implode(',', array_keys($projects));

        $resql = $db->query('SELECT rowid, ref, label, fk_projet FROM ' . MAIN_DB_PREFIX . "projet_task WHERE fk_projet IN ($ids) AND fk_statut < " . \Task::STATUS_CLOSED . ' ORDER BY fk_projet, rowid');
        while ($resql && ($row = $db->fetch_object($resql))) {
            $projects[(int) $row->fk_projet]['tasks'][] = ['id' => (int) $row->rowid, 'ref' => (string) $row->ref, 'label' => (string) $row->label];
        }

        $resql = $db->query('SELECT pt.fk_projet, SUM(t.element_duration) AS seconds FROM ' . MAIN_DB_PREFIX . 'element_time AS t'
            . ' JOIN ' . MAIN_DB_PREFIX . "projet_task AS pt ON pt.rowid = t.fk_element WHERE t.elementtype = 'task' AND (t.invoice_id IS NULL OR t.invoice_id = 0) AND pt.fk_projet IN ($ids) GROUP BY pt.fk_projet");
        while ($resql && ($row = $db->fetch_object($resql))) {
            $projects[(int) $row->fk_projet]['unbilled_minutes'] = intdiv((int) $row->seconds, 60);
        }

        foreach ($projects as $projectId => &$project) {
            $object = new \Project($db);
            $object->id = $projectId;
            $object->fetch_optionals();
            $project['array_options'] = $object->array_options;
        }
        unset($project);

        return array_map(static fn (array $project): mixed => Compactor::compact($project), array_values($projects));
    }

    private static function entries(Context $context, array $a): array
    {
        self::requireRight($context, $context->user->hasRight('projet', 'lire'), 'read time entries');
        $db = $context->db;
        $where = [];
        foreach (['project_id' => 'pt.fk_projet', 'task_id' => 'pt.rowid'] as $key => $column) {
            if (isset($a[$key])) {
                $where[] = "$column = " . (int) $a[$key];
            }
        }
        // element_date holds the local (server time zone) midday of the day as a datetime: compare it as text.
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (isset($a[$key])) {
                $where[] = 'DATE(t.element_date) ' . $operator . " '" . $db->escape(self::checkDate((string) $a[$key])) . "'";
            }
        }
        if (isset($a['invoiced'])) {
            $where[] = $a['invoiced'] ? 't.invoice_id > 0' : '(t.invoice_id IS NULL OR t.invoice_id = 0)';
        }
        $visible = self::visibleProjects($context);
        if ($visible !== null) {
            $where[] = 'pt.fk_projet IN (' . implode(',', $visible ?: [0]) . ')';
        }

        return array_map(
            static fn (array $row): array => self::present($row),
            self::query($context, $where, ' ORDER BY t.element_date, t.rowid LIMIT ' . max(1, (int) ($a['limit'] ?? 200))),
        );
    }

    /** @return list<array<string, mixed>> raw rows of element_time with their task, project and user */
    private static function query(Context $context, array $where, string $tail = ''): array
    {
        $db = $context->db;
        $sql = 'SELECT t.rowid, t.fk_element, t.element_date, t.element_datehour, t.element_date_withhour, t.element_duration, t.fk_user, t.fk_product, t.note, t.invoice_id, t.invoice_line_id, pt.label AS task_label, pt.fk_projet, p.ref AS project_ref, u.login'
            . ' FROM ' . MAIN_DB_PREFIX . 'element_time AS t'
            . ' JOIN ' . MAIN_DB_PREFIX . 'projet_task AS pt ON pt.rowid = t.fk_element'
            . ' JOIN ' . MAIN_DB_PREFIX . 'projet AS p ON p.rowid = pt.fk_projet'
            . ' LEFT JOIN ' . MAIN_DB_PREFIX . 'user AS u ON u.rowid = t.fk_user'
            . " WHERE t.elementtype = 'task' AND pt.entity IN (" . getEntity('project') . ')'
            . ($where === [] ? '' : ' AND ' . implode(' AND ', $where)) . $tail;
        $resql = $db->query($sql);
        if (!$resql) {
            throw new ToolError('Could not read the time entries: ' . $db->lasterror());
        }
        $rows = [];
        while ($row = $db->fetch_array($resql)) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** One entry the user may see, or "not found". */
    private static function row(Context $context, int $id): array
    {
        self::requireRight($context, $context->user->hasRight('projet', 'lire'), 'read time entries');
        $rows = self::query($context, ['t.rowid = ' . $id]);
        $visible = self::visibleProjects($context);
        if ($rows === [] || ($visible !== null && !in_array((int) $rows[0]['fk_projet'], $visible, true))) {
            throw new ToolError("Time entry $id not found.");
        }

        return $rows[0];
    }

    private static function present(array $row): array
    {
        $seconds = (int) $row['element_duration'];

        return Compactor::compact([
            'id' => (int) $row['rowid'],
            'date' => substr((string) $row['element_date'], 0, 10),
            'duration' => Duration::format($seconds),
            'minutes' => intdiv($seconds, 60),
            'description' => (string) $row['note'],
            'project_id' => (int) $row['fk_projet'],
            'project_ref' => (string) $row['project_ref'],
            'task_id' => (int) $row['fk_element'],
            'task_label' => (string) $row['task_label'],
            'user' => (string) $row['login'],
            'invoice_id' => (int) $row['invoice_id'] > 0 ? (int) $row['invoice_id'] : null,
        ]);
    }

    // ---- writing ----

    private static function create(Context $context, array $a): array
    {
        self::requireRight($context, $context->user->hasRight('projet', 'time'), 'log time on projects');
        $seconds = Duration::toSeconds($a['duration']);
        $date = self::midday((string) $a['date']);
        $task = self::taskFor($context, $a);

        $task->timespent_note = (string) ($a['description'] ?? '');
        $task->timespent_duration = $seconds;
        $task->timespent_date = $date;
        $task->timespent_withhour = 0;
        $task->timespent_fk_user = (int) $context->user->id;
        $task->timespent_fk_product = 0;
        if ($task->addTimeSpent($context->user) <= 0) {
            throw new ToolError(DocumentTools::failure($task, 'Dolibarr could not log the time.'));
        }

        return self::present(self::row($context, (int) $task->timespent_id));
    }

    /** The task to log on: the given one, or the single open task of the given project. */
    private static function taskFor(Context $context, array $a): \Task
    {
        $task = new \Task($context->db);
        $taskId = (int) ($a['task_id'] ?? 0);
        if ($taskId <= 0) {
            $projectId = (int) ($a['project_id'] ?? 0);
            if ($projectId <= 0) {
                throw new ToolError('Give task_id, or project_id of a project that has a single open task.');
            }
            $open = [];
            $resql = $context->db->query('SELECT rowid, label FROM ' . MAIN_DB_PREFIX . 'projet_task WHERE fk_projet = ' . $projectId . ' AND entity IN (' . getEntity('project') . ') AND fk_statut < ' . \Task::STATUS_CLOSED . ' ORDER BY rowid');
            while ($resql && ($row = $context->db->fetch_object($resql))) {
                $open[] = $row;
            }
            if (count($open) === 0) {
                throw new ToolError("Project $projectId has no open task; create a task first.");
            }
            if (count($open) > 1) {
                throw new ToolError("Project $projectId has several open tasks; give task_id: " . implode('; ', array_map(static fn (object $t): string => $t->rowid . ' = ' . $t->label, $open)) . '.');
            }
            $taskId = (int) $open[0]->rowid;
        }
        if ($task->fetch($taskId) <= 0) {
            throw new ToolError("Task $taskId not found.");
        }
        $visible = self::visibleProjects($context);
        if ($visible !== null && !in_array((int) $task->fk_project, $visible, true)) {
            throw new ToolError("Task $taskId not found.");
        }
        $task->fetchProject();
        if (empty($task->project->status)) {
            throw new ToolError('The project of this task is a draft; validate the project before logging time.');
        }

        return $task;
    }

    private static function update(Context $context, array $a): array
    {
        $row = self::row($context, (int) $a['id']);
        self::requireOwner($context, (int) $row['fk_user']);
        $task = new \Task($context->db);
        $task->fetchTimeSpent((int) $row['rowid']);
        $newTaskId = (int) ($a['task_id'] ?? $row['fk_element']);
        if ($task->fetch($newTaskId) <= 0) {
            throw new ToolError("Task $newTaskId not found.");
        }
        $visible = self::visibleProjects($context);
        if ($visible !== null && !in_array((int) $task->fk_project, $visible, true)) {
            throw new ToolError("Task $newTaskId not found.");
        }

        $seconds = (int) $row['element_duration'];
        $task->timespent_old_duration = $seconds;
        $task->timespent_duration = isset($a['duration']) ? Duration::toSeconds($a['duration']) : $seconds;
        $task->timespent_note = (string) ($a['description'] ?? $row['note']);
        if (isset($a['date'])) {
            $task->timespent_date = self::midday((string) $a['date']);
            $task->timespent_datehour = $task->timespent_date;
            $task->timespent_withhour = 0;
        }
        // updateTimeSpent writes these back: keep the existing invoice link.
        $task->timespent_invoiceid = (int) $row['invoice_id'];
        $task->timespent_invoicelineid = (int) $row['invoice_line_id'];
        if ($task->updateTimeSpent($context->user) < 0) {
            throw new ToolError(DocumentTools::failure($task, 'Dolibarr could not update the time entry.'));
        }

        return self::present(self::row($context, (int) $row['rowid']));
    }

    private static function delete(Context $context, int $id): array
    {
        $row = self::row($context, $id);
        self::requireRight($context, $context->user->hasRight('projet', 'time') || $context->user->hasRight('projet', 'all', 'creer'), 'delete time entries');
        self::requireOwner($context, (int) $row['fk_user']);
        $task = new \Task($context->db);
        $task->fetchTimeSpent($id);
        if ($task->delTimeSpent($context->user) < 0) {
            throw new ToolError(DocumentTools::failure($task, 'Dolibarr could not delete the time entry.'));
        }

        return ['deleted' => $id];
    }

    /** As in projet/tasks/time.php: your own time and your subordinates' time, or everybody's with the right on all projects. */
    private static function requireOwner(Context $context, int $ownerId): void
    {
        $mine = [(int) $context->user->id, ...array_map('intval', (array) $context->user->getAllChildIds(1))];
        self::requireRight($context, in_array($ownerId, $mine, true) || $context->user->hasRight('projet', 'all', 'creer'), "change the time of another user");
    }

    // ---- dates ----

    private static function checkDate(string $date): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new ToolError("The date \"$date\" is not valid; use YYYY-MM-DD.");
        }

        return $date;
    }

    /** Midday of the day in the server time zone, the way projet/tasks/time.php stores a day without hour. */
    private static function midday(string $date): int
    {
        [$year, $month, $day] = array_map('intval', explode('-', self::checkDate($date)));

        return (int) dol_mktime(12, 0, 0, $month, $day, $year);
    }
}
