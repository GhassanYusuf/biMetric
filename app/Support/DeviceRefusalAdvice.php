<?php

namespace App\Support;

use Promoseven\Biometric\Exception\DeviceException;

/**
 * What an operator can do about a terminal's refusal, added after the library's own
 * wording ("Device rejected the user write (4995 Error inserting database).").
 *
 * 4994 "Error updating database" and 4995 "Error inserting database": Camelot (F18)
 * refused every user write that way three times, with its data reading normally, until
 * it was restarted (casebook DC-029, DC-034). The library puts the raw reply in the
 * exception's context (2.20.0); the advice is keyed on that, never on the text.
 */
final class DeviceRefusalAdvice
{
    private const RESTART_CLEARS = [4994, 4995];

    public static function for(\Throwable $e): ?string
    {
        if (! $e instanceof DeviceException) {
            return null;
        }

        $reply = $e->context()['reply'] ?? null;

        return in_array($reply, self::RESTART_CLEARS, true)
            ? 'The terminal is refusing changes to its own database. Restart it (Devices → Restart terminal), then try again.'
            : null;
    }

    /** The exception's message with the advice after it, when there is any. */
    public static function message(\Throwable $e): string
    {
        $advice = self::for($e);

        return $advice === null ? $e->getMessage() : rtrim($e->getMessage()).' '.$advice;
    }

    /**
     * The user number the terminal itself refused as already taken (library 2.22.0, context
     * reason pin_in_use), or null when the failure is anything else. Casebook DC-038.
     */
    public static function numberInUse(\Throwable $e): ?string
    {
        // The literal, not ProtocolException::PIN_IN_USE: the constant exists from library
        // 2.22.0, and this must not fail where an older one is installed.
        if (! $e instanceof DeviceException || ($e->context()['reason'] ?? null) !== 'pin_in_use') {
            return null;
        }
        $pin = (string) ($e->context()['pin'] ?? '');

        return $pin === '' ? null : $pin;
    }
}
