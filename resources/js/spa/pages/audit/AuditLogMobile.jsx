import { useState } from 'react';
import { Button, Card, EmptyState, PageHeader, Pager, Skeleton, Spinner } from '../../shared/ui';
import { ActionBadge, AuditFilters, ChangesView, ROW_FOCUS, subjectLabel, useOpenRows, when } from './parts';

// Mobile: the same filters behind a button, entries as cards that open (rule 5).
export default function AuditLogMobile({ rows, pagination, users, actions, loading, loaded, filters, onFilter, onClear, onPage }) {
    const { open, toggle, keyToggle } = useOpenRows();
    const [showFilters, setShowFilters] = useState(false);

    return (
        <div className="space-y-4">
            <PageHeader title="Audit log" compact>
                {loading && loaded && <Spinner className="h-5 w-5 text-violet-600" />}
                <Button variant="secondary" className="min-h-[38px] px-3 text-sm" onClick={() => setShowFilters((v) => !v)} aria-expanded={showFilters}>Filters</Button>
            </PageHeader>

            {showFilters && <Card className="p-4"><AuditFilters filters={filters} onFilter={onFilter} users={users} actions={actions} stacked /></Card>}

            {!loaded ? (
                <div className="space-y-2" aria-busy="true" aria-label="Loading">
                    {Array.from({ length: 6 }, (_, i) => <Card key={i} className="p-3.5"><Skeleton className="h-3.5 w-40" /><Skeleton className="mt-2 h-2.5 w-56" /></Card>)}
                </div>
            ) : rows.length === 0 ? (
                <Card><EmptyState title="Nothing recorded" hint="No change matches these filters." action={<Button variant="secondary" onClick={onClear}>Clear filters</Button>} /></Card>
            ) : (
                <div className="space-y-2">
                    {rows.map((r) => (
                        <Card key={r.id} className="p-3.5">
                            <div
                                tabIndex={0}
                                role="button"
                                aria-expanded={open.has(r.id)}
                                onClick={() => toggle(r.id)}
                                onKeyDown={keyToggle(r.id)}
                                className={`rounded-lg ${ROW_FOCUS}`}
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <ActionBadge action={r.action} />
                                    <span className="text-xs tabular-nums text-slate-500">{when(r.created_at)}</span>
                                </div>
                                <div className="mt-1.5 text-sm text-slate-900">{r.actor ?? 'Automatic'}<span className="text-slate-500"> · {subjectLabel(r)}</span></div>
                            </div>
                            {open.has(r.id) && (
                                <div className="mt-2 border-t border-slate-100 pt-2">
                                    <ChangesView log={r} />
                                    {r.ip_address && <p className="mt-2 font-mono text-xs text-slate-500">From {r.ip_address}</p>}
                                </div>
                            )}
                        </Card>
                    ))}
                </div>
            )}
            <Pager pagination={pagination} onPage={onPage} loading={loading} noun="changes" />
        </div>
    );
}
