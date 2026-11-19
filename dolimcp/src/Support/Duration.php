<?php

declare(strict_types=1);

namespace DoliMcp\Support;

use DoliMcp\Mcp\ToolError;

/** Time durations typed by humans: minutes as an integer, or text such as 3h30, 2h, 45m, 1.5h. */
final class Duration
{
    private const HELP = 'Use minutes (90) or text such as 3h30, 2h, 45m, 1.5h.';

    public static function toSeconds(int|string $value): int
    {
        $text = strtolower(trim((string) $value));
        $seconds = match (true) {
            preg_match('/^\d+$/', $text) === 1 => (int) $text * 60,
            preg_match('/^(\d+)h(?:(\d{1,2})(?:m|min)?)?$/', $text, $m) === 1 && (int) ($m[2] ?? 0) < 60 => (int) $m[1] * 3600 + (int) ($m[2] ?? 0) * 60,
            preg_match('/^(\d+)(?:m|min)$/', $text, $m) === 1 => (int) $m[1] * 60,
            preg_match('/^(\d+(?:[.,]\d+)?)h$/', $text, $m) === 1 => (int) round((float) str_replace(',', '.', $m[1]) * 3600),
            default => throw new ToolError('The duration "' . $value . '" is not valid. ' . self::HELP),
        };
        if ($seconds <= 0) {
            throw new ToolError('The duration must be greater than zero. ' . self::HELP);
        }

        return $seconds;
    }

    public static function format(int $seconds): string
    {
        $minutes = intdiv(abs($seconds), 60);

        return sprintf('%dh%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
