import { useEffect, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { Badge, Button, Card, SelectField, TextField } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';
import { useEscapeKey } from '../../shared/useEscapeKey';

/*
| Pieces shared by the Cron jobs screen's desktop and mobile layouts and its dialog
| (owner, 2026-10-20). The server decides every rule shown here — which actions exist,
| what a valid schedule is, how often is too often, which terminals are ours
| (ScheduledJobController); the dialog only avoids offering what it would refuse.
|
| A schedule is ONE cron expression in the company's timezone. The simple editor writes
| "minute hours * * days" — 05:00 and 10:00 on Sun–Thu is "0 5,10 * * 0-4" style
| ("0 5,10 * * 0,1,2,3,4"); anything it cannot express is edited as text.
*/

export const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const pad = (n) => String(n).padStart(2, '0');

function expandDays(field) {
    if (field === '*') return [0, 1, 2, 3, 4, 5, 6];
    const out = new Set();
    for (const part of field.split(',')) {
        const range = /^(\d)-(\d)$/.exec(part);
        if (range) for (let d = +range[1]; d <= +range[2]; d++) out.add(d % 7);
        else if (/^\d$/.test(part)) out.add(+part % 7);
        else return null;
    }
    return [...out].sort();
}

/** { times: ['05:00', …], days: [0…6] } when the simple editor can show it, else null. */
export function parseSimple(expr) {
    const m = /^(\d{1,2}) (\d{1,2}(?:,\d{1,2})*) \* \* (\S+)$/.exec((expr || '').trim());
    if (!m) return null;
    const days = expandDays(m[3]);
    if (!days || days.length === 0) return null;
    return { times: m[2].split(',').map((h) => `${pad(h)}:${pad(m[1])}`), days };
}

/** The expression, or an error sentence when the times cannot share one expression. */
export function buildSimple(times, days) {
    const parsed = [...new Set(times.filter(Boolean))].map((t) => t.split(':').map((n) => parseInt(n, 10)));
    if (parsed.length === 0) return { error: 'Add at least one time.' };
    if (new Set(parsed.map(([, mm]) => mm)).size > 1) return { error: 'All the times must be on the same minute (05:00 and 10:00, not 05:00 and 10:30). Add a second job for a different minute.' };
    if (days.length === 0) return { error: 'Choose at least one day.' };
    const hours = parsed.map(([h]) => h).sort((a, b) => a - b);
    const dayField = days.length === 7 ? '*' : [...days].sort().join(',');
    return { expression: `${parsed[0][1]} ${hours.join(',')} * * ${dayField}` };
}

/** "Every day at 05:00 and 10:00" — or the expression itself when it is not that shape. */
export function describe(expr) {
    const s = parseSimple(expr);
    if (!s) return expr;
    const when = s.times.length === 1 ? s.times[0] : `${s.times.slice(0, -1).join(', ')} and ${s.times[s.times.length - 1]}`;
    const days = s.days.length === 7 ? 'Every day'
        : s.days.join() === '0,1,2,3,4' ? 'Sun–Thu'
            : s.days.join() === '1,2,3,4,5' ? 'Mon–Fri'
                : s.days.map((d) => DAYS[d]).join(', ');
    return `${days} at ${when}`;
}

/** An ISO time from the server, shown in the company's zone. */
export function when(iso, zone) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(undefined, { timeZone: zone, weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false });
}

export function terminalsLabel(job, devices) {
    if (!job.device_ids) return 'All terminals';
    const names = job.device_ids.map((id) => devices.find((d) => d.id === id)?.name).filter(Boolean);
    return names.length ? names.join(', ') : 'No terminals (removed)';
}

export function StatusBadge({ job }) {
    return job.is_enabled ? <Badge tone="emerald">On</Badge> : <Badge tone="slate">Paused</Badge>;
}

/** The menu items for one job — identical on both layouts (rule 5). */
export function jobActions(job, { canManage, onRun, onEdit, onToggle, onDelete }) {
    if (!canManage) return [];
    return [
        { key: 'run', label: 'Run now', onSelect: () => onRun(job) },
        { key: 'edit', label: 'Edit', onSelect: () => onEdit(job) },
        { key: 'toggle', label: job.is_enabled ? 'Pause' : 'Turn on', onSelect: () => onToggle(job) },
        { key: 'delete', label: 'Delete', tone: 'danger', onSelect: () => onDelete(job) },
    ];
}

/** The system's own jobs: shown so nothing runs unseen, not editable here. */
export function SystemJobList({ jobs, zone }) {
    return (
        <ul className="divide-y divide-slate-100">
            {jobs.map((j) => (
                <li key={j.key} className="flex flex-col gap-1 py-3 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                    <div className="min-w-0">
                        <div className="text-sm font-medium text-slate-900">{j.label}</div>
                        <div className="text-xs text-slate-500">{j.description}</div>
                    </div>
                    <div className="shrink-0 text-xs text-slate-600 sm:text-right">
                        <code className="rounded bg-slate-100 px-1.5 py-0.5 text-slate-700">{j.schedule}</code> <span className="text-slate-500">UTC</span>
                        <div className="mt-0.5 text-slate-500">Next {when(j.next_runs[0], zone)}</div>
                    </div>
                </li>
            ))}
        </ul>
    );
}

function Dialog({ title, submitting, onClose, children }) {
    useEscapeKey(onClose, !submitting);
    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/40 p-0 sm:items-center sm:p-4" onClick={submitting ? undefined : onClose} role="presentation">
            <Card className="w-full max-w-xl rounded-b-none sm:rounded-2xl" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" aria-labelledby="cron-dialog-title">
                <h2 id="cron-dialog-title" className="sr-only">{title}</h2>
                {children}
            </Card>
        </div>
    );
}

/** Add a job, or edit one. */
export function JobFormModal({ job, actions, devices, zone, onSubmit, onClose }) {
    const isEdit = !!job;
    const initial = parseSimple(job?.schedule ?? '0 5 * * *');
    const [name, setName] = useState(job?.name ?? '');
    const [action, setAction] = useState(job?.action ?? '');
    const [mode, setMode] = useState(initial ? 'simple' : 'advanced');
    const [times, setTimes] = useState(initial?.times ?? ['05:00']);
    const [days, setDays] = useState(initial?.days ?? [0, 1, 2, 3, 4, 5, 6]);
    const [expression, setExpression] = useState(job?.schedule ?? '0 5 * * *');
    const [allDevices, setAllDevices] = useState(!job?.device_ids);
    const [picked, setPicked] = useState(job?.device_ids ?? []);
    const [preview, setPreview] = useState(null);
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

    const simple = buildSimple(times, days);
    const schedule = mode === 'simple' ? simple.expression : expression.trim();
    const localError = mode === 'simple' ? simple.error : null;

    // The server says when it fires — the same cron library the scheduler runs.
    useEffect(() => {
        if (!schedule) { setPreview(null); return undefined; }
        const t = setTimeout(async () => {
            try {
                const res = await api.get('/scheduled-jobs/preview', { params: { expression: schedule } });
                setPreview(res.data);
            } catch {
                setPreview(null);
            }
        }, 300);
        return () => clearTimeout(t);
    }, [schedule]);

    function switchMode(next) {
        if (next === 'advanced' && simple.expression) setExpression(simple.expression);
        if (next === 'simple') {
            const s = parseSimple(expression);
            if (s) { setTimes(s.times); setDays(s.days); }
        }
        setMode(next);
    }

    async function submit(e) {
        e.preventDefault();
        const found = {};
        if (!name.trim()) found.name = ['Give it a name.'];
        if (!action) found.action = ['Choose what it does.'];
        if (localError) found.schedule = [localError];
        if (!allDevices && picked.length === 0) found.device_ids = ['Choose at least one terminal, or all of them.'];
        if (Object.keys(found).length) { setErrors(found); return; }

        setSubmitting(true);
        setErrors({});
        try {
            await onSubmit({ name: name.trim(), action, schedule, device_ids: allDevices ? null : picked });
        } catch (err) {
            if (err instanceof ApiError && err.errors) setErrors(err.errors);
        } finally {
            setSubmitting(false);
        }
    }

    const chosen = actions.find((a) => a.key === action);
    const scheduleError = errors.schedule?.[0] ?? localError ?? (preview && !preview.valid ? preview.error : null);
    const deviceError = errors.device_ids?.[0] ?? Object.entries(errors).find(([k]) => k.startsWith('device_ids.'))?.[1]?.[0];

    return (
        <Dialog title={isEdit ? `Edit ${job.name}` : 'Add a cron job'} submitting={submitting} onClose={onClose}>
            <form onSubmit={submit} className="max-h-[90vh] overflow-y-auto p-5" noValidate>
                <p className="mb-4 text-lg font-semibold text-slate-900" aria-hidden="true">{isEdit ? `Edit ${job.name}` : 'Add a cron job'}</p>
                <div className="space-y-4">
                    <TextField id="cron-name" label="Name" value={name} onChange={(e) => setName(e.target.value)} error={errors.name?.[0]} maxLength={100} autoFocus required />

                    <div>
                        <SelectField id="cron-action" label="What it does" value={action} onChange={(e) => setAction(e.target.value)} error={errors.action?.[0]}>
                            <option value="">Choose…</option>
                            {actions.map((a) => <option key={a.key} value={a.key}>{a.label}</option>)}
                        </SelectField>
                        {chosen && <p className="mt-1 text-xs text-slate-500">{chosen.description}</p>}
                    </div>

                    <fieldset>
                        <div className="mb-1.5 flex items-center justify-between gap-3">
                            <legend className="text-sm font-medium text-slate-700">When <span className="font-normal text-slate-500">({zone})</span></legend>
                            <button type="button" onClick={() => switchMode(mode === 'simple' ? 'advanced' : 'simple')} className={`text-xs font-medium text-violet-700 hover:underline ${FOCUS}`}>
                                {mode === 'simple' ? 'Type a cron expression' : 'Pick times and days'}
                            </button>
                        </div>

                        {mode === 'simple' ? (
                            <div className="space-y-3">
                                <div className="flex flex-wrap items-end gap-2">
                                    {times.map((t, i) => (
                                        <div key={i} className="flex items-center gap-1">
                                            <TextField
                                                id={`cron-time-${i}`}
                                                aria-label={`Time ${i + 1}`}
                                                type="time"
                                                value={t}
                                                onChange={(e) => setTimes(times.map((x, j) => (j === i ? e.target.value : x)))}
                                                className="w-32"
                                            />
                                            {times.length > 1 && (
                                                <button type="button" aria-label={`Remove ${t || 'this time'}`} onClick={() => setTimes(times.filter((_, j) => j !== i))} className={`rounded-lg px-2 py-1 text-lg leading-none text-slate-500 hover:bg-slate-100 hover:text-slate-800 ${FOCUS}`}>×</button>
                                            )}
                                        </div>
                                    ))}
                                    <Button type="button" variant="secondary" onClick={() => setTimes([...times, ''])}>+ Time</Button>
                                </div>
                                <div className="flex flex-wrap gap-1.5" role="group" aria-label="Days">
                                    {DAYS.map((label, d) => {
                                        const on = days.includes(d);
                                        return (
                                            <button
                                                key={label}
                                                type="button"
                                                aria-pressed={on}
                                                onClick={() => setDays(on ? days.filter((x) => x !== d) : [...days, d])}
                                                className={`min-h-[36px] rounded-lg border px-3 text-xs font-medium transition-colors ${on ? 'border-violet-600 bg-violet-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'} ${FOCUS}`}
                                            >
                                                {label}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        ) : (
                            <TextField
                                id="cron-expression"
                                aria-label="Cron expression"
                                value={expression}
                                onChange={(e) => setExpression(e.target.value)}
                                hint="Five fields: minute hour day-of-month month day-of-week. 0 5,10 * * * is 05:00 and 10:00 daily."
                                className="font-mono"
                            />
                        )}

                        {scheduleError ? (
                            <p role="alert" className="mt-2 text-xs font-medium text-rose-600">{scheduleError}</p>
                        ) : preview?.valid ? (
                            <p className="mt-2 text-xs text-slate-600">
                                <code className="rounded bg-slate-100 px-1.5 py-0.5">{preview.expression}</code> — next {preview.next_runs.map((r) => when(r, zone)).join(' · ')}
                            </p>
                        ) : null}
                    </fieldset>

                    <fieldset>
                        <legend className="mb-1.5 text-sm font-medium text-slate-700">Terminals</legend>
                        <div className="flex gap-4 text-sm text-slate-700">
                            <label className="flex cursor-pointer items-center gap-2">
                                <input type="radio" name="cron-terminals" checked={allDevices} onChange={() => setAllDevices(true)} className={`h-4 w-4 text-violet-600 ${FOCUS}`} />
                                All terminals
                            </label>
                            <label className="flex cursor-pointer items-center gap-2">
                                <input type="radio" name="cron-terminals" checked={!allDevices} onChange={() => setAllDevices(false)} className={`h-4 w-4 text-violet-600 ${FOCUS}`} />
                                Only these
                            </label>
                        </div>
                        {!allDevices && (
                            <div className="mt-2 grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                                {devices.map((d) => (
                                    <label key={d.id} className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-slate-700 hover:bg-slate-50">
                                        <input
                                            type="checkbox"
                                            checked={picked.includes(d.id)}
                                            onChange={(e) => setPicked(e.target.checked ? [...picked, d.id] : picked.filter((x) => x !== d.id))}
                                            className={`h-4 w-4 rounded border-slate-300 text-violet-600 ${FOCUS}`}
                                        />
                                        {d.name}
                                    </label>
                                ))}
                            </div>
                        )}
                        {deviceError && <p role="alert" className="mt-1 text-xs font-medium text-rose-600">{deviceError}</p>}
                        <p className="mt-1.5 text-xs text-slate-500">A terminal that cannot do this, or is still busy with the same thing, is skipped for that run.</p>
                    </fieldset>
                </div>
                <div className="mt-5 flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>Cancel</Button>
                    <Button type="submit" loading={submitting}>{isEdit ? 'Save' : 'Add job'}</Button>
                </div>
            </form>
        </Dialog>
    );
}
