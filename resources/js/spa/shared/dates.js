/*
| Dates are shown and typed as dd/mm/yyyy everywhere (owner, 2026-10-26), whatever
| language the viewer's browser is set to. The browser's own <input type="date"> cannot
| do that: it displays in the browser's locale (mm/dd/yyyy on an English-US machine) and
| a page cannot override it. So every date field is DateField (shared/ui.jsx), and every
| numeric date on screen goes through formatDate() here.
|
| Values sent to and received from the API do NOT change: always ISO yyyy-mm-dd. Only
| what a person sees and types is dd/mm/yyyy.
*/

const pad = (n) => String(n).padStart(2, '0');

/** "2026-10-26" → "26/10/2026"; anything else → "". */
export function isoToDmy(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso ?? '');
    return m ? `${m[3]}/${m[2]}/${m[1]}` : '';
}

/** "26/10/2026" → "2026-10-26", or null when it is not a real calendar date. */
export function dmyToIso(text) {
    const m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(text ?? '');
    if (!m) return null;
    const [day, month, year] = [Number(m[1]), Number(m[2]), Number(m[3])];
    const d = new Date(year, month - 1, day);
    // 31/02/2026 rolls over to 03/03 in Date; that is a typo, not a date.
    if (d.getFullYear() !== year || d.getMonth() !== month - 1 || d.getDate() !== day) return null;
    return `${m[3]}-${m[2]}-${m[1]}`;
}

/**
 * Typing mask: keeps up to 8 digits and puts the slashes in, so "26102026" and
 * "26/10/2026" both become "26/10/2026" and a phone shows the number pad.
 */
export function maskDmy(text) {
    const digits = (text ?? '').replace(/\D/g, '').slice(0, 8);
    if (digits.length <= 2) return digits;
    if (digits.length <= 4) return `${digits.slice(0, 2)}/${digits.slice(2)}`;
    return `${digits.slice(0, 2)}/${digits.slice(2, 4)}/${digits.slice(4)}`;
}

/** A Date (or ISO timestamp) as dd/mm/yyyy in the viewer's local time. */
export function formatDate(value) {
    const d = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(d.getTime())) return '—';
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
}

/** dd/mm/yyyy and the time, for "read at"-style stamps. */
export function formatDateTime(value) {
    const d = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(d.getTime())) return '—';
    return `${formatDate(d)} ${d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
}
