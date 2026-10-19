import { Button, ProgressBar, SectionCard } from '../../shared/ui';

// Shared between the desktop and mobile report, so a number is formatted the
// same on both and neither can quietly round differently.

/** "1 Sep – 14 Sep 2026". The server always sends a resolved range. */
export function rangeLabel(report) {
    if (!report?.from || !report?.to) return 'Last 30 days';

    const from = new Date(`${report.from}T00:00:00`);
    const to = new Date(`${report.to}T00:00:00`);
    const opts = { day: 'numeric', month: 'short' };

    return `${from.toLocaleDateString([], opts)} – ${to.toLocaleDateString([], { ...opts, year: 'numeric' })}`;
}

export function hours(value) {
    return `${Number(value ?? 0).toFixed(1)} h`;
}

/*
| The background employee-report files (2026-10-18), shared by both presentations
| (rule 5). PERSISTENT state — what is being built, what is ready — so it is page
| content, not a toast (rule 2). It stays current through the page's useMqtt
| subscription on module/reports (rule 18); nothing here polls.
*/
export function ExportRuns({ runs, downloading, deleting, onDownload, onDelete }) {
    if (!runs?.length) return null;

    return (
        <SectionCard title="Employee report files" subtitle={`Kept ${KEEP_DAYS} days. One PDF per run: a contents page, then every person's report.`}>
            <ul className="divide-y divide-slate-100">
                {runs.map((run) => (
                    <li key={run.id} className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-semibold text-slate-900">
                                {rangeLabel(run)} · {run.scope ?? 'Every employee'}
                            </p>
                            <p className="mt-0.5 text-xs text-slate-500">
                                {run.requested_by ? `${run.requested_by} · ` : ''}{new Date(run.created_at).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })}
                                {run.status === 'done' && run.file_size ? ` · ${(run.file_size / 1048576).toFixed(1)} MB` : ''}
                            </p>
                            {!run.is_finished && (
                                <ProgressBar
                                    className="mt-2 max-w-md"
                                    percent={run.total ? (run.done / run.total) * 100 : null}
                                    label={run.message}
                                    detail={`${run.done} / ${run.total}`}
                                />
                            )}
                            {run.status === 'failed' && <p className="mt-1 text-xs font-medium text-rose-700">{run.message}</p>}
                            {run.status === 'done' && <p className="mt-1 text-xs text-slate-600">{run.message}</p>}
                        </div>
                        <div className="flex gap-2 self-start sm:self-center">
                            {run.status === 'done' && (
                                <Button
                                    variant="secondary"
                                    className="min-h-[40px]"
                                    loading={downloading === run.id}
                                    disabled={downloading === run.id || deleting === run.id}
                                    onClick={() => onDownload(run)}
                                >
                                    {downloading === run.id ? 'Downloading…' : 'Download PDF'}
                                </Button>
                            )}
                            {/* A file still being built cannot be deleted (the server refuses with 409). */}
                            {run.is_finished && (
                                <Button
                                    variant="ghost"
                                    className="min-h-[40px] text-rose-700 hover:bg-rose-50"
                                    loading={deleting === run.id}
                                    disabled={deleting === run.id || downloading === run.id}
                                    onClick={() => onDelete(run)}
                                >
                                    {deleting === run.id ? 'Deleting…' : 'Delete'}
                                </Button>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
        </SectionCard>
    );
}

const KEEP_DAYS = 7;
