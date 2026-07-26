import { useCallback, useEffect, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useConfirm } from '../../shared/confirm';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useToast } from '../../shared/toast';
import { SkeletonTable } from '../../shared/ui';
import DeviceGroupsDesktop from './DeviceGroupsDesktop';
import DeviceGroupsMobile from './DeviceGroupsMobile';
import DeviceGroupFormModal from './DeviceGroupFormModal';

/*
| Groups of terminals, so one action can target a whole site.
|
| A group command queues ONE command per member, never one for the group. A run that
| half-succeeds has to stay legible per terminal, and a retry must not re-hit the
| devices that already worked.
*/
export default function DeviceGroups() {
    const deviceClass = useDeviceClass();
    const { can } = useAuth();
    const confirm = useConfirm();
    const toast = useToast();

    const [groups, setGroups] = useState([]);
    const [devices, setDevices] = useState([]);
    const [loading, setLoading] = useState(true);
    const [editing, setEditing] = useState(null); // group object, or {} for "new"
    const [dispatching, setDispatching] = useState(null); // group id

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const [g, d] = await Promise.all([api.get('/device-groups'), api.get('/devices')]);
            setGroups(g.data ?? []);
            setDevices(d.data ?? []);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not load device groups.');
        } finally {
            setLoading(false);
        }
    }, [toast]);

    useEffect(() => {
        load();
    }, [load]);

    /** Throws ApiError(422) so the modal renders field errors inline. */
    async function save(payload) {
        if (editing?.id) {
            const res = await api.put(`/device-groups/${editing.id}`, {
                name: payload.name,
                description: payload.description,
            });
            /*
             * Membership is a separate endpoint because it is a separate decision —
             * renaming a group should never be able to silently change which terminals
             * it commands.
             */
            const withDevices = await api.put(`/device-groups/${editing.id}/devices`, {
                device_ids: payload.device_ids,
            });
            setGroups((prev) => prev.map((g) => (g.id === editing.id ? withDevices.data : g)));
            toast.success(res.message);
        } else {
            const res = await api.post('/device-groups', payload);
            setGroups((prev) => [...prev, res.data].sort((a, b) => a.name.localeCompare(b.name)));
            toast.success(res.message);
        }
        setEditing(null);
    }

    async function remove(group) {
        const ok = await confirm({
            title: `Remove the group ${group.name}?`,
            body: 'Its terminals are not affected — only the grouping is deleted.',
            confirmLabel: 'Remove group',
            danger: true,
        });
        if (!ok) return;

        try {
            const res = await api.delete(`/device-groups/${group.id}`);
            setGroups((prev) => prev.filter((g) => g.id !== group.id));
            toast.success(res.message);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not remove that group.');
        }
    }

    async function dispatchToGroup(group, command) {
        const memberCount = group.devices_count ?? group.devices?.length ?? 0;
        const ok = await confirm({
            title: `Run this on ${memberCount} terminal${memberCount === 1 ? '' : 's'}?`,
            body: `Each device in ${group.name} gets its own queued command, so a partial failure stays visible per terminal.`,
            confirmLabel: 'Queue it',
        });
        if (!ok) return;

        setDispatching(group.id);
        try {
            const res = await api.post(`/device-groups/${group.id}/commands`, { command });
            toast.success(res.message);
        } catch (err) {
            // 422 here means the group has no addressable terminals — real information.
            toast.error(err instanceof ApiError ? err.message : 'Could not queue that command.');
        } finally {
            setDispatching(null);
        }
    }

    const props = {
        groups,
        devices,
        dispatching,
        canCreate: can('devices.create'),
        canUpdate: can('devices.update'),
        canDelete: can('devices.delete'),
        onAdd: () => setEditing({}),
        /*
         * The index endpoint returns counts, not membership. Opening the editor with
         * only a count would let the form submit an empty device list and silently
         * empty the group, so the full record is fetched first.
         */
        onEdit: async (group) => {
            try {
                const res = await api.get(`/device-groups/${group.id}`);
                setEditing(res.data);
            } catch (err) {
                toast.error(err instanceof ApiError ? err.message : 'Could not open that group.');
            }
        },
        onDelete: remove,
        onDispatch: dispatchToGroup,
    };

    const Presentation = deviceClass === 'desktop' ? DeviceGroupsDesktop : DeviceGroupsMobile;

    return (
        <>
            {loading && groups.length === 0 ? <SkeletonTable rows={3} cols={4} /> : <Presentation {...props} />}
            {editing && (
                <DeviceGroupFormModal
                    group={editing.id ? editing : null}
                    devices={devices}
                    onSubmit={save}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}
