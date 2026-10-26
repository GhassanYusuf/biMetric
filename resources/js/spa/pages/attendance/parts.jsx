/*
| Verify-method decoding (2026-10-11). DOCUMENTED, from the vendor itself: the
| `VerifyType` dictionary in ZKAccess 3.5.5's Languages/language_en-US.xml — the
| table ZKAccess uses to label every event, for its pull panels (inBIO460, TF1700)
| and its standalone terminals alike. See docs/protocol-overview.md.
|
| This replaces the old 0 password / 1 fingerprint / 2 card / 15 face set, which came
| from a schema comment and disagreed with the vendor on 0 and 2. Neither 0-as-
| password nor 2 occurs in this database, so no stored punch changes meaning.
|
| Checked against this estate's own data, not taken on trust: of the 76 people who
| punched with 4 ("Only Card"), 74 hold a card; of the 41 who punched with 1
| ("Only Fingerprint"), 11 do. Codes 1/3/4 were seen on the F18s (IT Room, Camelot)
| and the F22 (On The Road); 0/4/6/200 on the inBIO460 and TF1700.
|
| MUST match app/Support/VerifyMethod.php, which labels the PDF exports.
|
| A code the dictionary does not list (Camelot logged 48–51 once each) renders as
| "Unknown (n)", never as a guess: a wrong method is worse than an undecoded one.
*/

import { formatDate } from '../../shared/dates';
const VERIFY_METHODS = {
    0: 'Any',
    1: 'Fingerprint',
    2: 'Personnel ID',
    3: 'Password',
    4: 'Card',
    5: 'Fingerprint or password',
    6: 'Card or fingerprint',
    7: 'Card or password',
    8: 'Fingerprint + personnel ID',
    9: 'Fingerprint + password',
    10: 'Card + fingerprint',
    11: 'Card + password',
    12: 'Card + fingerprint + password',
    13: 'Fingerprint + personnel ID + password',
    14: 'Fingerprint + card or personnel ID',
    15: 'Face',
    16: 'Face + fingerprint',
    17: 'Face + password',
    18: 'Face + card',
    19: 'Face + fingerprint + card',
    20: 'Face + fingerprint + password',
    21: 'Finger vein',
    22: 'Finger vein + password',
    23: 'Finger vein + card',
    24: 'Finger vein + card + password',
    25: 'Palm',
    26: 'Palm + card',
    27: 'Palm + face',
    28: 'Palm + fingerprint',
    29: 'Palm + face + fingerprint',
    // The vendor's word. On the C3 panels it marks events with no credential behind
    // them — door sensor, exit button — not a person verifying.
    200: 'Other',
};

export function verifyLabel(method) {
    if (method == null) return '—';
    return VERIFY_METHODS[method] ?? `Unknown (${method})`;
}

export function formatPunch(iso) {
    if (!iso) return { date: '—', time: '' };
    const d = new Date(iso);
    return {
        date: formatDate(d),
        time: d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
    };
}

// Groups punches by calendar day for the mobile list's day headers.
export function groupByDay(rows) {
    const groups = [];
    let current = null;
    for (const row of rows) {
        const day = formatPunch(row.punched_at).date;
        if (!current || current.day !== day) {
            current = { day, rows: [] };
            groups.push(current);
        }
        current.rows.push(row);
    }
    return groups;
}
