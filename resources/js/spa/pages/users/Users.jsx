import { useCallback, useEffect, useState } from 'react';
import { useMqtt } from '../../shared/useMqtt';
import { api, ApiError } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useConfirm } from '../../shared/confirm';
import { useToast } from '../../shared/toast';
import UsersDesktop from './UsersDesktop';
import UsersMobile from './UsersMobile';
import { LevelModal, PasswordModal, UserFormModal } from './parts';

/*
| Users and permission levels (owner, 2026-10-19: "add users tab that has permissions
| levels and add users"). Owns the data and every write; the layouts only render
| (rule 5). /users and /roles are UserController and RoleController, which enforce the
| lock-out and no-climbing rules; refusals arrive as toasts with the server's reason.
*/
export default function Users() {
    const deviceClass = useDeviceClass();
    const { can, user: me, refresh } = useAuth();
    const confirm = useConfirm();
    const toast = useToast();
    const [tab, setTab] = useState('users');
    const [users, setUsers] = useState([]);
    const [levels, setLevels] = useState([]);
    const [groups, setGroups] = useState([]);
    const [loaded, setLoaded] = useState(false);
    // { kind: 'user'|'password'|'level', row?: … }
    const [editing, setEditing] = useState(null);

    const load = useCallback(async () => {
        try {
            const [u, r] = await Promise.all([
                api.get('/users'),
                can('roles.view') ? api.get('/roles') : Promise.resolve(null),
            ]);
            setUsers(u.data ?? []);
            if (r) {
                setLevels(r.data ?? []);
                setGroups(r.meta?.groups ?? []);
            }
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not load the users.');
        } finally {
            setLoaded(true);
        }
    }, [can, toast]);

    useEffect(() => { load(); }, [load]);

    // LIVE (rule 18): another admin adding a user or editing a level shows here at once.
    // Not auth's refresh() here: it signs out on any failed request, and a live tick
    // must never turn a network blip into a sign-out. It runs after saving a level.
    useMqtt(me?.tenant ? [`tenant/${me.tenant.id}/module/users/+`] : [], () => load());

    function failed(err, fallback) {
        // 422 stays in the dialog, on its fields (rule 2); everything else is an event.
        if (!(err instanceof ApiError && err.status === 422 && err.errors && Object.keys(err.errors).length)) {
            toast.error(err instanceof ApiError ? err.message : fallback);
        }
        throw err;
    }

    async function saveUser(payload) {
        const row = editing.row;
        let res;
        try {
            res = row ? await api.put(`/users/${row.id}`, payload) : await api.post('/users', payload);
        } catch (err) {
            failed(err, 'Could not save the user.');
        }
        toast.success(res.message);
        setEditing(null);
        load();
    }

    async function setPassword(password) {
        let res;
        try {
            res = await api.put(`/users/${editing.row.id}/password`, { password });
        } catch (err) {
            failed(err, 'Could not change the password.');
        }
        toast.success(res.message);
        setEditing(null);
    }

    async function toggleActive(row) {
        if (row.is_active) {
            const ok = await confirm({
                title: `Deactivate ${row.name}?`,
                body: [`${row.name} is signed out at once and cannot sign in until reactivated.`, 'Nothing they did is removed.'],
                confirmLabel: 'Deactivate',
                danger: true,
            });
            if (!ok) return;
        }
        try {
            const res = await api.put(`/users/${row.id}`, { is_active: !row.is_active });
            toast.success(row.is_active ? res.message : `${row.name} can sign in again.`);
            load();
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : `Could not change ${row.name}.`);
        }
    }

    async function removeUser(row) {
        const ok = await confirm({
            title: `Delete ${row.name}?`,
            body: ['Only an account that has never changed anything can be deleted — otherwise deactivate it, so the history keeps their name.'],
            confirmLabel: 'Delete user',
            danger: true,
        });
        if (!ok) return;
        try {
            const res = await api.delete(`/users/${row.id}`);
            toast.success(res.message);
            load();
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : `Could not delete ${row.name}.`);
        }
    }

    async function saveLevel(payload) {
        const row = editing.row;
        let res;
        try {
            res = row ? await api.put(`/roles/${row.id}`, payload) : await api.post('/roles', payload);
        } catch (err) {
            failed(err, 'Could not save the level.');
        }
        toast.success(res.message);
        setEditing(null);
        load();
        refresh?.();
    }

    async function removeLevel(level) {
        const ok = await confirm({
            title: `Delete the ${level.label} level?`,
            body: level.users_count > 0
                ? `${level.users_count} user${level.users_count === 1 ? ' has' : 's have'} it — give them another level first.`
                : 'Nobody has this level. It can be created again later.',
            confirmLabel: 'Delete level',
            danger: true,
        });
        if (!ok) return;
        try {
            const res = await api.delete(`/roles/${level.id}`);
            toast.success(res.message);
            load();
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : `Could not delete ${level.label}.`);
        }
    }

    const props = {
        loaded,
        tab,
        onTab: setTab,
        showLevels: can('roles.view'),
        users,
        levels,
        groups,
        can,
        onAddUser: () => setEditing({ kind: 'user' }),
        onEdit: (row) => setEditing({ kind: 'user', row }),
        onPassword: (row) => setEditing({ kind: 'password', row }),
        onToggleActive: toggleActive,
        onDelete: removeUser,
        onAddLevel: () => setEditing({ kind: 'level' }),
        onEditLevel: (row) => setEditing({ kind: 'level', row }),
        onDeleteLevel: removeLevel,
    };

    const Presentation = deviceClass === 'desktop' ? UsersDesktop : UsersMobile;

    return (
        <>
            <Presentation {...props} />
            {editing?.kind === 'user' && (
                <UserFormModal user={editing.row ?? null} levels={levels} myPermissions={me?.permissions} onSubmit={saveUser} onClose={() => setEditing(null)} />
            )}
            {editing?.kind === 'password' && (
                <PasswordModal user={editing.row} onSubmit={setPassword} onClose={() => setEditing(null)} />
            )}
            {editing?.kind === 'level' && (
                <LevelModal level={editing.row ?? null} groups={groups} canManage={can('roles.manage')} myPermissions={me?.permissions} onSubmit={saveLevel} onClose={() => setEditing(null)} />
            )}
        </>
    );
}
