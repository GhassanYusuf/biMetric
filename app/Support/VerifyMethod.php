<?php

namespace App\Support;

/**
 * How a punch was verified, as a label.
 *
 * The documented set — matching the attendance_logs schema comment and the SPA's
 * resources/js/spa/pages/attendance/parts.jsx — is 0 password, 1 fingerprint,
 * 2 card, 15 face.
 *
 * CAUTION: this database also holds methods 3 and 4, which are NOT in that set;
 * some ZKTeco firmware numbers verify modes differently. Those render as the raw
 * number rather than a guessed label, because mislabelling how somebody proved
 * who they were is worse than showing it undecoded. Resolve the real mapping
 * against the hardware before adding entries here, and change parts.jsx in the
 * same commit so a PDF and the screen never disagree.
 */
final class VerifyMethod
{
    private const LABELS = [
        0 => 'Password',
        1 => 'Fingerprint',
        2 => 'Card',
        15 => 'Face',
    ];

    public static function label(?int $method): string
    {
        if ($method === null) {
            return '—';
        }

        return self::LABELS[$method] ?? (string) $method;
    }
}
