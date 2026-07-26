import { Badge } from '../../shared/ui';

export const PROTOCOL_LABELS = {
    binary: 'Binary (UDP/TCP 4370)',
    csl_http: 'CSL HTTP',
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
