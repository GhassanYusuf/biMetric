import { Badge } from '../../shared/ui';

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
