/*
| The per-employee actions, as ActionMenu items — ONE list for both presentations,
| so desktop and mobile cannot drift in order or wording (rule 5). Replaced the row
| of five buttons (2026-09-23, owner's request): a strip that long pushed Delete a
| few pixels from Merge.
|
| Order is the old strip's, and the reasons for it still hold: Edit before Enroll,
| because correcting who somebody is comes before putting them on hardware; Push
| beside it, because it writes WITHOUT editing (making somebody re-save an unchanged
| record to trigger a device write teaches them to distrust Save); Merge and Delete
| last, behind a separator. Merge is not danger-toned: its destructive half is
| inside the dialog, behind a summary of exactly what moves.
*/
export function employeeRowActions(employee, { canUpdate, canEnroll, canDelete, onEdit, onPush, onEnroll, onReadFingerprints, onMerge, onDelete }) {
    return [
        canUpdate && { key: 'edit', label: 'Edit', description: 'Profile, details and access levels.', onSelect: () => onEdit(employee) },
        canEnroll && { key: 'push', label: 'Push', description: 'Write them onto terminals as they are.', onSelect: () => onPush(employee) },
        canEnroll && { key: 'enroll', label: 'Enroll', description: 'Fingerprint or card, on a terminal.', onSelect: () => onEnroll(employee) },
        // Read-only on the terminals: copies their fingerprints into the system (2026-09-24).
        canEnroll && onReadFingerprints && { key: 'read-fingers', label: 'Read fingerprints to system', description: 'Copy them from the terminals. Changes nothing there.', onSelect: () => onReadFingerprints(employee) },
        (canUpdate || canEnroll) && canDelete && { key: 'sep', separator: true },
        canUpdate && canDelete && { key: 'merge', label: 'Merge', description: 'Fold duplicate records into this one.', onSelect: () => onMerge(employee) },
        canDelete && { key: 'delete', label: 'Delete', description: 'Remove from this app. Terminals keep them.', tone: 'danger', onSelect: () => onDelete(employee) },
    ];
}
