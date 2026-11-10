<?php

namespace ErnestDefoe\Mural;

/**
 * Colours a season paints with. A tile stores an index into its season's
 * palette, so a palette is fixed once a season starts; index 0 is the
 * blank canvas.
 */
final class Palette
{
    public const MAX = 32;
    public const MIN = 2;

    /** Our own 32: five neutrals, then warm to cool, then earth tones. */
    public const MURAL_32 = [
        '#ffffff', '#c9ccd1', '#8b8f97', '#4b4f58', '#1d1f24',
        '#7a1631', '#d7263d', '#ff6b6b', '#ffb3c1', '#e0457b',
        '#f46036', '#ff9f1c', '#ffd23f', '#fff3b0',
        '#1b5e20', '#2e9e44', '#8fd14f', '#c8f7c5',
        '#0b7a75', '#17bebb', '#9be7f0',
        '#14213d', '#2c5eda', '#4ea5f7', '#b8d8ff',
        '#4b2a8f', '#7c3aed', '#b892ff', '#f0c2ff',
        '#5c3a21', '#a8693a', '#f2c79b',
    ];

    public const MURAL_16 = [
        '#ffffff', '#c9ccd1', '#8b8f97', '#1d1f24',
        '#d7263d', '#ff6b6b', '#f46036', '#ffd23f',
        '#2e9e44', '#8fd14f', '#0b7a75', '#2c5eda',
        '#4ea5f7', '#7c3aed', '#e0457b', '#5c3a21',
    ];

    public const PASTEL_16 = [
        '#fffdf7', '#e6e1d8', '#a59e94', '#4a4540',
        '#ffadad', '#ffd6a5', '#fdffb6', '#caffbf',
        '#9bf6ff', '#a0c4ff', '#bdb2ff', '#ffc6ff',
        '#e07a5f', '#81b29a', '#3d405b', '#f2cc8f',
    ];

    public const PRESETS = [
        'mural32' => self::MURAL_32,
        'mural16' => self::MURAL_16,
        'pastel16' => self::PASTEL_16,
    ];

    /**
     * A preset name or a list of hex colours (comma, space or newline
     * separated) as a clean list, or the default when nothing usable is left.
     *
     * @return list<string>
     */
    public static function parse(?string $value): array
    {
        $value = trim((string) $value);
        if ($value === '' || isset(self::PRESETS[$value])) {
            return self::PRESETS[$value ?: 'mural32'];
        }

        preg_match_all('/#?\b([0-9a-f]{6}|[0-9a-f]{3})\b/i', $value, $matches);
        $colours = [];
        foreach ($matches[1] as $hex) {
            $hex = strtolower(strlen($hex) === 3 ? $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2] : $hex);
            $colours['#'.$hex] = true;
        }
        $colours = array_slice(array_keys($colours), 0, self::MAX);

        return count($colours) >= self::MIN ? $colours : self::MURAL_32;
    }

    /**
     * @param list<string> $palette
     * @return list<array{int, int, int}>
     */
    public static function rgb(array $palette): array
    {
        return array_map(fn (string $hex) => [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))], $palette);
    }
}
