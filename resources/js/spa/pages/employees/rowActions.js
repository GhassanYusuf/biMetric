/*
| The per-employee actions, as ActionMenu items — ONE list for both presentations,
| so desktop and mobile cannot drift in order or wording (rule 5). Replaced the row
| of five buttons (2026-09-23, owner's request): a strip that long pushed Delete a
| few pixels from Merge. Then replaced the Actions button itself (2026-10-05,
| owner's request): tapping anywhere on the row now drops this menu down, so the
| row is the only target and there is one less thing to aim at.
|
| Order is the old strip's, and the reasons for it still hold: Edit before Enroll,
| because correcting who somebody is comes before putting them on hardware; Push
| beside it, because it writes WITHOUT editing (making somebody re-save an unchanged
| record to trigger a device write teaches them to distrust Save); Merge and Delete
| last, behind a separator. Merge is not danger-toned: its destructive half is
| inside the dialog, behind a summary of exactly what moves.
|
| Scan (2026-10-06, owner's request) is the one item that old strip never had, so
| it has no place in that order — it goes where it does the job, immediately
| before Merge, because finding the duplicates is the first half of merging them.
*/
export function employeeRowActions(employee, { canUpdate, canEnroll, canDelete, onReport, onView, onEdit, onPush, onEnroll, onReadFingerprints, onPushFingerprints, onMerge, onDelete, onScan }) {
    return [
        // What the ROW used to do on click (2026-10-05): the row now opens this
        // menu, so the per-finger/per-terminal detail is an item in it. Read-only,
        // so it needs no permission beyond seeing the roster.
        onView && { key: 'view', label: 'Enrollment', description: 'Fingerprints, card and which terminals hold them.', onSelect: () => onView(employee) },
        onView && (canUpdate || canEnroll || canDelete || onReport) && { key: 'sep-view', separator: true },
        // Read-only, so it needs no permission beyond seeing the roster (2026-09-27).
        onReport && { key: 'report', label: 'Report', description: 'Attendance dashboard for this person.', onSelect: () => onReport(employee) },
        onReport && (canUpdate || canEnroll || canDelete) && { key: 'sep-report', separator: true },
        canUpdate && { key: 'edit', label: 'Edit', description: 'Profile, details and access levels.', onSelect: () => onEdit(employee) },
        canEnroll && { key: 'push', label: 'Push', description: 'Write them onto terminals as they are.', onSelect: () => onPush(employee) },
        canEnroll && { key: 'enroll', label: 'Enroll', description: 'Fingerprint or card, on a terminal.', onSelect: () => onEnroll(employee) },
        // Read-only on the terminals: copies their fingerprints into the system (2026-09-24).
        canEnroll && onReadFingerprints && { key: 'read-fingers', label: 'Read fingerprints to system', description: 'Copy them from the terminals. Changes nothing there.', onSelect: () => onReadFingerprints(employee) },
        // Writes stored copies to terminals they are on that lack those fingers (2026-09-24):
        // how a finger enrolled on the F18 reaches a C3 door panel. Add-only.
        canEnroll && onPushFingerprints && { key: 'push-fingers', label: 'Push fingerprints to terminals', description: 'Copy stored fingers to terminals missing them. Never overwrites.', onSelect: () => onPushFingerprints(employee) },
        // OFFERED TO EVERYONE (2026-10-06, owner's request). Noticing that the
        // roster holds two records for one person needs no permission, and this
        // item only READS — the screen behind it cannot write until the operator
        // picks a merge and the update+delete checks on that route say yes.
        // Placed beside Merge because it is the step before it: scan first, then
        // fold, rather than knowing the other record is there and hunting for it.
        onScan && { key: 'scan', label: 'Scan for duplicates', description: 'Find other records of this person.', onSelect: () => onScan(employee) },
        (canUpdate || canEnroll) && canDelete && { key: 'sep', separator: true },
        canUpdate && canDelete && { key: 'merge', label: 'Merge', description: 'Fold duplicate records into this one.', onSelect: () => onMerge(employee) },
        canDelete && { key: 'delete', label: 'Delete', description: 'Remove from this app. Terminals keep them.', tone: 'danger', onSelect: () => onDelete(employee) },
    ];
}
