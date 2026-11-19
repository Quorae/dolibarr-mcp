<?php

declare(strict_types=1);

namespace DoliMcp\Tests\Integration;

final class TimeToolsTest extends McpTestCase
{
    /** @var list<callable> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $undo) {
            try {
                $undo();
            } catch (\Throwable) {
            }
        }
        $this->cleanup = [];
    }

    private function delete(string $path): void
    {
        $this->cleanup[] = fn () => $this->rest('DELETE', $path);
    }

    /** @return array{thirdparty: int, project: int, tasks: list<int>} a validated project with $taskCount open tasks */
    private function fixture(int $taskCount = 1): array
    {
        $suffix = bin2hex(random_bytes(3));
        $thirdparty = (int) $this->rest('POST', 'thirdparties', ['name' => "Time $suffix", 'client' => 1])['body'];
        $this->delete("thirdparties/$thirdparty");
        $project = (int) $this->rest('POST', 'projects', ['ref' => "TM$suffix", 'title' => "Project $suffix", 'socid' => $thirdparty])['body'];
        $this->assertGreaterThan(0, $project);
        $this->delete("projects/$project");
        $validated = $this->rest('POST', "projects/$project/validate", ['notrigger' => 0]);
        $this->assertSame(200, $validated['status'], json_encode($validated['body']));
        $tasks = [];
        for ($i = 1; $i <= $taskCount; $i++) {
            $task = (int) $this->rest('POST', 'tasks', ['ref' => "T$suffix-$i", 'label' => "Task $suffix $i", 'fk_project' => $project])['body'];
            $this->assertGreaterThan(0, $task);
            $this->delete("tasks/$task");
            $tasks[] = $task;
        }

        return ['thirdparty' => $thirdparty, 'project' => $project, 'tasks' => $tasks];
    }

    /** Logs time through the tool and registers its deletion. */
    private function log(array $arguments): array
    {
        $r = $this->callTool('time_entry_create', $arguments);
        $this->assertFalse($r['isError'], $r['text']);
        $id = (int) $r['data']['id'];
        $this->cleanup[] = fn () => $this->callTool('time_entry_delete', ['id' => $id]);

        return $r['data'];
    }

    public function testEntryForThe19thIsReadBackOnThe19th(): void
    {
        $f = $this->fixture();
        $entry = $this->log(['task_id' => $f['tasks'][0], 'date' => '2026-11-19', 'duration' => 60]);

        $got = $this->callTool('time_entry_get', ['id' => $entry['id']]);
        $this->assertFalse($got['isError'], $got['text']);
        $this->assertSame('2026-11-19', $got['data']['date']);

        $rest = $this->rest('GET', 'tasks/' . $f['tasks'][0] . '/timespent');
        $this->assertSame(200, $rest['status']);
        $this->assertCount(1, $rest['body']);
        // The date column is local midnight and the date-hour local midday (the server zone is Europe/Paris):
        // the midday read in UTC is still the 19th; the midnight is the 19th only in the server zone.
        $line = $rest['body'][0];
        $this->assertSame('2026-11-19', gmdate('Y-m-d', (int) $line['timespent_line_datehour']));
        $this->assertSame('2026-11-19', (new \DateTimeImmutable('@' . $line['timespent_line_date']))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d'));
    }

    public function testDurationTextIsAccepted(): void
    {
        $f = $this->fixture();
        $entry = $this->log(['task_id' => $f['tasks'][0], 'date' => '2026-11-19', 'duration' => '3h30']);

        $this->assertSame(210, $entry['minutes']);
        $this->assertSame('3h30', $entry['duration']);
        $this->assertSame($f['project'], $entry['project_id']);
        $this->assertSame($f['tasks'][0], $entry['task_id']);
    }

    public function testProjectWithOneTaskTakesItAutomatically(): void
    {
        $f = $this->fixture();
        $entry = $this->log(['project_id' => $f['project'], 'date' => '2026-11-19', 'duration' => '45m', 'description' => 'Review']);

        $this->assertSame($f['tasks'][0], $entry['task_id']);
        $this->assertSame('Review', $entry['description']);
    }

    public function testProjectWithTwoTasksAsksWhichOne(): void
    {
        $f = $this->fixture(2);
        $r = $this->callTool('time_entry_create', ['project_id' => $f['project'], 'date' => '2026-11-19', 'duration' => 30]);

        $this->assertTrue($r['isError']);
        foreach ($f['tasks'] as $taskId) {
            $this->assertStringContainsString((string) $taskId, $r['text']);
        }
        $this->assertStringContainsString('Task', $r['text']);
    }

    public function testListFiltersByPeriodAndInvoiced(): void
    {
        $f = $this->fixture();
        $a = $this->log(['task_id' => $f['tasks'][0], 'date' => '2026-11-10', 'duration' => 60]);
        $b = $this->log(['task_id' => $f['tasks'][0], 'date' => '2026-11-20', 'duration' => 120]);

        $all = $this->callTool('time_entry_list', ['project_id' => $f['project']]);
        $this->assertSame([$a['id'], $b['id']], array_column($all['data'], 'id'));

        $period = $this->callTool('time_entry_list', ['project_id' => $f['project'], 'from' => '2026-11-15', 'to' => '2026-11-30']);
        $this->assertSame([$b['id']], array_column($period['data'], 'id'));

        $until = $this->callTool('time_entry_list', ['task_id' => $f['tasks'][0], 'to' => '2026-11-10']);
        $this->assertSame([$a['id']], array_column($until['data'], 'id'));

        $invoiced = $this->callTool('time_entry_list', ['project_id' => $f['project'], 'invoiced' => true]);
        $this->assertSame([], $invoiced['data']);
        $unbilled = $this->callTool('time_entry_list', ['project_id' => $f['project'], 'invoiced' => false, 'limit' => 1]);
        $this->assertCount(1, $unbilled['data']);
    }

    public function testUpdateAndDelete(): void
    {
        $f = $this->fixture(2);
        $entry = $this->log(['task_id' => $f['tasks'][0], 'date' => '2026-11-19', 'duration' => 60, 'description' => 'Before']);

        $updated = $this->callTool('time_entry_update', ['id' => $entry['id'], 'date' => '2026-11-18', 'duration' => '2h', 'description' => 'After', 'task_id' => $f['tasks'][1]]);
        $this->assertFalse($updated['isError'], $updated['text']);
        $this->assertSame('2026-11-18', $updated['data']['date']);
        $this->assertSame(120, $updated['data']['minutes']);
        $this->assertSame('After', $updated['data']['description']);
        $this->assertSame($f['tasks'][1], $updated['data']['task_id']);

        $only = $this->callTool('time_entry_update', ['id' => $entry['id'], 'duration' => 30]);
        $this->assertSame('2026-11-18', $only['data']['date']);
        $this->assertSame('After', $only['data']['description']);
        $this->assertSame(30, $only['data']['minutes']);

        $deleted = $this->callTool('time_entry_delete', ['id' => $entry['id']]);
        $this->assertFalse($deleted['isError'], $deleted['text']);
        $this->assertTrue($this->callTool('time_entry_get', ['id' => $entry['id']])['isError']);
    }

    public function testProjectListShowsUnbilledMinutes(): void
    {
        $f = $this->fixture();
        $this->log(['task_id' => $f['tasks'][0], 'date' => '2026-11-19', 'duration' => '1h30']);
        $this->log(['task_id' => $f['tasks'][0], 'date' => '2026-11-20', 'duration' => 30]);

        $list = $this->callTool('project_list');
        $this->assertFalse($list['isError'], $list['text']);
        $mine = array_values(array_filter($list['data'], fn (array $p): bool => $p['id'] === $f['project']));
        $this->assertCount(1, $mine);
        $this->assertSame(120, $mine[0]['unbilled_minutes']);
        $this->assertSame('open', $mine[0]['status_label']);
        $this->assertSame($f['tasks'][0], $mine[0]['tasks'][0]['id']);
    }

    public function testTimeInvoiceWithAServiceLinksEntries(): void
    {
        $f = $this->fixture();
        $suffix = bin2hex(random_bytes(3));
        $service = (int) $this->rest('POST', 'products', [
            'ref' => "SVC$suffix", 'label' => "Day $suffix", 'type' => 1, 'price' => 490, 'price_base_type' => 'HT', 'tva_tx' => 20,
            'status' => 1, 'status_buy' => 1, 'duration_value' => 7, 'duration_unit' => 'h',
        ])['body'];
        $this->assertGreaterThan(0, $service);
        $this->delete("products/$service");
        $this->log(['task_id' => $f['tasks'][0], 'date' => '2026-11-18', 'duration' => '7h']);
        $this->log(['task_id' => $f['tasks'][0], 'date' => '2026-11-19', 'duration' => '7h']);

        $r = $this->callTool('time_invoice', ['project_id' => $f['project'], 'product_id' => $service]);
        $this->assertFalse($r['isError'], $r['text']);
        $invoiceId = (int) $r['data']['invoice']['id'];
        $this->delete("invoices/$invoiceId");
        $this->assertSame(2, $r['data']['linked_entries']);

        $invoice = $this->rest('GET', "invoices/$invoiceId")['body'];
        $this->assertSame($f['thirdparty'], (int) $invoice['socid']);
        $this->assertCount(1, $invoice['lines']);
        $this->assertEquals(2, $invoice['lines'][0]['qty']);
        $this->assertEquals(490, $invoice['lines'][0]['subprice']);

        $entries = $this->callTool('time_entry_list', ['project_id' => $f['project']])['data'];
        $this->assertCount(2, $entries);
        foreach ($entries as $entry) {
            $this->assertSame($invoiceId, $entry['invoice_id']);
        }
        $this->assertSame(
            0,
            array_values(array_filter($this->callTool('project_list')['data'], fn (array $p): bool => $p['id'] === $f['project']))[0]['unbilled_minutes'] ?? 0,
        );
    }

    public function testTimeInvoiceWithAnHourlyPricePerEntry(): void
    {
        $f = $this->fixture();
        $this->log(['task_id' => $f['tasks'][0], 'date' => '2026-11-18', 'duration' => '2h']);
        $this->log(['task_id' => $f['tasks'][0], 'date' => '2026-11-19', 'duration' => '1h30']);

        $r = $this->callTool('time_invoice', ['project_id' => $f['project'], 'mode' => 'per_entry', 'hourly_price' => 80, 'vat_rate' => 20, 'until' => '2026-11-18']);
        $this->assertFalse($r['isError'], $r['text']);
        $invoiceId = (int) $r['data']['invoice']['id'];
        $this->delete("invoices/$invoiceId");
        $this->assertSame(1, $r['data']['linked_entries']);

        $lines = $this->rest('GET', "invoices/$invoiceId")['body']['lines'];
        $this->assertCount(1, $lines);
        $this->assertEquals(2, $lines[0]['qty']);
        $this->assertEquals(80, $lines[0]['subprice']);
    }

    public function testTimeInvoiceWithoutPriceIsAnError(): void
    {
        $f = $this->fixture();
        $this->log(['task_id' => $f['tasks'][0], 'date' => '2026-11-19', 'duration' => 60]);

        $r = $this->callTool('time_invoice', ['project_id' => $f['project']]);
        $this->assertTrue($r['isError']);
        $this->assertStringContainsString('hourly_price', $r['text']);
    }

    public function testTimeInvoiceWithNothingToInvoiceIsAnError(): void
    {
        $f = $this->fixture();

        $r = $this->callTool('time_invoice', ['project_id' => $f['project'], 'hourly_price' => 80]);
        $this->assertTrue($r['isError']);
        $this->assertStringContainsString('nothing to invoice', strtolower($r['text']));
    }

    public function testSecondInvoiceOfTheSameEntriesIsRefused(): void
    {
        $f = $this->fixture();
        $entry = $this->log(['task_id' => $f['tasks'][0], 'date' => '2026-11-19', 'duration' => 60]);
        $first = $this->callTool('time_invoice', ['project_id' => $f['project'], 'hourly_price' => 80]);
        $this->assertFalse($first['isError'], $first['text']);
        $invoiceId = (int) $first['data']['invoice']['id'];
        $this->delete("invoices/$invoiceId");

        $again = $this->callTool('time_invoice', ['project_id' => $f['project'], 'hourly_price' => 80, 'time_entry_ids' => [$entry['id']]]);
        $this->assertTrue($again['isError']);
        $this->assertStringContainsString('nothing to invoice', strtolower($again['text']));
        $invoices = $this->rest('GET', 'invoices?thirdparty_ids=' . $f['thirdparty']);
        $this->assertCount(1, $invoices['body']);

        $listed = $this->callTool('time_entry_list', ['project_id' => $f['project'], 'invoiced' => true]);
        $this->assertSame([$entry['id']], array_column($listed['data'], 'id'));
        $this->assertSame($invoiceId, $listed['data'][0]['invoice_id']);
    }

    /** @return array{id: int, key: string} a non-admin user who may read projects and log time (and renew their own key, which /login needs) */
    private function restrictedUser(int $thirdpartyId = 0): array
    {
        $login = 'tm' . bin2hex(random_bytes(4));
        $fields = ['login' => $login, 'pass' => 'Restricted-pass-1', 'lastname' => $login, 'admin' => 0];
        $id = (int) $this->rest('POST', 'users', $fields)['body'];
        $this->assertGreaterThan(0, $id);
        $this->cleanup[] = function () use ($id): void {
            self::db()->query("DELETE FROM llx_user_rights WHERE fk_user = $id");
            $this->rest('DELETE', "users/$id");
        };
        self::db()->query("INSERT INTO llx_user_rights (entity, fk_user, fk_id) SELECT 1, $id, id FROM llx_rights_def WHERE (module = 'projet' AND perms IN ('lire', 'time') AND (subperms IS NULL OR subperms = '')) OR (module = 'user' AND perms = 'self' AND subperms = 'creer')");
        $this->assertSame(3, self::db()->affected_rows);
        if ($thirdpartyId > 0) { // the REST API does not set the third party of a user
            self::db()->query("UPDATE llx_user SET fk_soc = $thirdpartyId WHERE rowid = $id");
        }
        // POST /login with the password sets and returns the API key of the user.
        $url = getenv('REST_URL') . '/login';
        $raw = file_get_contents($url, false, stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json\r\nAccept: application/json", 'content' => json_encode(['login' => $login, 'password' => 'Restricted-pass-1']), 'ignore_errors' => true,
        ]]));
        $key = json_decode((string) $raw, true)['success']['token'] ?? null;
        $this->assertIsString($key, (string) $raw);

        return ['id' => $id, 'key' => $key];
    }

    private function makeContact(int $projectId, int $userId): void
    {
        self::db()->query("INSERT INTO llx_element_contact (datecreate, statut, element_id, fk_c_type_contact, fk_socpeople) SELECT NOW(), 4, $projectId, rowid, $userId FROM llx_c_type_contact WHERE element = 'project' AND source = 'internal' AND code = 'PROJECTLEADER'");
        $this->assertSame(1, self::db()->affected_rows);
    }

    public function testRestrictedUserSeesOnlyTheProjectsTheyAreOn(): void
    {
        $mine = $this->fixture();
        $other = $this->fixture();
        $user = $this->restrictedUser();
        $this->makeContact($mine['project'], $user['id']);

        $projects = $this->callTool('project_list', [], $user['key']);
        $this->assertFalse($projects['isError'], $projects['text']);
        $ids = array_column($projects['data'], 'id');
        $this->assertContains($mine['project'], $ids);
        $this->assertNotContains($other['project'], $ids);

        $entry = $this->callTool('time_entry_create', ['project_id' => $mine['project'], 'date' => '2026-11-19', 'duration' => '1h'], $user['key']);
        $this->assertFalse($entry['isError'], $entry['text']);
        $this->cleanup[] = fn () => $this->callTool('time_entry_delete', ['id' => $entry['data']['id']], $user['key']);
        $this->assertSame(
            [$entry['data']['id']],
            array_column($this->callTool('time_entry_list', ['project_id' => $mine['project']], $user['key'])['data'], 'id'),
        );

        $this->assertTrue($this->callTool('time_entry_create', ['task_id' => $other['tasks'][0], 'date' => '2026-11-19', 'duration' => '1h'], $user['key'])['isError']);
        $adminEntry = $this->log(['task_id' => $other['tasks'][0], 'date' => '2026-11-19', 'duration' => 60]);
        $this->assertTrue($this->callTool('time_entry_get', ['id' => $adminEntry['id']], $user['key'])['isError']);
    }

    public function testExternalUserSeesOnlyProjectsOfTheirThirdparty(): void
    {
        $mine = $this->fixture();
        $other = $this->fixture();
        $user = $this->restrictedUser($mine['thirdparty']);
        // Contact of both projects: only the third party restriction tells them apart.
        $this->makeContact($mine['project'], $user['id']);
        $this->makeContact($other['project'], $user['id']);

        $ids = array_column($this->callTool('project_list', [], $user['key'])['data'], 'id');
        $this->assertContains($mine['project'], $ids);
        $this->assertNotContains($other['project'], $ids);
    }
}
