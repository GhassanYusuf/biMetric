import { useCallback, useEffect, useState } from 'react';
import { useMqtt } from '../../shared/useMqtt';
import { api, ApiError } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useConfirm } from '../../shared/confirm';
import { useToast } from '../../shared/toast';
import { SkeletonTable } from '../../shared/ui';
import HolidaysDesktop from './HolidaysDesktop';
import HolidaysMobile from './HolidaysMobile';
import HolidayFormModal from './HolidayFormModal';

// Owns the tenant's holiday calendar. The API returns them ordered by date and
// enforces one holiday per date per tenant, so a duplicate surfaces as a 422 on
// the date field.
export default function Holidays() {
    const deviceClass = useDeviceClass();
    const { can, user } = useAuth();
    const confirm = useConfirm();
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);
    const [editing, setEditing] = useState(null); // holiday object, or {} for "new"
    // The saved attendance rules (AttendanceRules on the server), null until read, and the
    // hours form's draft — kept apart so a live refresh never overwrites what is being typed.
    const [rules, setRules] = useState(null);
    const [draft, setDraft] = useState(null);
    const [ruleErrors, setRuleErrors] = useState({});
    const [savingWeekend, setSavingWeekend] = useState(false);
    const [savingHours, setSavingHours] = useState(false);
    const toast = useToast();

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await api.get('/holidays');
            setRows(res.data);
        } finally {
            setLoading(false);
        }
    }, []);

    const dirty = rules !== null && draft !== null && HOUR_FIELDS.some((k) => String(draft[k]) !== String(rules[k]));

    const loadRules = useCallback(async () => {
        try {
            const res = await api.get('/holidays/rules');
            setRules(res.data);
            // Only an untouched form follows the server; an edited one keeps its draft.
            setDraft((prev) => (prev === null || !prev.__edited ? hoursOf(res.data) : prev));
        } catch {
            // Left as it is; the next live change asks again.
        }
    }, []);

    // LIVE (CLAUDE.md rule 18): refresh in place when holidays — or the rules — change anywhere.
    useMqtt(user?.tenant ? [`tenant/${user.tenant.id}/module/holidays/+`] : [], () => {
        load();
        loadRules();
    });

    useEffect(() => {
        load();
        loadRules();
    }, [load, loadRules]);

    async function toggleWeekendDay(day) {
        const current = rules?.weekend_days ?? [];
        const next = current.includes(day) ? current.filter((d) => d !== day) : [...current, day].sort((a, b) => a - b);
        setSavingWeekend(true);
        try {
            const res = await api.put('/holidays/rules', { weekend_days: next });
            setRules(res.data);
            toast.success(res.message);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not save the weekend.');
        } finally {
            setSavingWeekend(false);
        }
    }

    async function saveHours() {
        setSavingHours(true);
        setRuleErrors({});
        try {
            const payload = Object.fromEntries(HOUR_FIELDS.map((k) => [k, k.endsWith('_minutes') ? Number(draft[k]) : draft[k]]));
            const res = await api.put('/holidays/rules', payload);
            setRules(res.data);
            setDraft(hoursOf(res.data));
            toast.success(res.message);
        } catch (err) {
            // A 422 belongs on the field (rule 2); anything else is an event.
            if (err instanceof ApiError && err.status === 422 && Object.keys(err.errors).length) setRuleErrors(err.errors);
            else toast.error(err instanceof ApiError ? err.message : 'Could not save the working hours.');
        } finally {
            setSavingHours(false);
        }
    }

    async function saveHoliday(payload) {
        if (editing?.id) {
            const res = await api.put(`/holidays/${editing.id}`, payload);
            setRows((prev) => sortByDate(prev.map((h) => (h.id === editing.id ? res.data : h))));
        } else {
            const res = await api.post('/holidays', payload);
            setRows((prev) => sortByDate([...prev, res.data]));
        }
        setEditing(null);
    }

    async function deleteHoliday(holiday) {
        const ok = await confirm({
            title: `Remove "${holiday.name}"?`,
            body: `${holiday.date} will count as a normal working day again.`,
            confirmLabel: 'Remove holiday',
            danger: true,
        });
        if (!ok) return;

        await api.delete(`/holidays/${holiday.id}`);
        setRows((prev) => prev.filter((h) => h.id !== holiday.id));
    }

    const presentationProps = {
        rows,
        canManage: can('holidays.manage'),
        onAdd: () => setEditing({}),
        onEdit: (holiday) => setEditing(holiday),
        onDelete: deleteHoliday,
        rulesProps: {
            rules,
            draft,
            errors: ruleErrors,
            canManage: can('holidays.manage'),
            savingWeekend,
            savingHours,
            dirty,
            onToggleDay: toggleWeekendDay,
            onDraft: (key, value) => setDraft((prev) => ({ ...prev, [key]: value, __edited: true })),
            onSaveHours: saveHours,
            onReset: () => {
                setDraft(hoursOf(rules));
                setRuleErrors({});
            },
        },
    };

    const Presentation = deviceClass === 'desktop' ? HolidaysDesktop : HolidaysMobile;

    return (
        <>
            {loading && rows.length === 0 ? (
                <SkeletonTable rows={5} cols={3} />
            ) : (
                <Presentation {...presentationProps} />
            )}
            {editing && (
                <HolidayFormModal
                    holiday={editing.id ? editing : null}
                    onSubmit={saveHoliday}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

// Keeps the client list in the same order the API returns (ascending by date)
// after a local insert or edit, so nothing jumps on the next refetch.
function sortByDate(list) {
    return [...list].sort((a, b) => a.date.localeCompare(b.date));
}

// The fields the hours form edits; the weekend saves on its own.
const HOUR_FIELDS = ['shift_start', 'shift_end', 'late_grace_minutes', 'early_leave_grace_minutes', 'leave_late_after_minutes'];

function hoursOf(rules) {
    return Object.fromEntries(HOUR_FIELDS.map((k) => [k, rules[k]]));
}
