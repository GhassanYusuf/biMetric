<?php

namespace App\Support;

/**
 * How a punch was verified, as a label.
 *
 * DOCUMENTED (2026-10-11), from the vendor: the `VerifyType` dictionary in ZKAccess
 * 3.5.5's Languages/language_en-US.xml, the table ZKAccess itself labels events with.
 * Cross-checked on this estate's data — 74 of 76 people with code-4 ("card") punches
 * hold a card. Full evidence and the codes each terminal emits are in
 * docs/protocol-overview.md.
 *
 * MUST match resources/js/spa/pages/attendance/parts.jsx, so a PDF and the screen
 * never disagree. A code not listed renders "Unknown (n)", never a guess.
 */
final class VerifyMethod
{
    private const LABELS = [
        0 => 'Any',
        1 => 'Fingerprint',
        2 => 'Personnel ID',
        3 => 'Password',
        4 => 'Card',
        5 => 'Fingerprint or password',
        6 => 'Card or fingerprint',
        7 => 'Card or password',
        8 => 'Fingerprint + personnel ID',
        9 => 'Fingerprint + password',
        10 => 'Card + fingerprint',
        11 => 'Card + password',
        12 => 'Card + fingerprint + password',
        13 => 'Fingerprint + personnel ID + password',
        14 => 'Fingerprint + card or personnel ID',
        15 => 'Face',
        16 => 'Face + fingerprint',
        17 => 'Face + password',
        18 => 'Face + card',
        19 => 'Face + fingerprint + card',
        20 => 'Face + fingerprint + password',
        21 => 'Finger vein',
        22 => 'Finger vein + password',
        23 => 'Finger vein + card',
        24 => 'Finger vein + card + password',
        25 => 'Palm',
        26 => 'Palm + card',
        27 => 'Palm + face',
        28 => 'Palm + fingerprint',
        29 => 'Palm + face + fingerprint',
        200 => 'Other',
    ];

    public static function label(?int $method): string
    {
        if ($method === null) {
            return '—';
        }

        return self::LABELS[$method] ?? "Unknown ({$method})";
    }
}
