import { useState } from 'react';
import { api } from '../../shared/api';
import { Button, SectionCard, TextField } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';

/*
| Daily clock sync (owner, 2026-10-11: "every day sync the time from server to the
| biometrics, at 5 am"; casebook DC-019). Every terminal that can take a clock is set to
| the server's NTP-synchronised time in its own zone and read back.
|
| A time of day rather than a cron field: this runs once a day by design, and "05:00" is
| what the owner asked for in those words. It is stored as the cron expression the
| scheduler evaluates ("0 5 * * *"), in the COMPANY's timezone.
*/
function toTime(expr) {
    const m = /^(\d{1,2}) (\d{1,2}) \* \* \*$/.exec((expr || '').trim());
    if (!m) return null;
    const pad = (n) => String(n).padStart(2, '0');
    return `${pad(m[2])}:${pad(m[1])}`;
}

function toCron(time) {
    const [h, m] = time.split(':').map((n) => parseInt(n, 10));
    return `${m} ${h} * * *`;
}

export default function ClockSyncSection({ settings, canManage, onSaved, onError }) {
    const stored = settings['devices.clock_sync_schedule'];
    const [enabled, setEnabled] = useState(settings['devices.clock_sync_enabled'] !== false);
    const [reads, setReads] = useState(settings['devices.daily_read_enabled'] !== false);
    const [time, setTime] = useState(toTime(stored) ?? '05:00');
    const [saving, setSaving] = useState(false);
    const zone = settings['system.timezone'];

    async function save(keys) {
        setSaving(true);
        try {
            const res = await api.put('/settings', keys);
            onSaved(res.data);
            return true;
        } catch (e) {
            onError(e.response?.data?.message || 'Could not save the clock sync.');
            return false;
        } finally {
            setSaving(false);
        }
    }

    return (
        <SectionCard
            title="Daily clock sync and attendance read"
            subtitle="Once a day: every terminal's clock is set to this server's time, then the past day's punches are read."
        >
            <div className="space-y-4">
                <label className="flex cursor-pointer items-start gap-3">
                    <input
                        type="checkbox"
                        className={`mt-0.5 h-4 w-4 rounded border-slate-300 text-violet-600 ${FOCUS}`}
                        checked={enabled}
                        disabled={!canManage || saving}
                        onChange={async (e) => {
                            const next = e.target.checked;
                            setEnabled(next);
                            if (!(await save({ 'devices.clock_sync_enabled': next }))) setEnabled(!next);
                        }}
                    />
                    <span className="text-sm text-slate-700">
                        <span className="font-medium text-slate-900">Sync every terminal&apos;s clock daily</span>
                        <span className="mt-0.5 block text-xs text-slate-500">
                            Each clock is set to the server&apos;s time in the terminal&apos;s own time zone and read
                            back; a terminal that does not take it shows as failed in its command list. The time-zone
                            setting alone never changes a terminal&apos;s clock.
                        </span>
                    </span>
                </label>

                <label className="flex cursor-pointer items-start gap-3">
                    <input
                        type="checkbox"
                        className={`mt-0.5 h-4 w-4 rounded border-slate-300 text-violet-600 ${FOCUS}`}
                        checked={reads}
                        disabled={!canManage || saving}
                        onChange={async (e) => {
                            const next = e.target.checked;
                            setReads(next);
                            if (!(await save({ 'devices.daily_read_enabled': next }))) setReads(!next);
                        }}
                    />
                    <span className="text-sm text-slate-700">
                        <span className="font-medium text-slate-900">Then read the past day&apos;s attendance from every terminal</span>
                        <span className="mt-0.5 block text-xs text-slate-500">
                            Yesterday and today, queued on each terminal right after its clock is set, so it reads with
                            the clock right. A punch already in the system is never added twice.
                        </span>
                    </span>
                </label>

                <div className="flex flex-wrap items-end gap-3">
                    <div className="w-40">
                        <TextField
                            label="Every day at"
                            type="time"
                            value={time}
                            disabled={!canManage || (!enabled && !reads)}
                            onChange={(e) => setTime(e.target.value)}
                            hint={zone}
                        />
                    </div>
                    {canManage && (
                        <Button
                            loading={saving}
                            disabled={(!enabled && !reads) || !time || toCron(time) === stored}
                            onClick={() => save({ 'devices.clock_sync_schedule': toCron(time) })}
                        >
                            Save time
                        </Button>
                    )}
                </div>
                {toTime(stored) === null && stored && (
                    <p className="text-xs text-slate-500">Currently on a custom schedule: <code>{stored}</code>.</p>
                )}
            </div>
        </SectionCard>
    );
}
