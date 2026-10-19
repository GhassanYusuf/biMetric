import { Badge, Button, SectionCard, Skeleton, Spinner, TextField } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';

const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

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
export function AttendanceRulesCard({ rules, draft, errors, canManage, savingWeekend, savingHours, dirty, onToggleDay, onDraft, onSaveHours, onReset, compact = false }) {
    const off = rules?.weekend_days ?? [];
    const weekendText = off.length === 0 ? 'No weekend: every day is a work day.' : `${off.map((d) => WEEKDAYS[d]).join(' and ')} ${off.length === 1 ? 'is' : 'are'} the weekend.`;
    const field = (key) => ({
        value: draft?.[key] ?? '',
        onChange: (e) => onDraft(key, e.target.value),
        error: errors[key]?.[0],
        disabled: !canManage || savingHours,
    });

    return (
        <SectionCard
            title="Attendance rules"
            subtitle="Every report counts Late, Left early, Left late, Absent and overtime against these."
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
