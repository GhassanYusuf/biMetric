import { api } from './api';

/*
| Companies and departments, as every screen that groups people reads them
| (companies table, 2026-11-03).
|
| Until then a company was a `departments` row with no parent, and one tree-node id
| (`department_id`) stood for either. Now they are two tables, each department sits
| flat under exactly one company, and the two id spaces overlap — company 21 and
| department 21 can both exist. So a node is never a bare id on screen: it is a KEY,
|
|   'c:4'   company 4 (everyone in it, its departments included)
|   'd:13'  department 13
|   'none'  people in no company (the tree's "Unassigned")
|   ''      everyone
|
| and `setOrgParams` turns that key into the `company_id` / `department_id` the server
| filters on (App\Support\OrgScope). One place, so no screen can send a company id as a
| department id again.
*/

export const companyKey = (id) => `c:${Number(id)}`;
export const departmentKey = (id) => `d:${Number(id)}`;

/** 'c:4' → { company_id: 4 }, 'd:13' → { department_id: 13 }, 'none' → { company_id: 'none' }, '' → {}. */
export function orgParams(key) {
    if (key === 'none') return { company_id: 'none' };
    const match = /^([cd]):(\d+)$/.exec(key ?? '');
    if (!match) return {};
    return match[1] === 'c' ? { company_id: Number(match[2]) } : { department_id: Number(match[2]) };
}

/** Adds the key's filter to a query string being built. */
export function setOrgParams(params, key) {
    Object.entries(orgParams(key)).forEach(([k, v]) => params.set(k, String(v)));
    return params;
}

/**
 * GET /companies and GET /departments together. Either refused (no departments.view)
 * reads as "no grouping offered", not an error the operator can do anything about.
 * `meta.unassigned_count` — people in no company — comes with the companies.
 */
export async function fetchOrg() {
    const [companies, departments] = await Promise.all([
        api.get('/companies').catch(() => null),
        api.get('/departments').catch(() => null),
    ]);
    return {
        companies: companies?.data ?? [],
        departments: departments?.data ?? [],
        unassignedCount: Number(companies?.meta?.unassigned_count ?? 0),
    };
}

/**
 * Companies by name, each with its departments by name. Ids are numbers throughout:
 * SQL Server hands some back as strings ("4"). `direct` is the people on the company
 * with no department — `employees_count` on a company already includes its departments'.
 * A department whose company is not in the list (soft-deleted, or another tenant's) is
 * left out rather than shown under nothing.
 */
export function buildOrg(companies, departments) {
    const byName = (a, b) => a.name.localeCompare(b.name);
    const list = companies.map((c) => ({
        ...c,
        id: Number(c.id),
        employees_count: Number(c.employees_count ?? 0),
        departments: [],
    }));
    const byId = new Map(list.map((c) => [c.id, c]));
    departments.forEach((d) => {
        const company = byId.get(Number(d.company_id));
        if (!company) return;
        company.departments.push({ ...d, id: Number(d.id), company_id: company.id, employees_count: Number(d.employees_count ?? 0) });
    });
    list.forEach((c) => {
        c.departments.sort(byName);
        c.direct = Math.max(0, c.employees_count - c.departments.reduce((n, d) => n + d.employees_count, 0));
    });
    return list.sort(byName);
}

/**
 * The <option>s of a single "which company or department" select: a group per company,
 * the company itself first (everyone in it), then its departments. Values are keys.
 * `unassigned` adds "In no company" (company_id=none).
 */
export function OrgOptions({ org, unassigned = false }) {
    return (
        <>
            {org.map((c) => (
                <optgroup key={c.id} label={c.name}>
                    <option value={companyKey(c.id)}>{`All of ${c.name}`}</option>
                    {c.departments.map((d) => (
                        <option key={d.id} value={departmentKey(d.id)}>{d.name}</option>
                    ))}
                </optgroup>
            ))}
            {unassigned && <option value="none">In no company</option>}
        </>
    );
}
