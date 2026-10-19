import { useRef, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { useConfirm } from '../../shared/confirm';
import { useToast } from '../../shared/toast';
import { Badge, Button, InlineAlert, PageHeader, SectionCard, Spinner } from '../../shared/ui';

/*
| The legacy ZKAccess backup and the system's own backup, on one screen.
|
| IMPORT: the operator uploads the .mdb the old attendance software exported
| (10-06-2026.mdb and friends) — or an earlier snapshot we produced — and the
| server parses it to canonical JSON, dry-runs the whole import and shows what
| WOULD happen. Nothing is written until Apply, which replays those exact bytes.
| Both steps are idempotent (matching by employee code, never duplicating an
| attendance row), so re-running is safe.
|
| EXPORT: a downloadable snapshot of the current state in the same canonical
| shape — the file the import side of this screen also accepts. A backup made
| here can be restored here (or in another tenant) later.
*/
export default function LegacyData() {
    const toast = useToast();
    const confirm = useConfirm();
    const fileInput = useRef(null);

    const [file, setFile] = useState(null);
    const [busy, setBusy] = useState(null);
    const [preview, setPreview] = useState(null); // { token, source, counts, report }
    const [applied, setApplied] = useState(null); // { message, report }
    const [error, setError] = useState(null);

    function chooseFile(e) {
        const next = e.target.files?.[0] || null;
        setFile(next);
        setPreview(null);
        setApplied(null);
        setError(null);
    }

    async function parse() {
        if (!file) return;
        setBusy('parse');
        setError(null);
        setApplied(null);
        try {
            const fd = new FormData();
            fd.append('file', file);
            const res = await api.post('/legacy/import/preview', fd);
            setPreview(res.data);
        } catch (err) {
            setError(err instanceof ApiError ? err.message : 'The upload could not be parsed.');
        } finally {
            setBusy(null);
            if (fileInput.current) fileInput.current.value = '';
        }
    }

    async function apply() {
        if (!preview) return;
        const ok = await confirm({
            title: 'Apply this import?',
            body:
                'Departments and employees are created and matched by employee code; profile fields are only filled where empty; attendance rows that already exist are skipped.' +
                ' Anything ambiguous is reported, never guessed. This is safe to run more than once.',
            confirmLabel: 'Apply import',
        });
        if (!ok) return;

        setBusy('apply');
        setError(null);
        try {
            const res = await api.post(`/legacy/import/${preview.token}/apply`);
            setApplied({ message: res.message, report: res.data.report });
        } catch (err) {
            setError(err instanceof ApiError ? err.message : 'The import could not be applied.');
        } finally {
            setBusy(null);
        }
    }

    async function downloadSnapshot() {
        setBusy('export');
        setError(null);
        try {
            const name = await api.download('/legacy/export', 'zkaccess-snapshot.json');
            toast.success(`Snapshot downloaded as ${name}.`);
        } catch (err) {
            setError(err instanceof ApiError ? err.message : 'The snapshot could not be downloaded.');
        } finally {
            setBusy(null);
        }
    }

    const { report } = applied?.report ? applied : preview || {};

    return (
        <div className="mx-auto max-w-3xl">
            <PageHeader
                title="Legacy data"
                subtitle="Import the old attendance software's backup, or export this system's state as a re-importable snapshot."
            />

            {error && (
                <div className="mb-4">
                    <InlineAlert tone="error" onDismiss={() => setError(null)}>{error}</InlineAlert>
                </div>
            )}
            {applied && (
                <div className="mb-4">
                    <InlineAlert tone="success" onDismiss={() => setApplied(null)}>{applied.message}</InlineAlert>
                </div>
            )}

            <SectionCard
                title="Import a legacy ZKAccess backup"
                subtitle="Upload a .mdb from the old software or one of our own export files (.json). Parsed and dry-run before anything is written."
                className="mb-5"
            >
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Backup file</label>
                <input
                    ref={fileInput}
                    type="file"
                    accept=".mdb,.json"
                    onChange={chooseFile}
                    disabled={busy !== null}
                    className="block w-full max-w-md rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2.5 text-sm text-slate-900 transition-colors duration-150 focus:border-violet-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-violet-100 disabled:opacity-50"
                />
                <p className="mt-1.5 text-xs text-slate-500">
                    .mdb backups can be tens of megabytes; reading is streamed, so the page only waits for the parse to finish.
                </p>

                <div className="mt-4">
                    <Button
                        variant="secondary"
                        disabled={!file || busy !== null}
                        loading={busy === 'parse'}
                        onClick={parse}
                    >
                        {preview ? 'Reparse this file' : 'Parse and preview'}
                    </Button>
                </div>

                {busy === 'parse' && (
                    <div className="mt-4 flex items-center gap-2 text-sm text-slate-500">
                        <Spinner className="h-4 w-4" />
                        Reading and dry-running the import — large files take a moment.
                    </div>
                )}
            </SectionCard>

            {report && <ImportReport {...{ report, preview, applied, busy, onApply: apply }} />}

            <SectionCard
                title="Export a backup snapshot"
                subtitle="Downloads the current departments, machines, employees and every attendance row as one JSON file in the same format the import above reads."
            >
                <div className="flex flex-wrap items-center gap-3">
                    <Button loading={busy === 'export'} disabled={busy !== null} onClick={downloadSnapshot}>
                        Download backup snapshot
                    </Button>
                    <span className="text-xs text-slate-500">
                        Re-importable into any company from the panel above.
                    </span>
                </div>
            </SectionCard>
        </div>
    );
}

function ImportReport({ report, preview, applied, busy, onApply }) {
    const depts = report.departments;
    const emp = report.employees;
    const att = report.attendance;

    const matched = emp.matched_by_pin + emp.matched_by_name;
    const warnings = report.warnings ?? [];

    return (
        <SectionCard
            title="Import report"
            subtitle={
                preview
                    ? `${preview.counts.users} employees and ${preview.counts.punches} punches in ${preview.source} — dry run, nothing written yet`
                    : 'Applied — the figures below are what was written.'
            }
            className="mb-5"
            action={applied ? <Badge tone="emerald">Applied</Badge> : <Badge tone="slate">{preview.source}</Badge>}
        >
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                <Kpi label="Departments" value={`${depts.created} created`} hint={`${depts.existing} matched`} />
                <Kpi label="Employees" value={`${matched} matched`} hint={`${emp.created} created`} />
                <Kpi label="Attendance" value={`${att.candidates} candidates`} hint={`${att.duplicates} already present`} />
                <Kpi label="Orphan punches" value={`${att.orphans}`} hint="no matching user" />
                <Kpi label="Unresolvable" value={`${att.unresolvable_device ?? 0}`} hint="no matching device" />
                <Kpi label="Ambiguous" value={`${emp.ambiguous}`} hint="left for you" />
            </div>

            <div className="mt-4 grid grid-cols-2 gap-x-6 gap-y-1.5 text-sm sm:grid-cols-3">
                <Detail label="Fields filled" value={emp.fields_filled} />
                <Detail label="Cards skipped" value={emp.cards_skipped} />
                <Detail label="Skipped (no pin)" value={emp.skipped_no_pin} />
                <Detail label="Skipped (deleted)" value={emp.skipped_deleted} />
                <Detail label="Skipped (code clash)" value={emp.skipped_code_collision} />
                <Detail label="Inserted this run" value={att.inserted} />
            </div>

            {!applied && (
                <div className="mt-5">
                    <Button variant="danger" loading={busy === 'apply'} disabled={busy !== null} onClick={onApply}>
                        Apply this import
                    </Button>
                    <p className="mt-1.5 text-xs text-slate-500">
                        Applying is idempotent — running it again after a partial failure is safe.
                    </p>
                </div>
            )}

            {warnings.length > 0 && (
                <div className="mt-5">
                    <h3 className="mb-2 text-sm font-semibold text-slate-900">
                        {warnings.length} warning{warnings.length === 1 ? '' : 's'} — nothing guessed
                    </h3>
                    <ul className="max-h-48 space-y-1.5 overflow-y-auto pr-1">
                        {warnings.map((w, i) => (
                            <li key={i} className="flex items-start gap-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                <Badge tone="amber" className="mt-0.5 shrink-0">UNRESOLVED</Badge>
                                <span className="min-w-0">
                                    <span className="font-medium text-slate-800">{w.user}</span>
                                    {w.card ? <span className="text-slate-400"> · card {w.card}</span> : null}
                                    <span className="block text-slate-500">{w.reason}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </SectionCard>
    );
}

function Kpi({ label, value, hint }) {
    return (
        <div className="rounded-xl bg-slate-50 px-3 py-2.5">
            <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500">{label}</p>
            <p className="mt-0.5 text-sm font-semibold tabular-nums text-slate-900">{value}</p>
            {hint && <p className="text-xs tabular-nums text-slate-500">{hint}</p>}
        </div>
    );
}

function Detail({ label, value }) {
    return (
        <div className="flex items-baseline justify-between gap-3 border-b border-slate-100 pb-1">
            <span className="text-slate-500">{label}</span>
            <span className="tabular-nums font-medium text-slate-800">{value}</span>
        </div>
    );
}