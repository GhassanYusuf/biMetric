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
}
