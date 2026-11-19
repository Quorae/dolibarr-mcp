<?php

declare(strict_types=1);

namespace DoliMcp\Tests\Integration;

final class ModuleTest extends McpTestCase
{
    public function testModuleIsEnabledThroughDolibarr(): void
    {
        $response = $this->rest('GET', 'setup/modules');

        $this->assertSame(200, $response['status']);
        $this->assertContains('dolimcp', $response['body']);
    }

    public function testJournalTableExists(): void
    {
        $result = self::db()->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'llx_dolimcp_journal'");

        $this->assertSame(1, $result->num_rows);
    }
}
