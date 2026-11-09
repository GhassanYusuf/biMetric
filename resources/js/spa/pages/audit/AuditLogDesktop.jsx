import { Fragment } from 'react';
import { Button, Card, EmptyState, PageHeader, Pager, SkeletonTable, Spinner, TableShell, Thead } from '../../shared/ui';
import { ActionBadge, AuditFilters, ChangesView, ROW_FOCUS, subjectLabel, useOpenRows, when } from './parts';

// Desktop: filters above a table; a row opens to show what changed.
export default function AuditLogDesktop({ rows, pagination, users, actions, loading, loaded, filters, onFilter, onClear, onPage }) {
    const { open, toggle, keyToggle } = useOpenRows();
    const total = pagination?.total;

    return (
        <div className="space-y-5">
            <PageHeader title="Audit log" subtitle={total != null ? `${total.toLocaleString()} recorded change${total === 1 ? '' : 's'} matching` : 'Who changed what, and when'}>
                {loading && loaded && <Spinner className="h-5 w-5 text-violet-600" />}
            </PageHeader>

            <Card className="p-4">
                <AuditFilters filters={filters} onFilter={onFilter} users={users} actions={actions} />
            </Card>

            {!loaded ? <SkeletonTable rows={8} cols={5} /> : rows.length === 0 ? (
                <Card><EmptyState title="Nothing recorded" hint="No change matches these filters." action={<Button variant="secondary" onClick={onClear}>Clear filters</Button>} /></Card>
            ) : (
                <TableShell>
                    <table className="min-w-full text-sm">
                        <Thead>
                            <tr>
                                <th className="px-5 py-3 text-left">When</th>
                                <th className="px-5 py-3 text-left">Who</th>
                                <th className="px-5 py-3 text-left">Action</th>
                                <th className="px-5 py-3 text-left">On</th>
                                <th className="px-5 py-3 text-left">From</th>
                            </tr>
                        </Thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.map((r) => (
                                <Fragment key={r.id}>
                                    <tr
                                        tabIndex={0}
                                        role="button"
                                        aria-expanded={open.has(r.id)}
                                        onClick={() => toggle(r.id)}
                                        onKeyDown={keyToggle(r.id)}
                                        className={`cursor-pointer hover:bg-violet-50/40 ${ROW_FOCUS}`}
                                    >
                                        <td className="whitespace-nowrap px-5 py-3 tabular-nums text-slate-600">{when(r.created_at)}</td>
                                        <td className="px-5 py-3 font-medium text-slate-900">{r.actor ?? <span className="font-normal text-slate-500">Automatic</span>}</td>
                                        <td className="px-5 py-3"><ActionBadge action={r.action} /></td>
                                        <td className="px-5 py-3 text-slate-700">{subjectLabel(r)}</td>
                                        <td className="px-5 py-3 font-mono text-xs text-slate-500">{r.ip_address ?? '—'}</td>
                                    </tr>
                                    {open.has(r.id) && (
                                        <tr className="bg-slate-50/60">
                                            <td colSpan={5} className="px-5 py-3">
                                                <ChangesView changes={r.changes} />
                                                {r.user_agent && <p className="mt-2 truncate text-xs text-slate-500" title={r.user_agent}>Browser: {r.user_agent}</p>}
                                            </td>
                                        </tr>
                                    )}
                                </Fragment>
                            ))}
                        </tbody>
                    </table>
                </TableShell>
            )}
            <Pager pagination={pagination} onPage={onPage} loading={loading} noun="changes" />
        </div>
    );
}
