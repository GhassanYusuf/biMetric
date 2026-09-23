import { Badge } from '../../shared/ui';

/*
| Rule 9: the UI must HIDE an action the device cannot do. A button that returns
| 502 teaches operators to distrust the whole screen.
|
| The API already sends a `usable` flag per capability — confirmed OR implemented,
| which is exactly "offer it" — so this reads that rather than guessing from the
| protocol. A device whose capabilities have never loaded returns false: hiding an
| action that might work is recoverable, offering one that cannot is not.
|
| `Test` is deliberately NOT gated. Reachability is how you find out what a device
| can do, so it must stay available on a device we know nothing about.
*/
export function can(device, capability) {
    return device?.capabilities?.[capability]?.usable === true;
}

export const PROTOCOL_LABELS = {
    binary: 'Binary (UDP/TCP 4370)',
    csl_http: 'CSL HTTP',
    c3: 'C3 / PullSDK',
};

// Sync status → badge. `null` means the device has never been synced, which is
// distinct from a failure and must not read as one.
export function SyncBadge({ status }) {
    if (status === 'success') return <Badge tone="emerald">Synced</Badge>;
    if (status === 'error') return <Badge tone="rose">Error</Badge>;
    return <Badge tone="slate">Never synced</Badge>;
}

export function relativeTime(iso) {
    if (!iso) return 'never';
    const then = new Date(iso);
    const mins = Math.round((Date.now() - then.getTime()) / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.round(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;
    return then.toLocaleDateString();
}

/**
 * The confirmation shown before a full re-read, wherever it is offered.
 *
 * Lives here because two screens fire it — the devices list and device detail —
 * and they must warn about the same thing in the same words. When this copy was
 * duplicated it was already the kind of text that quietly drifts.
 *
 * Deliberately NOT `danger`. A full re-read is slow, not destructive: the unique
 * punch index means it can add rows but never duplicate or delete them. Painting
 * it red would teach operators to fear the recovery tool they are meant to reach
 * for after a decoder fix.
 */
export const FULL_RESYNC_CONFIRM = {
    title: 'Re-read the entire attendance log?',
    body: [
        'This ignores the sync watermark and reads every record the terminal still holds, which can be tens of thousands.',
        'Existing punches are recognised and skipped, so nothing is duplicated — but it takes considerably longer than a normal sync.',
    ],
    confirmLabel: 'Read everything',
};

/**
 * How a finished run describes what it was ASKED for.
 *
 * `incremental` and `full` say everything about themselves; `range` does not — two
 * range runs minutes apart can cover different months, and "read 9,579, imported
 * 640" is only legible next to the window it was bounded to. The dates come back
 * from the API as plain Y-m-d because they are the terminal's own calendar days
 * (see AttendanceSyncRunResource); they are shown as typed, NOT reformatted through
 * the browser's timezone, which would move them by a day.
 */
export function describeRunMode(run) {
    if (run?.mode !== 'range') return run?.mode ?? '';

    if (run.range_from && run.range_to) return `range · ${run.range_from} → ${run.range_to}`;
    if (run.range_from) return `range · from ${run.range_from}`;
    if (run.range_to) return `range · up to ${run.range_to}`;

    return 'range';
}

/**
 * Whether this terminal will accept a write, as a badge.
 *
 * It was invisible until today: nothing in the devices payload said a device was
 * read-only, so every screen offered pushes the hardware would refuse and the only
 * way to find out was to try. Rule 9 — an action that cannot work is hidden, and a
 * state that blocks actions is shown.
 *
 * `adopted` deliberately renders NOTHING. It is the normal state, and a green badge
 * on every row would make the two that matter harder to see, not easier.
 */
export function AdoptionBadge({ device }) {
    if (device?.is_writable) return null;

    const pending = device?.unclaimed_roster_entries ?? 0;

    return (
        <Badge tone="amber" title={device?.write_refusal ?? undefined}>
            {pending > 0 ? `${pending} to claim` : 'Read-only'}
        </Badge>
    );
}

/**
 * The confirmation shown before adopting, wherever it is offered.
 *
 * Shared for the same reason FULL_RESYNC_CONFIRM is: two screens fire it and they
 * must warn about the same thing in the same words.
 *
 * NOT `danger`. Adoption destroys nothing — it permits writes that were refused, and
 * it is refused itself while anything on the terminal is undecided. Painting it red
 * would teach operators to fear the step that makes the device usable.
 */
export function adoptConfirm(device) {
    return {
        title: `Allow writes to ${device.name}?`,
        body: [
            'Its roster has been read and every entry on it has been claimed or ignored, so this app knows who is already enrolled on the hardware.',
            'From now on this terminal will accept employee pushes and enrollments. Nothing is written to it by this action itself.',
        ],
        confirmLabel: 'Allow writes',
    };
}
