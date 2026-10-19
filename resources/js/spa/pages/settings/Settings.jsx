import { useCallback, useEffect, useState } from 'react';
import { api } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useConfirm } from '../../shared/confirm';
import {
    Badge,
    Button,
    InlineAlert,
    PageHeader,
    SectionCard,
    SelectField,
    TableShell,
    TextField,
    Thead,
} from '../../shared/ui';
import { COMMON_ZONES, driftLabel, useAllZones, zoneLabel } from './parts';
import ScheduleSection from './ScheduleSection';
import ClockSyncSection from './ClockSyncSection';

// Company settings: the system time zone, per-terminal overrides, and the daily
// attendance read.
//
// The page owns every write to `settings`, and keeps the orphaned
// `employees.auto_push_on_save` toggle visible — it has had an API since 2026-09-24
// and no screen to reach it from, which is exactly how a setting gets forgotten.
//
// Two zones appear on this screen and they are not the same thing. The COMPANY zone
// is what a device clock is written in and what the attendance schedule is evaluated
// in. The SERVER zone (app.timezone, UTC on this host) is an implementation detail
// shown only so nobody is surprised by it.
export default function Settings() {
    const { can } = useAuth();
    const confirm = useConfirm();

    const [settings, setSettings] = useState(null);
    const [plan, setPlan] = useState(null);
    const [saving, setSaving] = useState(false);
    const [saveError, setSaveError] = useState(null);
    const [notice, setNotice] = useState(null);

    const [zoneDraft, setZoneDraft] = useState('');
    const [drift, setDrift] = useState(null);
    const [checking, setChecking] = useState(false);
    const [applying, setApplying] = useState(false);

    const [editing, setEditing] = useState(null); // device id whose override is open
    const [overrideDraft, setOverrideDraft] = useState('');
    const [overrideError, setOverrideError] = useState(null);
    const [savingOverride, setSavingOverride] = useState(false);

    const canManage = can('settings.manage');
    const allZones = useAllZones();

    const load = useCallback(async () => {
        try {
            const [settingsRes, planRes] = await Promise.all([
                api.get('/settings'),
                api.get('/settings/timezones'),
            ]);
            setSettings(settingsRes.data);
            setZoneDraft(settingsRes.data['system.timezone']);
            setPlan(planRes.data);
        } catch (e) {
            setSaveError(e.response?.data?.message || 'Could not load settings.');
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    async function save(keys, successMessage) {
        setSaving(true);
        setSaveError(null);
        try {
            const res = await api.put('/settings', keys);
            setSettings(res.data);
            setNotice({ tone: 'success', text: successMessage });
            return true;
        } catch (e) {
            // The server refuses an unknown zone or a bad cron with a real message;
            // surface it rather than swallowing it into a generic failure.
            setSaveError(
                e.response?.data?.message ||
                    e.response?.data?.errors?.[Object.keys(e.response?.data?.errors || {})[0]]?.[0] ||
                    'Could not save.'
            );
            return false;
        } finally {
            setSaving(false);
        }
    }

    /** Read-only: asks every terminal what time it thinks it is. */
    async function checkClocks() {
        setChecking(true);
        setNotice(null);
        try {
            const res = await api.post('/settings/timezones/check');
            setDrift(res.data.devices);
            const unreachable = res.data.devices.filter((d) => !d.reachable).length;
            setNotice({
                tone: unreachable ? 'warn' : 'success',
                text: unreachable
                    ? `Checked ${res.data.devices.length} terminals. ${unreachable} did not answer.`
                    : `Checked ${res.data.devices.length} terminals.`,
            });
        } catch (e) {
            setSaveError(e.response?.data?.message || 'Clock check failed.');
        } finally {
            setChecking(false);
        }
    }

    /**
     * Queues a clock write for every terminal that supports one.
     *
     * Confirmed first, because this is the one control here that writes to hardware:
     * it changes the time every FUTURE punch on those terminals is stamped with. C3
     * panels cannot do it at all, and the plan lists which ones so the confirmation
     * can say "3 of 6" rather than implying all six.
     */
    async function applyToAll() {
        const settable = (plan?.devices || []).filter((d) => d.supports_set_time);
        const refused = (plan?.devices || []).filter((d) => !d.supports_set_time);

        if (settable.length === 0) {
            setNotice({ tone: 'warn', text: 'No terminal in this company can have its clock set.' });
            return;
        }

        const ok = await confirm({
            title: `Set the clock on ${settable.length} terminal${settable.length === 1 ? '' : 's'}?`,
            body:
                `Each will be set to ${zoneLabel(settings['system.timezone'])}. ` +
                'This changes the time every future punch on those terminals is stamped with. ' +
                (refused.length
                    ? `${refused.length} (${refused.map((d) => d.name).join(', ')}) cannot have their clock set and will be skipped.`
                    : ''),
            confirmLabel: 'Set clocks',
        });
        if (!ok) return;

        setApplying(true);
        try {
            const res = await api.post('/settings/timezones/apply');
            setNotice({
                tone: 'success',
                text: `Queued ${res.data.queued.length} clock sync(s)` +
                    (res.data.refused.length ? `, skipped ${res.data.refused.length} that cannot do it.` : '.'),
            });
        } catch (e) {
            setSaveError(e.response?.data?.message || 'Could not queue clock syncs.');
        } finally {
            setApplying(false);
        }
    }

    function openOverride(device) {
        setEditing(device.id);
        setOverrideDraft(device.timezone ?? '');
        setOverrideError(null);
    }

    /** Saves one terminal's own zone, or clears it back to inheriting. */
    async function saveOverride(device) {
        setSavingOverride(true);
        setOverrideError(null);
        try {
            await api.put(`/devices/${device.id}`, {
                name: device.name,
                ip_address: device.ip_address,
                protocol: device.protocol,
                // '' means "same as the company default" and the API stores null.
                timezone: overrideDraft.trim() === '' ? null : overrideDraft.trim(),
            });
            const res = await api.get('/settings/timezones');
            setPlan(res.data);
            setEditing(null);
            setNotice({
                tone: 'success',
                text:
                    overrideDraft.trim() === ''
                        ? `${device.name} now follows the company time zone.`
                        : `${device.name} now runs on ${zoneLabel(overrideDraft.trim())}.`,
            });
        } catch (e) {
            setOverrideError(
                e.response?.data?.errors?.timezone?.[0] ||
                    e.response?.data?.message ||
                    'Could not save that time zone.'
            );
        } finally {
            setSavingOverride(false);
        }
    }

    if (!settings) {
        return (
            <div className="space-y-5">
                <PageHeader title="Settings" subtitle="Company time zone and attendance schedule" />
                {saveError && <InlineAlert tone="error">{saveError}</InlineAlert>}
            </div>
        );
    }

    const overridden = (plan?.devices || []).filter((d) => d.overrides);
    const notWritable = (plan?.devices || []).filter((d) => !d.supports_set_time);

    return (
        <div className="space-y-5">
            <PageHeader title="Settings" subtitle="Company time zone and attendance schedule">
                {notice && <InlineAlert tone={notice.tone} onDismiss={() => setNotice(null)}>{notice.text}</InlineAlert>}
            </PageHeader>

            {saveError && (
                <InlineAlert tone="error" onDismiss={() => setSaveError(null)}>
                    {saveError}
                </InlineAlert>
            )}

            {/* ---------------------------------------------------------------- */}
            <SectionCard
                title="Company time zone"
                subtitle="Every terminal follows this unless it has its own override below."
            >
                <div className="space-y-4">
                    <p className="text-sm text-slate-600">
                        A biometric terminal has no time zone database — it stores a clock, not a zone. This setting
                        is the wall-clock time written to those clocks, and the zone future punches are read in.
                        Regional names are preferred because they carry daylight-saving rules; a plain offset like{' '}
                        <code className="rounded bg-slate-100 px-1">+03:00</code> is fixed all year.
                    </p>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <SelectField
                            label="Company time zone"
                            value={zoneDraft}
                            disabled={!canManage}
                            onChange={(e) => setZoneDraft(e.target.value)}
                            list="company-zones"
                        >
                            {!COMMON_ZONES.includes(zoneDraft) && zoneDraft && (
                                <option value={zoneDraft}>{zoneDraft}</option>
                            )}
                            {COMMON_ZONES.map((z) => (
                                <option key={z} value={z}>
                                    {zoneLabel(z)}
                                </option>
                            ))}
                        </SelectField>

                        <TextField
                            label="Or type a zone"
                            value={zoneDraft}
                            disabled={!canManage}
                            onChange={(e) => setZoneDraft(e.target.value)}
                            placeholder="Europe/London"
                            list="all-zones"
                            hint="Daylight-saving aware names are best."
                        />
                    </div>

                    <datalist id="company-zones" />
                    <datalist id="all-zones">
                        {allZones.map((z) => (
                            <option key={z} value={z} />
                        ))}
                    </datalist>

                    {canManage && (
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                loading={saving}
                                disabled={zoneDraft === settings['system.timezone']}
                                onClick={() =>
                                    save({ 'system.timezone': zoneDraft.trim() }, 'Company time zone saved.')
                                }
                            >
                                Save time zone
                            </Button>
                            <Button variant="secondary" loading={checking} onClick={checkClocks}>
                                Check terminal clocks
                            </Button>
                            <Button variant="secondary" loading={applying} onClick={applyToAll}>
                                Apply to terminal clocks
                            </Button>
                        </div>
                    )}
                </div>
            </SectionCard>

            {/* ---------------------------------------------------------------- */}
            <SectionCard
                title="Terminal time zones"
                subtitle="Overrides for a machine in another country. Empty means it follows the company zone."
            >
                {notWritable.length > 0 && (
                    <div className="mb-4">
                        <InlineAlert tone="info">
                            {notWritable.length} terminal{notWritable.length === 1 ? '' : 's'} cannot have their
                            clock set at all (
                            {notWritable.map((d) => d.name).join(', ')}
                            ). Their panels report time-set as unsupported, so they keep whatever time ZKAccess
                            gives them. Attendance from them is still read, and read in the company zone.
                        </InlineAlert>
                    </div>
                )}

                {plan?.devices?.length === 0 ? (
                    <p className="text-sm text-slate-500">No active terminals.</p>
                ) : (
                    <TableShell>
                        <table className="min-w-full text-sm">
                            <Thead>
                                <tr>
                                    <th className="px-5 py-3 text-left font-semibold">Terminal</th>
                                    <th className="px-5 py-3 text-left font-semibold">Time zone</th>
                                    <th className="px-5 py-3 text-left font-semibold">Clock</th>
                                    <th className="px-5 py-3 text-right font-semibold">Override</th>
                                </tr>
                            </Thead>
                            <tbody className="divide-y divide-slate-100">
                                {(plan?.devices || []).map((d) => {
                                    const checked = drift?.find((x) => x.id === d.id);
                                    const isEditing = editing === d.id;

                                    return (
                                        <tr key={d.id} className="align-top">
                                            <td className="px-5 py-3">
                                                <div className="font-medium text-slate-900">{d.name}</div>
                                                <div className="text-xs text-slate-500">
                                                    {d.ip_address} · {d.protocol.toUpperCase()}
                                                </div>
                                            </td>

                                            <td className="px-5 py-3">
                                                {isEditing ? (
                                                    <div className="space-y-2">
                                                        <TextField
                                                            value={overrideDraft}
                                                            onChange={(e) => setOverrideDraft(e.target.value)}
                                                            placeholder="Same as company default"
                                                            list="all-zones"
                                                            autoFocus
                                                            error={overrideError}
                                                            hint="Leave empty to follow the company zone."
                                                        />
                                                        <div className="flex gap-2">
                                                            <Button
                                                                loading={savingOverride}
                                                                onClick={() => saveOverride(d)}
                                                            >
                                                                Save
                                                            </Button>
                                                            <Button variant="ghost" onClick={() => setEditing(null)}>
                                                                Cancel
                                                            </Button>
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <>
                                                        <div className="font-medium text-slate-900">
                                                            {zoneLabel(d.timezone)}
                                                        </div>
                                                        <div className="mt-1 flex items-center gap-1.5">
                                                            {d.overrides ? (
                                                                <Badge tone="violet">Override</Badge>
                                                            ) : (
                                                                <span className="text-xs text-slate-500">
                                                                    follows company
                                                                </span>
                                                            )}
                                                        </div>
                                                    </>
                                                )}
                                            </td>

                                            <td className="px-5 py-3">
                                                {d.supports_set_time ? (
                                                    checked ? (
                                                        checked.reachable ? (
                                                            <div>
                                                                <div className="text-slate-900">{checked.reported}</div>
                                                                <div className="text-xs text-slate-500">
                                                                    wants {checked.expected}
                                                                    {checked.drift_seconds !== null && (
                                                                        <>
                                                                            {' · '}
                                                                            off by {driftLabel(checked.drift_seconds)}
                                                                        </>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        ) : (
                                                            <span className="text-xs text-rose-600">
                                                                {checked.error || 'No answer'}
                                                            </span>
                                                        )
                                                    ) : (
                                                        <span className="text-xs text-slate-500">
                                                            not checked · target {d.target_time}
                                                        </span>
                                                    )
                                                ) : (
                                                    <span className="text-xs text-slate-500">
                                                        {d.refusal?.replace(/^Cannot set the clock on this terminal: /, '') ?? 'not supported'}
                                                    </span>
                                                )}
                                            </td>

                                            <td className="px-5 py-3 text-right">
                                                {!isEditing && (
                                                    <Button variant="ghost" onClick={() => openOverride(d)}>
                                                        {d.overrides ? 'Change' : 'Set'}
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </TableShell>
                )}

                {overridden.length > 0 && (
                    <p className="mt-3 text-xs text-slate-500">
                        {overridden.length} terminal{overridden.length === 1 ? '' : 's'} on a different time zone
                        from the company default.
                    </p>
                )}
            </SectionCard>

            {/* ---------------------------------------------------------------- */}
            <ScheduleSection
                settings={settings}
                canManage={canManage}
                onSaved={(next) => setSettings(next)}
                onError={setSaveError}
            />

            {/* ---------------------------------------------------------------- */}
            <ClockSyncSection
                settings={settings}
                canManage={canManage}
                onSaved={(next) => setSettings(next)}
                onError={setSaveError}
            />

            {/* ---------------------------------------------------------------- */}
            {canManage && (
                <SectionCard
                    title="Employee updates"
                    subtitle="What happens when someone saves an employee record."
                >
                    <label className="flex cursor-pointer items-start gap-3">
                        <input
                            type="checkbox"
                            className="mt-0.5 h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-200"
                            checked={!!settings['employees.auto_push_on_save']}
                            disabled={saving}
                            onChange={(e) =>
                                save(
                                    { 'employees.auto_push_on_save': e.target.checked },
                                    e.target.checked
                                        ? 'Employee saves will push to terminals immediately.'
                                        : 'Employee saves will no longer push to terminals.'
                                )
                            }
                        />
                        <span className="text-sm text-slate-700">
                            <span className="font-medium text-slate-900">
                                Push to terminals automatically on save
                            </span>
                            <span className="mt-0.5 block text-xs text-slate-500">
                                Saves write the name, card and user ID straight to every terminal on the
                                employee&apos;s access levels. Off is the safer default: on, every edit becomes a
                                device write.
                            </span>
                        </span>
                    </label>
                </SectionCard>
            )}
        </div>
    );
}
