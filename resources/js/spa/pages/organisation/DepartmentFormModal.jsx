import { useState } from 'react';
import { ApiError } from '../../shared/api';
import { Button, Card, TextField } from '../../shared/ui';
import Combobox from '../../shared/Combobox';
import { useEscapeKey } from '../../shared/useEscapeKey';

/*
| Add or edit a company or a department. A company is a node with no parent; a
| department names its company, and can be moved to another one by changing it. A
| company is never turned into a department here: its own departments would be left
| two levels deep, which no screen expects.
|
| node      the row being edited, or null to add
| kind      'company' | 'department'
| parentId  the company a new department goes under
*/
export default function DepartmentFormModal({ node, kind, parentId = null, companies, onSubmit, onClose }) {
    const isEdit = !!node;
    const [name, setName] = useState(node?.name ?? '');
    const [companyId, setCompanyId] = useState(node?.parent_id ?? parentId);
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

    useEscapeKey(onClose, !submitting);

    const noun = kind === 'company' ? 'company' : 'department';

    async function submit(e) {
        e.preventDefault();
        const found = {};
        if (!name.trim()) found.name = [`Enter the ${noun}'s name.`];
        if (kind === 'department' && companyId == null) found.parent_id = ['Choose the company it belongs to.'];
        if (Object.keys(found).length) {
            setErrors(found);
            return;
        }
        setSubmitting(true);
        setErrors({});
        try {
            await onSubmit({ name: name.trim(), parent_id: kind === 'company' ? null : companyId });
        } catch (err) {
            // 422 → on the fields; anything else was already toasted by the page.
            if (err instanceof ApiError && err.errors) setErrors(err.errors);
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/40 p-0 sm:items-center sm:p-4" onClick={submitting ? undefined : onClose} role="presentation">
            <Card className="w-full max-w-md rounded-b-none sm:rounded-2xl" onClick={(e) => e.stopPropagation()}>
                <form onSubmit={submit} className="p-5" noValidate>
                    <h2 className="mb-4 text-lg font-semibold text-slate-900">
                        {isEdit ? `Edit ${node.name}` : `Add ${noun}`}
                    </h2>
                    <div className="space-y-3">
                        <TextField
                            label="Name"
                            placeholder={kind === 'company' ? 'e.g. PROMOSEVEN' : 'e.g. Marketing'}
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            error={errors.name?.[0]}
                            maxLength={255}
                            autoFocus
                            required
                        />
                        {kind === 'department' && (
                            <div>
                                <label htmlFor="department-company" className="mb-1.5 block text-sm font-medium text-slate-700">Company</label>
                                <Combobox
                                    id="department-company"
                                    options={companies.map((c) => ({ value: c.id, label: c.name }))}
                                    value={companyId}
                                    onChange={(v) => v != null && setCompanyId(v)}
                                    placeholder="Search…"
                                    emptyText="No company matches."
                                    error={errors.parent_id?.[0]}
                                />
                            </div>
                        )}
                    </div>
                    <div className="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>Cancel</Button>
                        <Button type="submit" loading={submitting}>
                            {isEdit ? 'Save' : `Add ${noun}`}
                        </Button>
                    </div>
                </form>
            </Card>
        </div>
    );
}
