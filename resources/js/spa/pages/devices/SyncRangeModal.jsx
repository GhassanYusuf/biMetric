import { useState } from 'react';
import { useEscapeKey } from '../../shared/useEscapeKey';
import { Button, Card, InlineAlert, TextField } from '../../shared/ui';

/*
| Asks for the window of days to import from one terminal.
|
| WHY A DIALOG AND NOT TWO FIELDS ON THE ROW: this is the one device action whose
| result depends entirely on what the operator typed, and a mistyped year reads as
| "the sync imported nothing" days later. The dates get a surface of their own, with
| the presets that cover almost every real request and a sentence saying plainly
| what the read will and will not do.
|
| Rule 1: no window.prompt — it cannot hold two fields, a warning and a date picker.
| Rule 2: the explanation is page STATE (it stays true the whole time the dialog is
| open), so it is content here; the OUTCOME of the read is a toast, raised by the
| caller. Field problems are field errors, never toasts.
*/

/** Local Y-M-D. toISOString() would shift the date in any timezone west of UTC. */
function isoDate(date) {
    const pad = (n) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/*
| The windows people actually ask for, in the order they ask for them. "This month"
| is the payroll period being chased; "last month" is the one that closed wrong;
| "last 7 days" is the gap a stopped worker left over a weekend.
*/
function presets(today) {
    const startOfThisMonth = new Date(today.getFullYear(), today.getMonth(), 1);
    const startOfLastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
    const endOfLastMonth = new Date(today.getFullYear(), today.getMonth(), 0);
    const sevenDaysAgo = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 6);

    return [
        { key: 'last7', label: 'Last 7 days', from: isoDate(sevenDaysAgo), to: isoDate(today) },
        { key: 'this-month', label: 'This month', from: isoDate(startOfThisMonth), to: isoDate(today) },
        { key: 'last-month', label: 'Last month', from: isoDate(startOfLastMonth), to: isoDate(endOfLastMonth) },
    ];
}

export default function SyncRangeModal({ device, onSubmit, onClose }) {
    const today = new Date();
    const todayIso = isoDate(today);
    const options = presets(today);

    const [form, setForm] = useState({ from: options[1].from, to: todayIso });
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

    // Rule 7: Escape closes — but not while the queue request is in flight, which
    // matches how the backdrop is guarded below.
    useEscapeKey(onClose, !submitting);

    function set(key, value) {
        setForm((f) => ({ ...f, [key]: value }));
        setErrors((e) => ({ ...e, [key]: undefined }));
    }

    function apply(preset) {
        setForm({ from: preset.from, to: preset.to });
        setErrors({});
    }

    async function submit(e) {
        e.preventDefault();

        /*
         * Checked here as well as on the server because these two mistakes have an
         * obvious fix the operator can make without a round trip. The server still
         * validates — this is an affordance, not the authority.
         */
        const next = {};
        if (!form.from) next.from = ['Pick the first day to import.'];
        if (!form.to) next.to = ['Pick the last day to import.'];
        if (form.from && form.to && form.to < form.from) next.to = ['The last day cannot be before the first.'];

        if (Object.keys(next).length > 0) {
            setErrors(next);

            return;
        }

        setSubmitting(true);
        try {
            await onSubmit({ from: form.from, to: form.to });
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-slate-900/40 p-0 sm:items-center sm:p-4"
            onClick={submitting ? undefined : onClose}
        >
            <Card className="w-full max-w-md rounded-b-none sm:rounded-2xl">
                <form onClick={(e) => e.stopPropagation()} onSubmit={submit} className="p-5">
                    <h2 className="text-lg font-semibold text-slate-900">Read a date range</h2>
                    <p className="mt-1 text-sm text-slate-600">
                        Import only the punches {device?.name} recorded between these two days. Both days are
                        included.
                    </p>

                    <div className="mt-4 flex flex-wrap gap-2">
                        {options.map((preset) => {
                            const active = form.from === preset.from && form.to === preset.to;

                            return (
                                <button
                                    key={preset.key}
                                    type="button"
                                    onClick={() => apply(preset)}
                                    aria-pressed={active}
                                    className={`rounded-full border px-3 py-1.5 text-xs font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-violet-100 ${
                                        active
                                            ? 'border-violet-300 bg-violet-50 text-violet-700'
                                            : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                                    }`}
                                >
                                    {preset.label}
                                </button>
                            );
                        })}
                    </div>

                    <div className="mt-3 grid grid-cols-2 gap-3">
                        {/*
                          | max is today: a terminal cannot have recorded tomorrow, and
                          | a window that ends in the future silently imports nothing.
                        */}
                        <TextField
                            label="From"
                            type="date"
                            value={form.from}
                            max={todayIso}
                            onChange={(e) => set('from', e.target.value)}
                            error={errors.from?.[0]}
                        />
                        <TextField
                            label="To"
                            type="date"
                            value={form.to}
                            max={todayIso}
                            onChange={(e) => set('to', e.target.value)}
                            error={errors.to?.[0]}
                        />
                    </div>

                    {/*
                      | Rule 3 and rule 10, at the point of asking: never let a screen
                      | imply the hardware can do something it cannot. This protocol has
                      | no "punches since X" — the terminal always hands over its whole
                      | table — so a range is not a faster read, and an operator told
                      | otherwise would report the progress bar as broken.
                    */}
                    <div className="mt-4">
                        <InlineAlert tone="info">
                            The terminal always sends its whole log — a range does not make the read quicker, it
                            limits what is imported. Days outside the range are counted and left alone, and the
                            normal sync position is not moved, so ordinary syncing carries on unchanged.
                        </InlineAlert>
                    </div>

                    <div className="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={submitting}>
                            Read this range
                        </Button>
                    </div>
                </form>
            </Card>
        </div>
    );
}
