import { useCallback, useEffect, useMemo, useState } from 'react';
import { useMqtt } from '../../shared/useMqtt';
import { api, ApiError } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useConfirm } from '../../shared/confirm';
import { useToast } from '../../shared/toast';
import { SkeletonCards } from '../../shared/ui';
import OrganisationDesktop from './OrganisationDesktop';
import OrganisationMobile from './OrganisationMobile';
import DepartmentFormModal from './DepartmentFormModal';
import { buildTree } from './parts';

/*
| Companies & departments (2026-10-12, owner's request). Owns the data and the
| writes; the layouts only render (rule 5). Everything goes through the existing
| /departments API (rule 13). Deleting is refused by the server while anything is
| still inside, and that refusal is shown as it is.
*/
export default function Organisation() {
    const deviceClass = useDeviceClass();
    const { can, user } = useAuth();
    const confirm = useConfirm();
    const toast = useToast();
    const [rows, setRows] = useState([]);
    const [unassigned, setUnassigned] = useState(0);
    const [loaded, setLoaded] = useState(false);
    // { kind: 'company'|'department', node?: row, parentId?: id }
    const [editing, setEditing] = useState(null);

    const load = useCallback(async () => {
        try {
            const res = await api.get('/departments');
            setRows(res.data ?? []);
            setUnassigned(Number(res.meta?.unassigned_count ?? 0));
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not load the companies.');
        } finally {
            setLoaded(true);
        }
    }, [toast]);

    useEffect(() => { load(); }, [load]);

    // LIVE (rule 18): departments stamp the employees module, as do employee moves,
    // which change the head counts here.
    useMqtt(user?.tenant ? [`tenant/${user.tenant.id}/module/employees/+`] : [], () => load());

    const companies = useMemo(() => buildTree(rows), [rows]);

    async function save(payload) {
        const { node, kind } = editing;
        try {
            if (node) await api.put(`/departments/${node.id}`, payload);
            else await api.post('/departments', payload);
        } catch (err) {
            if (!(err instanceof ApiError && err.status === 422)) {
                toast.error(err instanceof ApiError ? err.message : 'Could not save it.');
            }
            throw err; // the dialog stays open and shows field errors
        }
        toast.success(node
            ? `${payload.name} saved.`
            : `${kind === 'company' ? 'Company' : 'Department'} “${payload.name}” added.`);
        setEditing(null);
        load();
    }

    async function remove(node) {
        const isCompany = companies.some((c) => c.id === Number(node.id));
        const ok = await confirm({
            title: `Delete ${node.name}?`,
            body: isCompany
                ? 'Only an empty company can be deleted — move its departments and people out first.'
                : 'Only an empty department can be deleted — move its people to another department first.',
            confirmLabel: `Delete ${isCompany ? 'company' : 'department'}`,
            danger: true,
        });
        if (!ok) return;
        try {
            await api.delete(`/departments/${node.id}`);
            toast.success(`${node.name} deleted.`);
            load();
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : `Could not delete ${node.name}.`);
        }
    }

    const props = {
        companies,
        unassigned,
        canManage: can('departments.manage'),
        onAddCompany: () => setEditing({ kind: 'company' }),
        onAddDepartment: (company) => setEditing({ kind: 'department', parentId: company.id }),
        onEdit: (node) => setEditing({ kind: node.parent_id == null || !rows.some((r) => Number(r.id) === Number(node.parent_id)) ? 'company' : 'department', node }),
        onDelete: remove,
    };

    const Presentation = deviceClass === 'desktop' ? OrganisationDesktop : OrganisationMobile;

    return (
        <>
            {!loaded ? <SkeletonCards count={4} height="h-40" /> : <Presentation {...props} />}
            {editing && (
                <DepartmentFormModal
                    node={editing.node ?? null}
                    kind={editing.kind}
                    parentId={editing.parentId ?? null}
                    companies={companies}
                    onSubmit={save}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}
