import { Button, Card, EmptyState, InlineAlert, PageHeader } from '../../shared/ui';
import { CompanyCard, people } from './parts';

// Desktop: every company as a card, side by side, its departments listed inside.
export default function OrganisationDesktop({ companies, unassigned, canManage, onAddCompany, onAddDepartment, onEdit, onDelete }) {
    return (
        <div className="space-y-5">
            <PageHeader title="Companies & departments" subtitle="The Company › Department tree employees are placed in">
                {canManage && <Button onClick={onAddCompany}>+ Add company</Button>}
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
                <div className="grid grid-cols-1 items-start gap-5 lg:grid-cols-2 2xl:grid-cols-3">
                    {companies.map((c) => (
                        <CompanyCard key={c.id} company={c} canManage={canManage} onAddDepartment={onAddDepartment} onEdit={onEdit} onDelete={onDelete} />
                    ))}
                </div>
            )}
        </div>
    );
}
