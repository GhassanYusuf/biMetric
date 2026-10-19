import { Button, Card, EmptyState, InlineAlert, PageHeader } from '../../shared/ui';
import { CompanyCard, people } from './parts';

// Mobile: the same company cards, stacked.
export default function OrganisationMobile({ companies, unassigned, canManage, onAddCompany, onAddDepartment, onEdit, onDelete }) {
    return (
        <div className="space-y-4">
            <PageHeader title="Companies & departments" compact>
                {canManage && <Button onClick={onAddCompany} className="px-3">+ Company</Button>}
            </PageHeader>

            {unassigned > 0 && (
                <InlineAlert tone="info">{people(unassigned)} {unassigned === 1 ? 'is' : 'are'} in no company. Set it in each employee's form.</InlineAlert>
            )}

            {companies.length === 0 ? (
                <Card>
                    <EmptyState
                        title="No companies yet"
                        hint="Add a company, then the departments inside it."
                        action={canManage && <Button onClick={onAddCompany}>+ Add your first company</Button>}
                    />
                </Card>
            ) : (
                <div className="space-y-3">
                    {companies.map((c) => (
                        <CompanyCard key={c.id} company={c} canManage={canManage} onAddDepartment={onAddDepartment} onEdit={onEdit} onDelete={onDelete} />
                    ))}
                </div>
            )}
        </div>
    );
}
