import { Badge, Button, SectionCard, Skeleton, Spinner, TextField } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';

export const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

/** "HH:MM" plus or minus some minutes, for the plain-language summary. */
function shiftClock(hhmm, delta) {
    const [h, m] = hhmm.split(':').map(Number);
    const t = h * 60 + m + delta;
    return `${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`;
}

/*
| The company's attendance rules (AttendanceRules on the server): the weekend, the shift
| and the thresholds behind Late, Left early and Left late. Every report counts against
| them.
|
| The weekend saves on each press, with the row locked while it does so two quick presses
| cannot race into the wrong week. The hours and thresholds are a small form with a Save
| button: typing "07:30" one keystroke at a time must not save "0", "07", "07:3" on the way.
| Holidays.jsx keeps the form's draft apart from the saved rules, so a live refresh never
| overwrites what somebody is typing (rule 18).
*/
export function AttendanceRulesCard({ rules, draft, errors, canManage, mayEditDefault = true, savingWeekend, savingHours, dirty, onToggleDay, onDraft, onSaveHours, onReset, compact = false }) {
    const off = rules?.weekend_days ?? [];
    const weekendText = off.length === 0 ? 'No weekend: every day is a work day.' : `${off.map((d) => WEEKDAYS[d]).join(' and ')} ${off.length === 1 ? 'is' : 'are'} the weekend.`;
    const field = (key) => ({
        value: draft?.[key] ?? '',
        onChange: (e) => onDraft(key, e.target.value),
        error: errors[key]?.[0],
        disabled: !canManage || !mayEditDefault || savingHours,
    });
    canManage = canManage && mayEditDefault; // a user limited to some companies reads the default only

    return (
        <SectionCard
            title="Default attendance rules"
            subtitle="Every company without its own rules is judged by these: Late, Left early, Left late, Absent and overtime."
            action={(savingWeekend || savingHours) && <Spinner className="h-4 w-4 text-violet-600" />}
            bodyClass={compact ? 'p-3 space-y-4' : 'p-5 pt-4 space-y-5'}
        >
            {rules === null || draft === null ? (
                <Skeleton className="h-40 w-full" />
            ) : (
                <>
                    <div>
                        <h3 className="text-sm font-medium text-slate-700">Weekend</h3>
                        <p className="mt-0.5 text-xs text-slate-500">Never counted absent or late; time worked on these days is overtime.</p>
                        <div className={`mt-2 ${compact ? 'grid grid-cols-4 gap-2' : 'flex flex-wrap gap-2'}`}>
                            {WEEKDAYS.map((name, day) => {
                                const isOff = off.includes(day);
                                return (
                                    <button
                                        key={day}
                                        type="button"
                                        aria-pressed={isOff}
                                        disabled={!canManage || savingWeekend}
                                        onClick={() => onToggleDay(day)}
                                        className={`min-h-[40px] rounded-lg px-3 text-sm font-medium transition-colors disabled:cursor-not-allowed ${FOCUS} ${
                                            isOff ? 'bg-violet-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                                        }`}
                                    >
                                        {compact ? name.slice(0, 3) : name}
                                    </button>
                                );
                            })}
                        </div>
                        <p className="mt-2 text-sm text-slate-600">{weekendText}</p>
                    </div>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            onSaveHours();
                        }}
                    >
                        <h3 className="text-sm font-medium text-slate-700">Working hours and indicators</h3>
                        <div className={`mt-2 grid gap-3 ${compact ? 'grid-cols-2' : 'grid-cols-5'}`}>
                            <TextField label="Shift starts" type="time" {...field('shift_start')} />
                            <TextField label="Shift ends" type="time" {...field('shift_end')} />
                            <TextField label="Late after (min)" type="number" min="0" max="240" hint="Allowance after the start" {...field('late_grace_minutes')} />
                            <TextField label="Left early (min)" type="number" min="0" max="240" hint="Allowance before the end" {...field('early_leave_grace_minutes')} />
                            <TextField label="Left late after (min)" type="number" min="0" max="240" hint="Past the end; overtime" {...field('leave_late_after_minutes')} />
                        </div>
                        <p className="mt-3 text-sm text-slate-600">
                            Late: first punch after <b>{shiftClock(rules.shift_start, rules.late_grace_minutes)}</b> · Left early: last punch before{' '}
                            <b>{shiftClock(rules.shift_end, -rules.early_leave_grace_minutes)}</b> · Left late: last punch from{' '}
                            <b>{shiftClock(rules.shift_end, rules.leave_late_after_minutes)}</b>
                        </p>
                        {canManage && (
                            <div className="mt-3 flex gap-2">
                                <Button type="submit" loading={savingHours} disabled={!dirty || savingHours}>Save hours</Button>
                                {dirty && <Button type="button" variant="ghost" disabled={savingHours} onClick={onReset}>Undo changes</Button>}
                            </div>
                        )}
                    </form>
                </>
            )}
        </SectionCard>
    );
}

/** "07:30–16:30 · Fri, Sat off · late after 10 min" */
export function rulesSummary(r) {
    const off = r.weekend_days ?? [];
    const weekend = off.length === 0 ? 'no weekend' : `${off.map((d) => WEEKDAYS[d].slice(0, 3)).join(', ')} off`;
    return `${r.shift_start}–${r.shift_end} · ${weekend} · late after ${r.late_grace_minutes} min`;
}

/*
| Per-company rules (2026-11-22). Each company either uses the default or keeps its own; shared by the
| desktop and mobile layouts (rule 5), Holidays.jsx owns the reads and writes.
*/
export function CompanyRulesCard({ companies, canManage, busyId, onEdit, onReset, compact = false }) {
    return (
        <SectionCard
            title="Company rules"
            subtitle="A company with its own rules is judged by them; the rest use the default above."
            bodyClass={compact ? 'p-2' : 'p-2 pt-1'}
        >
            {companies === null ? (
                <Skeleton className="m-3 h-24" />
            ) : companies.length === 0 ? (
                <p className="px-3 py-6 text-center text-sm text-slate-500">No companies yet.</p>
            ) : (
                <ul className="divide-y divide-slate-100">
                    {companies.map((c) => (
                        <li key={c.id} className={`flex gap-3 rounded-xl px-3 py-2.5 ${compact ? 'flex-col' : 'items-center'}`}>
                            <div className="min-w-0 flex-1">
                                <div className="truncate font-medium text-slate-800">{c.name}</div>
                                <div className="text-xs text-slate-500">{c.rules ? rulesSummary(c.rules) : 'Uses the default rules'}</div>
                            </div>
                            <div className="flex shrink-0 items-center gap-2">
                                {c.rules ? <Badge tone="violet">Own rules</Badge> : <Badge tone="slate">Default</Badge>}
                                {busyId === c.id && <Spinner className="h-4 w-4 text-violet-600" />}
                                {canManage && (
                                    <>
                                        <button type="button" onClick={() => onEdit(c)} disabled={busyId === c.id} className={`rounded px-1.5 text-sm text-slate-600 hover:text-slate-800 ${FOCUS}`}>
                                            {c.rules ? 'Edit' : 'Set own rules'}
                                        </button>
                                        {c.rules && (
                                            <button type="button" onClick={() => onReset(c)} disabled={busyId === c.id} className={`rounded px-1.5 text-sm text-rose-700 hover:underline ${FOCUS}`}>
                                                Use default
                                            </button>
                                        )}
                                    </>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </SectionCard>
    );
}

/** "All companies", or the ticked companies' names. */
export function companiesLabel(holiday) {
    const list = holiday.companies ?? [];
    return list.length === 0 ? 'All companies' : list.map((c) => c.name).join(', ');
}

// Holiday dates are plain Y-m-d strings (no timezone), so they are parsed as
// local dates explicitly — `new Date('2026-01-01')` would be parsed as UTC and
// can render as the previous day west of Greenwich.
export function parseDate(ymd) {
    const [y, m, d] = ymd.split('-').map(Number);
    return new Date(y, m - 1, d);
}

export function formatDate(ymd) {
    return parseDate(ymd).toLocaleDateString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function startOfToday() {
    const now = new Date();
    return new Date(now.getFullYear(), now.getMonth(), now.getDate());
}

export function dayState(ymd) {
    const date = parseDate(ymd);
    const today = startOfToday();
    if (date.getTime() === today.getTime()) return 'today';
    return date < today ? 'past' : 'upcoming';
}

export function DayBadge({ ymd }) {
    const state = dayState(ymd);
    if (state === 'today') return <Badge tone="violet">Today</Badge>;
    if (state === 'past') return <Badge tone="slate">Past</Badge>;
    return <Badge tone="emerald">Upcoming</Badge>;
}

// Splits the list into upcoming (incl. today) and past, newest-first for past.
export function partition(rows) {
    const upcoming = rows.filter((h) => dayState(h.date) !== 'past');
    const past = rows.filter((h) => dayState(h.date) === 'past').reverse();
    return { upcoming, past };
}
