import { useCallback, useEffect, useState } from 'react';
import { api } from '../../shared/api';
import { Badge, Button, InlineAlert, SectionCard, SelectField, TextField } from '../../shared/ui';
import { SCHEDULE_PRESETS, presetFor } from './parts';

/**
 * The scheduled attendance read.
 *
 * The next-run preview comes from the server, deliberately. A browser has no cron
 * parser, and shipping one to label '0 3 * * *' as "daily at 03:00" would create a
 * second opinion about the schedule — which is precisely the disagreement this
 * feature exists to remove. The server answers with the same library the scheduler
 * runs on.
 *
 * The cost warning is not decoration. The protocol cannot be asked for "punches since
 * X", so every run transfers each terminal's entire attendance table.
 */
export default function ScheduleSection({ settings, canManage, onSaved, onError }) {
    const [enabled, setEnabled] = useState(!!settings['attendance.sync_enabled']);
    const [expression, setExpression] = useState(settings['attendance.sync_schedule']);
    const [preview, setPreview] = useState(null);
    const [loadingPreview, setLoadingPreview] = useState(false);
    const [saving, setSaving] = useState(false);

    const selectedPreset = presetFor(expression);
    const onPreset = !!selectedPreset;

    const loadPreview = useCallback(async (expr) => {
        if (!expr || !expr.trim()) {
            setPreview(null);
            return;
        }

        setLoadingPreview(true);
        try {
            const res = await api.get('/settings/attendance-schedule', {
                params: { expression: expr.trim() },
            });
            setPreview(res.data);
        } catch {
            setPreview(null);
        } finally {
            setLoadingPreview(false);
        }
    }, []);

    useEffect(() => {
        loadPreview(expression);
    }, [expression, loadPreview]);

    async function save(keys, message) {
        setSaving(true);
        try {
            const res = await api.put('/settings', keys);
            onSaved(res.data);
            return true;
        } catch (e) {
            onError(
                e.response?.data?.message ||
                    e.response?.data?.errors?.[Object.keys(e.response?.data?.errors || {})[0]]?.[0] ||
                    'Could not save the schedule.'
            );
            return false;
        } finally {
            setSaving(false);
        }
    }

    return (
        <SectionCard
            title="Scheduled attendance read"
            subtitle="How often this system pulls attendance from every terminal."
        >
            <div className="space-y-4">
                <InlineAlert tone="warn">
                    Every run transfers each terminal&apos;s <strong>entire</strong> attendance table — the
                    protocol cannot be asked for “punches since X”. On the largest panel that is tens of
                    thousands of rows and several minutes. Start with once a day.
                </InlineAlert>

                <label className="flex cursor-pointer items-start gap-3">
                    <input
                        type="checkbox"
                        className="mt-0.5 h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-200"
                        checked={enabled}
                        disabled={!canManage || saving}
                        onChange={async (e) => {
                            const next = e.target.checked;
                            setEnabled(next);
                            if (await save({ 'attendance.sync_enabled': next }, next ? 'Scheduled attendance reads are on.' : 'Scheduled attendance reads are off.')) {
                                return;
                            }
                            setEnabled(!next); // the save failed; put the switch back
                        }}
                    />
                    <span className="text-sm text-slate-700">
                        <span className="font-medium text-slate-900">
                            Read attendance from all terminals on a schedule
                        </span>
                        <span className="mt-0.5 block text-xs text-slate-500">
                            Until this is on, scheduled reads do not run at all. Punches still arrive in real
                            time from the terminals that push them.
                        </span>
                    </span>
                </label>

                <div className="grid gap-4 sm:grid-cols-2">
                    <SelectField
                        label="How often"
                        value={onPreset ? selectedPreset.expr : '__custom'}
                        disabled={!canManage}
                        onChange={(e) => {
                            if (e.target.value !== '__custom') setExpression(e.target.value);
                        }}
                    >
                        {SCHEDULE_PRESETS.map((p) => (
                            <option key={p.expr} value={p.expr}>
                                {p.label}
                            </option>
                        ))}
                        <option value="__custom">Custom…</option>
                    </SelectField>

                    <TextField
                        label="Cron expression"
                        value={expression}
                        disabled={!canManage}
                        onChange={(e) => setExpression(e.target.value)}
                        placeholder="0 3 * * *"
                        className="font-mono"
                        hint={
                            loadingPreview
                                ? 'checking…'
                                : 'five fields: minute hour day month weekday'
                        }
                        error={preview && !preview.valid ? preview.error : null}
                    />
                </div>

                {preview?.valid && (
                    <div className="rounded-xl bg-slate-50 px-4 py-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                Next runs
                            </span>
                            <Badge tone="slate">{preview.timezone}</Badge>
                        </div>
                        <ul className="mt-2 space-y-1 text-sm text-slate-700">
                            {preview.next_runs.map((when) => (
                                <li key={when} className="font-mono text-xs">
                                    {when}
                                </li>
                            ))}
                        </ul>
                        <p className="mt-2 text-xs text-slate-500">
                            Times are {preview.timezone}, so “03:00” means 03:00 where your company is.
                        </p>
                    </div>
                )}

                {canManage && (
                    <Button
                        loading={saving}
                        disabled={expression === settings['attendance.sync_schedule']}
                        onClick={() =>
                            save(
                                { 'attendance.sync_schedule': expression.trim() },
                                'Attendance schedule saved.'
                            )
                        }
                    >
                        Save schedule
                    </Button>
                )}
            </div>
        </SectionCard>
    );
}
