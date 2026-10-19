import { useEffect, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { FOCUS } from '../../shared/theme';
import { Badge, Button, Card, EmptyState, InlineAlert, Spinner } from '../../shared/ui';
import { useEscapeKey } from '../../shared/useEscapeKey';

/*
| Scans the roster for duplicate records and offers the merge.
|
| WHY THIS EXISTS ALONGSIDE MergeModal. MergeModal asks you to KNOW who the other
| record is and go and find it. This does the finding: it reads the records, says
| what evidence there is, and lets you confirm. The two answer different
| questions — "I know these two are the same person" versus "are these the same
| person?" — and only the second one needs a scan.
|
| THE NON-LOSSY PROMISE. Nothing here invents information, and nothing is
| silently dropped. Before you confirm, the preview lists every profile field
| the folded records hold that the survivor is missing, and every field where
| the two disagree — the survivor's value is kept and the other one is written
| to the audit log rather than vanishing with the row. Punches and terminal
| mappings move in full either way; no terminal is contacted at any point.
|
| A TIER IS EVIDENCE, NOT A PROBABILITY. "Needs a look" means the system can
| only see a resemblance — a shared name, a shared phone — and it is asking
| because it cannot know. That is why merging from that tier requires ticking
| that you recognise both people.
*/
/*
 * Whether this group may be folded from the screen, and why.
 *
 * Extracted from the component because it is the safety rule rather than a bit
 * of layout: resemblance-only evidence is mergeable only once the operator has
 * said out loud that they recognise both records, contradictory evidence never
 * is, and nothing merges until its preview has come back — the screen's whole
 * job is to say what the fold does before it happens.
 *
 * Exported so the rule can be asserted directly; there is no browser in CI.
 */
export function canMergeGroup(group, { acknowledged = false, previewReady = false } = {}) {
    if (!group || group.tier === 'do_not_merge') return false;
    if (!previewReady) return false;

    return group.tier === 'strong' || acknowledged === true;
}

export default function DuplicateScanModal({ employee = null, onMerged, onClose }) {
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [groups, setGroups] = useState([]);
    const [counts, setCounts] = useState(null);

    const [openId, setOpenId] = useState(null);
    const [keepers, setKeepers] = useState({}); // group id => employee id
    const [previews, setPreviews] = useState({}); // group id => preview payload
    const [previewErrors, setPreviewErrors] = useState({}); // group id => bool
    const [acknowledged, setAcknowledged] = useState({}); // group id => bool
    const [busyId, setBusyId] = useState(null);
    const [mergeError, setMergeError] = useState(null);

    useEscapeKey(onClose, busyId === null);

    useEffect(() => {
        const url = employee ? `/employees/${employee.id}/duplicates` : '/employees/duplicate-groups';

        api.get(url)
            .then((res) => {
                setGroups(res.data?.groups ?? []);
                setCounts(res.data?.counts ?? null);
            })
            .catch((err) => setError(err instanceof ApiError ? err.message : 'The scan could not be run.'))
            .finally(() => setLoading(false));
    }, [employee?.id]);

    // The preview depends on which record survives, so it is re-read whenever
    // that choice changes — a stale one would promise the wrong fields.
    useEffect(() => {
        if (!openId) return undefined;

        const group = groups.find((g) => g.id === openId);
        if (!group) return undefined;

        const keepId = keepers[openId] ?? group.keep;
        const mergeIds = group.records.filter((r) => r.id !== keepId).map((r) => r.id);

        if (mergeIds.length === 0) {
            setPreviews((p) => ({ ...p, [openId]: null }));
            return undefined;
        }

        let cancelled = false;
        setPreviewErrors((e) => ({ ...e, [openId]: false }));
        api.post('/employees/merge/preview', { keep_id: keepId, merge_ids: mergeIds })
            .then((res) => {
                if (!cancelled) setPreviews((p) => ({ ...p, [openId]: res.data }));
            })
            .catch(() => {
                if (cancelled) return;
                /*
                 * A preview that failed must not read as "nothing would change".
                 * That is a promise about the merge, made by a network error, and
                 * the operator would confirm believing nothing was at stake.
                 */
                setPreviews((p) => ({ ...p, [openId]: null }));
                setPreviewErrors((e) => ({ ...e, [openId]: true }));
            });

        return () => { cancelled = true; };
    }, [openId, keepers, groups]);

    const TIER_META = {
        strong: { label: 'Confident', tone: 'emerald' },
        possible: { label: 'Needs a look', tone: 'amber' },
        do_not_merge: { label: 'Do not merge', tone: 'rose' },
    };

    function toggle(id) {
        setMergeError(null);
        setOpenId((cur) => (cur === id ? null : id));
    }

    function keep(group, id) {
        setKeepers((prev) => ({ ...prev, [group.id]: id }));
        setMergeError(null);
    }

    async function merge(group) {
        const keepId = keepers[group.id] ?? group.keep;
        const mergeIds = group.records.filter((r) => r.id !== keepId).map((r) => r.id);

        setBusyId(group.id);
        setMergeError(null);

        try {
            const res = await api.post('/employees/merge', { keep_id: keepId, merge_ids: mergeIds });
            onMerged(res.message);
        } catch (err) {
            setMergeError(err instanceof ApiError ? err.message : 'The merge could not be completed.');
        } finally {
            setBusyId(null);
        }
    }

    /*
     * The preview has to have come back AND succeeded before the merge is
     * offered — see canMergeGroup(). A failed preview that still enabled the
     * button would be a promise about the merge made by a network error.
     */
    const canMerge = (group) => canMergeGroup(group, {
        acknowledged: acknowledged[group.id] === true,
        previewReady: previews[group.id] !== undefined && previewErrors[group.id] !== true,
    });

    const section = (tier) => groups.filter((g) => g.tier === tier);

    return (
        /*
         * SCROLL SHAPE — a scan of the live roster is TALL (23 groups was an
         * ordinary answer when this was written), so the layout is not cosmetic.
         *
         * The dialog used to be centred INSIDE the scrolling element. With
         * `items-center` on `overflow-y-auto`, content taller than the window
         * overflows equally top and bottom, and the part above the fold is then
         * unreachable: the modal grows past the screen and nothing scrolls to
         * it. So the OUTER element is the scroller, the wrapper only centres a
         * dialog capped at the viewport, and the results list is the part that
         * actually scrolls — which keeps the heading and Close button on screen
         * the whole time, so the way out never scrolls away either.
         */
        <div
            className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/40"
            onClick={busyId === null ? onClose : undefined}
            role="presentation"
        >
            <div className="flex min-h-full items-end justify-center p-0 sm:items-center sm:p-4">
                <Card
                    className="w-full max-w-3xl overflow-hidden rounded-none sm:rounded-2xl"
                    onClick={(e) => e.stopPropagation()}
                >
                    <div className="flex max-h-screen flex-col sm:max-h-[calc(100vh-2rem)]">
                        <div className="shrink-0 p-5 pb-0">
                            <div className="flex items-start justify-between gap-4">
                                <div className="min-w-0">
                                    <h2 className="text-lg font-semibold tracking-[-0.01em] text-slate-900">
                                        {employee ? `Scan for duplicates of ${employee.name}` : 'Scan for duplicates'}
                                    </h2>
                                    <p className="mt-1 text-sm text-slate-600">
                                        {employee
                                            ? 'Other records that may be this same person, with the evidence for each.'
                                            : 'Records that may be the same person listed twice. Every group shows what it was matched on.'}
                                    </p>
                                </div>
                                <Button type="button" variant="secondary" onClick={onClose} disabled={busyId !== null}>Close</Button>
                            </div>

                            {counts && counts.total > 0 && (
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <Badge tone="emerald">{counts.strong} confident</Badge>
                                    <Badge tone="amber">{counts.possible} need a look</Badge>
                                    {counts.do_not_merge > 0 && <Badge tone="rose">{counts.do_not_merge} do not merge</Badge>}
                                </div>
                            )}
                        </div>

                        <div className="min-h-0 flex-1 overflow-y-auto p-5">
                            {loading && (
                                <div className="flex items-center gap-2 text-sm text-slate-500">
                                    <Spinner className="h-4 w-4" /> scanning the roster…
                                </div>
                            )}

                            {!loading && error && (
                                <div className="mt-4">
                                    <InlineAlert tone="error">{error}</InlineAlert>
                                </div>
                            )}

                            {!loading && !error && groups.length === 0 && (
                                <div className="mt-4">
                                    <EmptyState
                                        title={employee ? `No duplicates of ${employee.name}` : 'No duplicates found'}
                                        hint="Nothing in these records points at the same person twice."
                                    />
                                </div>
                            )}

                            {!loading && !error && groups.length > 0 && (
                                <div className="space-y-5">
                                    {['strong', 'possible', 'do_not_merge'].map((tier) => {
                                        const items = section(tier);
                                        if (items.length === 0) return null;
                                        const meta = TIER_META[tier];

                                        return (
                                            <section key={tier}>
                                                <h3 className="text-xs font-semibold uppercase tracking-[0.06em] text-slate-500">
                                                    {meta.label}
                                                    <span className="ml-1.5 font-normal normal-case tracking-normal text-slate-400">
                                                        {tier === 'strong' && 'matched on an identifier'}
                                                        {tier === 'possible' && 'a resemblance only — confirm you recognise both'}
                                                        {tier === 'do_not_merge' && 'the evidence points away'}
                                                    </span>
                                                </h3>

                                                <ul className="mt-2 space-y-2">
                                                    {items.map((group) => (
                                                        <GroupCard
                                                            key={group.id}
                                                            group={group}
                                                            meta={meta}
                                                            open={openId === group.id}
                                                            onToggle={() => toggle(group.id)}
                                                            keepId={keepers[group.id] ?? group.keep}
                                                            onKeep={(id) => keep(group, id)}
                                                            preview={previews[group.id]}
                                                            previewError={previewErrors[group.id] === true}
                                                            acknowledged={acknowledged[group.id] === true}
                                                            onAcknowledge={(v) => setAcknowledged((prev) => ({ ...prev, [group.id]: v }))}
                                                            canMerge={canMerge(group)}
                                                            busy={busyId === group.id}
                                                            onMerge={() => merge(group)}
                                                        />
                                                    ))}
                                                </ul>
                                            </section>
                                        );
                                    })}

                                    {mergeError && (
                                        <InlineAlert tone="error">{mergeError}</InlineAlert>
                                    )}

                                    <p className="text-xs text-slate-500">
                                        No terminal is contacted by a scan or a merge. Fingerprints, cards and PINs stay
                                        exactly where they are — only which record each terminal mapping points at changes.
                                    </p>
                                </div>
                            )}
                        </div>
                    </div>
                </Card>
            </div>
        </div>
    );
}

function GroupCard({
    group, meta, open, onToggle, keepId, onKeep, preview, previewError,
    acknowledged, onAcknowledge, canMerge, busy, onMerge,
}) {
    const folding = group.records.filter((r) => r.id !== keepId);
    const survivor = group.records.find((r) => r.id === keepId) ?? group.records[0];
    const movingPunches = folding.reduce((n, r) => n + (r.punches ?? 0), 0);
    const movingTerminals = folding.reduce((n, r) => n + (r.terminals?.length ?? 0), 0);

    return (
        <li className="rounded-xl ring-1 ring-slate-200 bg-white">
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={open}
                className={`flex w-full items-start gap-3 rounded-xl px-4 py-3 text-left transition-colors hover:bg-slate-50 ${FOCUS}`}
            >
                <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-semibold text-slate-900">{group.name}</span>
                        <Badge tone={meta.tone}>{meta.label}</Badge>
                        <span className="text-xs text-slate-500">
                            {group.records.length} record{group.records.length === 1 ? '' : 's'} ·{' '}
                            {group.punches.toLocaleString()} punches
                        </span>
                    </span>
                    <span className="mt-1 block text-xs text-slate-500">
                        {group.signals.join(' · ')}
                    </span>
                </span>
                <svg
                    className={`mt-1 h-4 w-4 shrink-0 text-slate-400 transition-transform ${open ? 'rotate-180' : ''}`}
                    viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"
                >
                    <path d="M4 6l4 4 4-4" />
                </svg>
            </button>

            {open && (
                <div className="border-t border-slate-100 px-4 py-4">
                    <p className="text-xs font-medium text-slate-500">Records in this group</p>
                    <ul className="mt-2 space-y-1.5">
                        {group.records.map((r) => (
                            <li key={r.id}>
                                <label className="flex items-start gap-2.5">
                                    <input
                                        type="radio"
                                        name={`keep-${group.id}`}
                                        checked={keepId === r.id}
                                        onChange={() => onKeep(r.id)}
                                        className="mt-0.5 h-4 w-4 border-slate-300 text-violet-600"
                                    />
                                    <span className="min-w-0 text-sm text-slate-700">
                                        {r.name} <span className="text-slate-500">· {r.employee_code}</span>
                                        <span className="block text-xs text-slate-500">
                                            #{r.id} · {r.punches.toLocaleString()} punches
                                            {r.terminals?.length > 0 && ` · ${r.terminals.join(', ')}`}
                                        </span>
                                    </span>
                                </label>
                            </li>
                        ))}
                    </ul>

                    <div className="mt-4 rounded-lg bg-slate-50 p-3">
                        <p className="text-xs font-semibold text-slate-700">
                            What combining them does
                        </p>

                        {preview === undefined && (
                            <p className="mt-2 flex items-center gap-2 text-xs text-slate-500">
                                <Spinner className="h-3.5 w-3.5" /> working out what would change…
                            </p>
                        )}

                        {previewError && !preview && (
                            <div className="mt-2">
                                <InlineAlert tone="error">
                                    What this merge would change could not be read. Nothing has been merged —
                                    try opening it again.
                                </InlineAlert>
                            </div>
                        )}

                        {!previewError && preview === null && (
                            <p className="mt-2 text-xs text-slate-500">
                                Nothing to bring across — these records hold no profile information that differs.
                            </p>
                        )}

                        {preview && (
                            <div className="mt-2 space-y-2 text-xs text-slate-600">
                                <p>
                                    {movingPunches.toLocaleString()} punch{movingPunches === 1 ? '' : 'es'} and{' '}
                                    {movingTerminals} terminal mapping{movingTerminals === 1 ? '' : 's'} move to{' '}
                                    <span className="font-semibold text-slate-800">{survivor.name}</span>.
                                </p>

                                {preview.filled?.length > 0 && (
                                    <div>
                                        <p className="font-semibold text-slate-700">Brought across from the other record:</p>
                                        <ul className="mt-1 space-y-0.5">
                                            {preview.filled.map((f) => (
                                                <li key={f.field}>
                                                    {f.label}: <span className="font-medium text-slate-800">{String(f.value)}</span>
                                                    <span className="text-slate-400"> · from #{f.from_id} {f.from_name}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}

                                {preview.conflicts?.length > 0 && (
                                    <div>
                                        <p className="font-semibold text-slate-700">
                                            Differing values — the record you keep wins, the other is kept in the audit log:
                                        </p>
                                        <ul className="mt-1 space-y-0.5">
                                            {preview.conflicts.map((c) => (
                                                <li key={`${c.field}-${c.other_id}`}>
                                                    {c.label}: keeping <span className="font-medium text-slate-800">{String(c.keep)}</span>,
                                                    dropping <span className="font-medium text-slate-800">{String(c.other)}</span>
                                                    <span className="text-slate-400"> · #{c.other_id} {c.other_name}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}

                                {preview.filled?.length === 0 && preview.conflicts?.length === 0 && (
                                    <p className="text-slate-500">
                                        No profile fields change — only the punches and terminal mappings move.
                                    </p>
                                )}
                            </div>
                        )}
                    </div>

                    {group.tier === 'possible' && (
                        <label className="mt-3 flex items-start gap-2.5">
                            <input
                                type="checkbox"
                                checked={acknowledged}
                                onChange={(e) => onAcknowledge(e.target.checked)}
                                className="mt-0.5 h-4 w-4 rounded border-slate-300 text-violet-600"
                            />
                            <span className="text-xs text-slate-600">
                                I recognise these as the same person. This evidence on its own does not prove it.
                            </span>
                        </label>
                    )}

                    {group.tier === 'do_not_merge' && (
                        <div className="mt-3">
                            <InlineAlert tone="error">
                                These records disagree about which card they use, so they may well be two different
                                people. Merging them is not offered here. If you know better, use Merge from the
                                employee's own row menu.
                            </InlineAlert>
                        </div>
                    )}

                    <div className="mt-4 flex justify-end">
                        <Button
                            type="button"
                            variant={group.tier === 'strong' ? 'primary' : 'secondary'}
                            onClick={onMerge}
                            loading={busy}
                            disabled={!canMerge}
                        >
                            {group.tier === 'do_not_merge'
                                ? 'Not offered'
                                : `Merge ${folding.length} into ${survivor.name}`}
                        </Button>
                    </div>
                </div>
            )}
        </li>
    );
}
