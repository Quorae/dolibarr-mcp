<?php

declare(strict_types=1);

namespace DoliMcp\Tests\Unit;

use DoliMcp\Mcp\ToolError;
use DoliMcp\Support\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    public static function validDurations(): array
    {
        return [
            'integer minutes' => [90, 5400],
            'numeric string is minutes' => ['90', 5400],
            'hours and minutes' => ['3h30', 12600],
            'uppercase and spaces' => [' 3H30 ', 12600],
            'hours only' => ['3h', 10800],
            'minutes only' => ['45m', 2700],
            'minutes with min' => ['45min', 2700],
            'decimal hours' => ['1.5h', 5400],
            'decimal hours with comma' => ['1,5h', 5400],
            'hours and minute marker' => ['2h15m', 8100],
        ];
    }

    #[DataProvider('validDurations')]
    public function testParses(int|string $input, int $seconds): void
    {
        $this->assertSame($seconds, Duration::toSeconds($input));
    }

    public static function invalidDurations(): array
    {
        return [[0], [-5], ['abc'], [''], ['0h'], ['-1h'], ['3h75'], ['h']];
    }

    #[DataProvider('invalidDurations')]
    public function testRefuses(int|string $input): void
    {
        $this->expectException(ToolError::class);
        Duration::toSeconds($input);
    }

    public function testFormat(): void
    {
        $this->assertSame('3h30', Duration::format(12600));
        $this->assertSame('0h45', Duration::format(2700));
        $this->assertSame('2h00', Duration::format(7200));
    }
}
