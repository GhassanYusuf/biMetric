import { useCallback, useEffect, useState } from 'react';
import { useMqtt } from '../../shared/useMqtt';
import { api } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useConfirm } from '../../shared/confirm';
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

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await api.get('/holidays');
            setRows(res.data);
        } finally {
            setLoading(false);
        }
    }, []);

    // LIVE (CLAUDE.md rule 18): refresh in place when holidays change anywhere.
    useMqtt(user?.tenant ? [`tenant/${user.tenant.id}/module/holidays/+`] : [], () => load());

    useEffect(() => {
        load();
    }, [load]);

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
