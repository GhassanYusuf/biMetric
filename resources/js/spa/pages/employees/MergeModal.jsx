import { useEffect, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { FOCUS } from '../../shared/theme';
import { Button, Card, InlineAlert, Spinner, TextField } from '../../shared/ui';
import { useEscapeKey } from '../../shared/useEscapeKey';

/*
| Folds duplicate employment records into one.
|
| WHY IT IS A SEARCH AND NOT A LIST OF SUGGESTIONS. The automatic finder catches the
| import artefact — the same name at the SAME PIN on several terminals — and that is
| the only shape it can be sure about. One person enrolled under DIFFERENT pins on
| different devices looks identical, in the data, to two colleagues who share a name.
| Only somebody who knows the people can tell, so this asks them.
|
| WHAT IT DOES NOT DO: touch a device. Every terminal keeps its own user, PIN,
| fingerprints and card exactly as they are; what moves is which employee row those
| mappings point at. That is worth saying on screen, because "merge" sounds like it
| reaches the hardware, and the fear of losing an enrolment is what stops people
| cleaning up their roster.
|
| It IS irreversible in this app, so the confirm step names what will move and the
| action is red.
*/
export default function MergeModal({ employee, onMerged, onClose }) {
    const [keepId, setKeepId] = useState(employee.id);
    const [search, setSearch] = useState(employee.name ?? '');
    const [results, setResults] = useState([]);
    const [loading, setLoading] = useState(false);
    const [picked, setPicked] = useState([]); // employee objects to fold in
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    useEscapeKey(onClose, !busy);

    useEffect(() => {
        const term = search.trim();
        if (term.length < 2) {
            setResults([]);
            return undefined;
        }

        let cancelled = false;
        setLoading(true);

        // Debounced: this runs on every keystroke and the roster is hundreds of rows.
        const t = setTimeout(() => {
            api.get(`/employees?search=${encodeURIComponent(term)}&per_page=25`)
                .then((res) => {
                    if (!cancelled) setResults(res.data ?? []);
                })
                .catch(() => {
                    if (!cancelled) setResults([]);
                })
                .finally(() => {
                    if (!cancelled) setLoading(false);
                });
        }, 250);

        return () => { cancelled = true; clearTimeout(t); };
    }, [search]);

    // Everyone involved: the record opened from, plus whatever was picked. The keeper
    // is chosen from among them, so the operator can keep the OTHER record — which is
    // usually the right call, since the duplicate with the punches is often not the
    // one they searched from.
    const involved = [employee, ...picked.filter((p) => p.id !== employee.id)];
    const keeper = involved.find((e) => e.id === keepId) ?? employee;
    const folding = involved.filter((e) => e.id !== keeper.id);

    function toggle(row) {
        setPicked((prev) =>
            prev.some((p) => p.id === row.id)
                ? prev.filter((p) => p.id !== row.id)
                : [...prev, row],
        );
        setError(null);
    }

    function terminals(e) {
        return (e.enrollments ?? []).filter((m) => m.is_live !== false);
    }

    async function submit() {
        setBusy(true);
        setError(null);
        try {
            const res = await api.post('/employees/merge', {
                keep_id: keeper.id,
                merge_ids: folding.map((e) => e.id),
            });
            onMerged(res.message);
        } catch (err) {
            setError(err instanceof ApiError ? err.message : 'The merge could not be completed.');
        } finally {
            setBusy(false);
        }
    }

    const movingPunches = folding.reduce((n, e) => n + (e.attendance_logs_count ?? 0), 0);
    const movingTerminals = folding.reduce((n, e) => n + terminals(e).length, 0);

    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-slate-900/40 p-0 sm:items-center sm:p-4"
            onClick={busy ? undefined : onClose}
            role="presentation"
        >
            <Card className="w-full max-w-lg rounded-b-none sm:rounded-2xl" onClick={(e) => e.stopPropagation()}>
                <div className="p-5">
                    <h2 className="text-lg font-semibold tracking-[-0.01em] text-slate-900">Merge duplicate records</h2>
                    <p className="mt-1 text-sm text-slate-600">
                        One person with two records has their attendance split in two, and no report about them is
                        right. Find the other record and fold it in.
                    </p>

                    <div className="mt-4">
                        <TextField
                            label="Find the other record"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Name or employee code"
                            autoFocus
                        />
                    </div>

                    <div className="mt-3 max-h-48 space-y-1 overflow-y-auto">
                        {loading && (
                            <div className="flex items-center gap-2 py-2 text-xs text-slate-500">
                                <Spinner className="h-3 w-3" /> searching…
                            </div>
                        )}
                        {!loading && search.trim().length >= 2 && results.filter((r) => r.id !== employee.id).length === 0 && (
                            <p className="py-2 text-xs text-slate-500">Nobody else matches that.</p>
                        )}
                        {results
                            .filter((r) => r.id !== employee.id)
                            .map((r) => (
                                <label key={r.id} className="flex items-start gap-2.5 rounded-lg px-1 py-1.5 hover:bg-slate-50">
                                    <input
                                        type="checkbox"
                                        checked={picked.some((p) => p.id === r.id)}
                                        onChange={() => toggle(r)}
                                        className={`mt-0.5 h-4 w-4 rounded border-slate-300 text-violet-600 ${FOCUS}`}
                                    />
                                    <span className="min-w-0 text-sm text-slate-700">
                                        {r.name} <span className="text-slate-500">· {r.employee_code}</span>
                                        <span className="block text-xs text-slate-500">
                                            {(r.attendance_logs_count ?? 0).toLocaleString()} punches ·{' '}
                                            {terminals(r).length} terminal{terminals(r).length === 1 ? '' : 's'}
                                            {terminals(r).length > 0 && ` (${terminals(r).map((m) => m.device_name).join(', ')})`}
                                        </span>
                                    </span>
                                </label>
                            ))}
                    </div>

                    {folding.length > 0 && (
                        <div className="mt-4 border-t border-slate-100 pt-4">
                            {/*
                              | Which record SURVIVES is the decision that matters, and it
                              | is not always the one the operator opened. The record with
                              | the history is usually the one to keep.
                            */}
                            <span className="text-sm font-medium text-slate-700">Keep which record?</span>
                            <div className="mt-2 space-y-1.5">
                                {involved.map((e) => (
                                    <label key={e.id} className="flex items-start gap-2.5">
                                        <input
                                            type="radio"
                                            name="keeper"
                                            checked={keepId === e.id}
                                            onChange={() => setKeepId(e.id)}
                                            className={`mt-0.5 h-4 w-4 border-slate-300 text-violet-600 ${FOCUS}`}
                                        />
                                        <span className="min-w-0 text-sm text-slate-700">
                                            {e.name} <span className="text-slate-500">· {e.employee_code}</span>
                                            <span className="block text-xs text-slate-500">
                                                {(e.attendance_logs_count ?? 0).toLocaleString()} punches ·{' '}
                                                {terminals(e).length} terminal{terminals(e).length === 1 ? '' : 's'}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                            </div>

                            <div className="mt-4">
                                <InlineAlert tone="error">
                                    {folding.length} record{folding.length === 1 ? '' : 's'} will be deleted and{' '}
                                    {movingPunches.toLocaleString()} punch{movingPunches === 1 ? '' : 'es'} and{' '}
                                    {movingTerminals} terminal mapping{movingTerminals === 1 ? '' : 's'} will move to{' '}
                                    {keeper.name}. No terminal is contacted — fingerprints, cards and PINs stay exactly
                                    where they are. This cannot be undone from here.
                                </InlineAlert>
                            </div>
                        </div>
                    )}

                    {error && (
                        <div className="mt-3">
                            <InlineAlert tone="error">{error}</InlineAlert>
                        </div>
                    )}

                    <div className="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
                        <Button
                            type="button"
                            variant="danger"
                            onClick={submit}
                            loading={busy}
                            disabled={folding.length === 0}
                        >
                            {folding.length === 0
                                ? 'Pick a record'
                                : `Merge ${folding.length} into ${keeper.name}`}
                        </Button>
                    </div>
                </div>
            </Card>
        </div>
    );
}
