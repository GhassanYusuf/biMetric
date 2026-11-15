<?php

namespace App\Support;

/**
 * Makes text safe to store when it may carry bytes straight off a terminal.
 *
 * Casebook DC-036 (2026-10-29): an exception message quoting misaligned bytes from Camelot
 * could not be JSON-encoded into `device_commands.error`, so the failure itself was lost,
 * the command read "job lost" and its sync run stayed "running" for ever. Invalid UTF-8 is
 * replaced with "?"; valid text is returned unchanged.
 */
final class Utf8
{
    /**
     * @template T
     *
     * @param  T  $value  a string, or an array of them at any depth; anything else as is
     * @return T
     */
    public static function scrub(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? $value : mb_scrub($value, 'UTF-8');
        }

        if (is_array($value)) {
            return array_map(self::scrub(...), $value);
        }

        return $value;
    }

    /**
     * A name typed on a terminal, made storable (casebook DC-046, 2026-11-15). Valid UTF-8 comes
     * back unchanged. Otherwise the bytes are read as Windows-1252, the code page these firmwares
     * keep names in (a lone 0xA0 is a no-break space), and any run of spacing becomes one space.
     * "DIRISAM<0xA0>PRADEEP" on gems workshop stopped its whole roster read: SQL Server refused it.
     */
    public static function name(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $text = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($value, 'UTF-8');
        }

        return trim(preg_replace('/[\s\x{00A0}\x{2000}-\x{200B}]+/u', ' ', $text) ?? $text);
    }
}
