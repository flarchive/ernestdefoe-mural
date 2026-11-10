<?php

namespace ErnestDefoe\Mural;

/**
 * A palette PNG from one byte per pixel, with nothing but zlib: no GD or
 * Imagick needed, and an 8-bit indexed image is what a canvas is anyway.
 */
final class Png
{
    /**
     * @param list<array{int, int, int}> $rgb
     */
    public static function encode(int $width, int $height, array $rgb, string $pixels, int $scale = 1): string
    {
        $scale = max(1, $scale);
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $row = substr($pixels, $y * $width, $width);
            if ($scale > 1) {
                $wide = '';
                foreach (str_split($row) as $byte) {
                    $wide .= str_repeat($byte, $scale);
                }
                $row = $wide;
            }
            $raw .= str_repeat("\0".$row, $scale);
        }

        $palette = '';
        foreach ($rgb as [$r, $g, $b]) {
            $palette .= chr($r).chr($g).chr($b);
        }

        return "\x89PNG\r\n\x1a\n"
            .self::chunk('IHDR', pack('NNCCCCC', $width * $scale, $height * $scale, 8, 3, 0, 0, 0))
            .self::chunk('PLTE', $palette)
            .self::chunk('IDAT', (string) gzcompress($raw, 9))
            .self::chunk('IEND', '');
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
