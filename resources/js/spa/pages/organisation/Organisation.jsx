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
import { buildOrg } from '../../shared/orgUnits';

/*
| Companies & departments (2026-10-12, owner's request). Owns the data and the
| writes; the layouts only render (rule 5). Since the companies table (2026-11-03) the
| two kinds are managed through their own endpoints — /companies, and /departments with
| a required company. Deleting is refused by the server (409) while anything is still
| inside, and that refusal is shown as it is.
*/
export default function Organisation() {
    const deviceClass = useDeviceClass();
    const { can, user } = useAuth();
    const confirm = useConfirm();
    const toast = useToast();
    const [companyRows, setCompanyRows] = useState([]);
    const [departmentRows, setDepartmentRows] = useState([]);
    const [unassigned, setUnassigned] = useState(0);
    const [loaded, setLoaded] = useState(false);
    // { kind: 'company'|'department', node?: row, companyId?: id }
    const [editing, setEditing] = useState(null);

    const load = useCallback(async () => {
        try {
            const [companies, departments] = await Promise.all([api.get('/companies'), api.get('/departments')]);
            setCompanyRows(companies.data ?? []);
            setDepartmentRows(departments.data ?? []);
            // People in no company: on /companies since 2026-11-03.
            setUnassigned(Number(companies.meta?.unassigned_count ?? 0));
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not load the companies.');
        } finally {
            setLoaded(true);
        }
    }, [toast]);

    useEffect(() => { load(); }, [load]);

    // LIVE (rule 18): companies and departments stamp the employees module, as do
    // employee moves, which change the head counts here.
    useMqtt(user?.tenant ? [`tenant/${user.tenant.id}/module/employees/+`] : [], () => load());

    const companies = useMemo(() => buildOrg(companyRows, departmentRows), [companyRows, departmentRows]);

    async function save(payload) {
        const { node, kind } = editing;
        const path = kind === 'company' ? '/companies' : '/departments';
        try {
            if (node) await api.put(`${path}/${node.id}`, payload);
            else await api.post(path, payload);
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

    // `kind` comes from the card that was clicked: a company and a department can
    // share an id, so the id alone cannot say which table to delete from.
    async function remove(node, kind) {
        const isCompany = kind === 'company';
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
            await api.delete(`${isCompany ? '/companies' : '/departments'}/${node.id}`);
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
        onAddDepartment: (company) => setEditing({ kind: 'department', companyId: company.id }),
        onEdit: (node, kind) => setEditing({ kind, node }),
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
                    companyId={editing.companyId ?? null}
                    companies={companies}
                    onSubmit={save}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}
