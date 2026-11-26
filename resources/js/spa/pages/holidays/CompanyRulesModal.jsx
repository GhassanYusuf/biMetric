import { useState } from 'react';
import { ApiError } from '../../shared/api';
import { FOCUS } from '../../shared/theme';
import { Button, Card, TextField } from '../../shared/ui';
import { useEscapeKey } from '../../shared/useEscapeKey';
import { WEEKDAYS } from './parts';

/*
| One company's own attendance rules (2026-11-22): weekend, shift and the three allowances, saved
| together so a company never half-follows the default. Opens filled with the company's own rules, or
| with the default's when it has none yet.
*/
export default function CompanyRulesModal({ company, defaults, onSubmit, onClose }) {
    const start = company.rules ?? defaults;
    const [form, setForm] = useState({
        weekend_days: start.weekend_days ?? [],
        shift_start: start.shift_start,
        shift_end: start.shift_end,
        late_grace_minutes: start.late_grace_minutes,
        early_leave_grace_minutes: start.early_leave_grace_minutes,
        leave_late_after_minutes: start.leave_late_after_minutes,
        // Open 24 hours (2026-11-26): no Late / Left early / Left late; hours first to last punch.
        no_fixed_hours: !!start.no_fixed_hours,
    });
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

    useEscapeKey(onClose, !submitting);

    const field = (key) => ({
        value: form[key] ?? '',
        onChange: (e) => setForm((f) => ({ ...f, [key]: e.target.value })),
        error: errors[key]?.[0],
        disabled: submitting,
    });

    function toggleDay(day) {
        setForm((f) => ({
            ...f,
            weekend_days: f.weekend_days.includes(day) ? f.weekend_days.filter((d) => d !== day) : [...f.weekend_days, day].sort((a, b) => a - b),
        }));
    }

    async function submit(e) {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        try {
            await onSubmit({
                ...form,
                late_grace_minutes: Number(form.late_grace_minutes),
                early_leave_grace_minutes: Number(form.early_leave_grace_minutes),
                leave_late_after_minutes: Number(form.leave_late_after_minutes),
            });
        } catch (err) {
            // 422 → on the fields; anything else was toasted by the page.
            if (err instanceof ApiError && err.status === 422 && err.errors) setErrors(err.errors);
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/40 p-0 sm:items-center sm:p-4" onClick={submitting ? undefined : onClose} role="presentation">
            <Card className="w-full max-w-lg rounded-b-none sm:rounded-2xl" onClick={(e) => e.stopPropagation()}>
                <form onSubmit={submit} className="space-y-4 p-5">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-900">{company.name}: own rules</h2>
                        <p className="mt-0.5 text-sm text-slate-600">Everyone in this company is judged by these instead of the default.</p>
                    </div>

                    <div>
                        <h3 className="text-sm font-medium text-slate-700">Weekend</h3>
                        <div className="mt-2 grid grid-cols-4 gap-2 sm:grid-cols-7">
                            {WEEKDAYS.map((name, day) => {
                                const isOff = form.weekend_days.includes(day);
                                return (
                                    <button
                                        key={day}
                                        type="button"
                                        aria-pressed={isOff}
                                        disabled={submitting}
                                        onClick={() => toggleDay(day)}
                                        className={`min-h-[40px] rounded-lg px-2 text-sm font-medium transition-colors ${FOCUS} ${isOff ? 'bg-violet-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'}`}
                                    >
                                        {name.slice(0, 3)}
                                    </button>
                                );
                            })}
                        </div>
                        {errors.weekend_days?.[0] && <p className="mt-1 text-sm text-rose-700">{errors.weekend_days[0]}</p>}
                    </div>

                    <label className="flex cursor-pointer items-start gap-2.5 rounded-xl p-3 text-sm text-slate-700 ring-1 ring-slate-200">
                        <input
                            type="checkbox"
                            checked={form.no_fixed_hours}
                            onChange={(e) => setForm((f) => ({ ...f, no_fixed_hours: e.target.checked }))}
                            disabled={submitting}
                            className={`mt-0.5 h-4 w-4 rounded border-slate-300 text-violet-600 ${FOCUS}`}
                        />
                        <span>
                            <span className="font-medium text-slate-800">Open 24 hours: no fixed working hours</span>
                            <span className="block text-xs text-slate-500">
                                Nobody is marked Late, Left early or Left late. A work day with punches is present, paid from the first punch to the last (across midnight). A work day with none is absent.
                            </span>
                        </span>
                    </label>

                    <div className="grid grid-cols-2 gap-3">
                        <TextField label={form.no_fixed_hours ? 'Normal day starts' : 'Shift starts'} type="time" {...field('shift_start')} />
                        <TextField label={form.no_fixed_hours ? 'Normal day ends' : 'Shift ends'} type="time" {...field('shift_end')} />
                        {form.no_fixed_hours ? (
                            <p className="col-span-2 -mt-1 text-xs text-slate-500">The length of a normal day: what a holiday or excused day is paid, and how long an absence counts.</p>
                        ) : (
                            <>
                                <TextField label="Late after (min)" type="number" min="0" max="240" hint="Allowance after the start" {...field('late_grace_minutes')} />
                                <TextField label="Left early (min)" type="number" min="0" max="240" hint="Allowance before the end" {...field('early_leave_grace_minutes')} />
                                <TextField label="Left late after (min)" type="number" min="0" max="240" hint="Past the end; overtime" {...field('leave_late_after_minutes')} />
                            </>
                        )}
                    </div>

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>Cancel</Button>
                        <Button type="submit" loading={submitting}>Save rules</Button>
                    </div>
                </form>
            </Card>
        </div>
    );
}
