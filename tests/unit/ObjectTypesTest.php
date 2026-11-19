<?php

declare(strict_types=1);

namespace DoliMcp\Tests\Unit;

use DoliMcp\Mcp\ToolError;
use DoliMcp\Tools\ObjectTypes;
use PHPUnit\Framework\TestCase;

final class ObjectTypesTest extends TestCase
{
    public function testStatusLabels(): void
    {
        $this->assertSame('draft', ObjectTypes::statusLabel('invoice', 0));
        $this->assertSame('paid', ObjectTypes::statusLabel('invoice', 2));
        $this->assertSame('billed', ObjectTypes::statusLabel('proposal', 4));
        $this->assertSame('closed', ObjectTypes::statusLabel('project', 2));
        $this->assertNull(ObjectTypes::statusLabel('thirdparty', 1));
    }

    public function testPresentLabelsObjectsAndLists(): void
    {
        $this->assertSame(['status' => '1', 'status_label' => 'unpaid'], ObjectTypes::present('invoice', ['status' => '1', 'x' => '']));
        $this->assertSame([['status' => 0, 'status_label' => 'draft']], ObjectTypes::present('invoice', [['status' => 0]]));
        $this->assertSame(['status' => 1], ObjectTypes::present('thirdparty', ['status' => 1]));
    }

    public function testCreatedIdAcceptsPositiveIntegersOnly(): void
    {
        $this->assertSame(12, ObjectTypes::createdId('invoice', 12));
        $this->assertSame(12, ObjectTypes::createdId('invoice', '12'));
        foreach ([0, '0', '', 'abc', [], ['id' => 1], null, -3] as $bad) {
            try {
                ObjectTypes::createdId('invoice', $bad);
                $this->fail('Expected a ToolError for ' . json_encode($bad));
            } catch (ToolError $e) {
                $this->assertStringContainsString('did not return the new invoice id', $e->getMessage());
            }
        }
    }
}
