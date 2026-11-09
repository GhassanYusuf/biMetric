import { Fragment, useState } from 'react';
import { Button, Card, EmptyState, PageHeader, Pager, SkeletonTable, Spinner, TableShell, Thead } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';
import { EntryDetail, ErrorFilters, LevelBadge, SourceBadge, Summary, about, when } from './parts';

// Desktop: filters, a one-line summary, then a table; a row opens to the full entry.
export default function ErrorLogsDesktop({ rows, meta, grouped, pagination, loading, loaded, filters, onFilter, onClear, onPage }) {
    const [open, setOpen] = useState(null);
    const key = (r) => (grouped ? r.signature : r.id);
    const toggle = (k) => setOpen((o) => (o === k ? null : k));

    return (
        <div className="space-y-5">
            <PageHeader title="Error logs" subtitle="What went wrong on the server, with the terminals, and in people’s browsers">
                {loading && loaded && <Spinner className="h-5 w-5 text-violet-600" />}
            </PageHeader>

            <Card className="space-y-3 p-4">
                <ErrorFilters filters={filters} onFilter={onFilter} />
                <Summary counts={meta?.counts} entries={meta?.entries ?? 0} />
            </Card>

            {!loaded ? <SkeletonTable rows={8} cols={5} /> : rows.length === 0 ? (
                <Card><EmptyState title="Nothing went wrong" hint="No entry matches these filters." action={<Button variant="secondary" onClick={onClear}>Last 7 days, errors</Button>} /></Card>
            ) : (
                <TableShell>
                    <table className="min-w-full text-sm">
                        <Thead>
                            <tr>
                                <th className="px-5 py-3 text-left">{grouped ? 'Last seen' : 'When'}</th>
                                <th className="px-5 py-3 text-left">Where</th>
                                <th className="px-5 py-3 text-left">Level</th>
                                <th className="px-5 py-3 text-left">What</th>
                                {grouped && <th className="px-5 py-3 text-right">Times</th>}
                            </tr>
                        </Thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.map((r) => {
                                const entry = grouped ? r.sample : r;
                                const k = key(r);
                                return (
                                    <Fragment key={k}>
                                        <tr
                                            tabIndex={0}
                                            role="button"
                                            aria-expanded={open === k}
                                            onClick={() => toggle(k)}
                                            onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(k); } }}
                                            className={`cursor-pointer align-top hover:bg-violet-50/40 ${FOCUS}`}
                                        >
                                            <td className="whitespace-nowrap px-5 py-3 tabular-nums text-slate-600">
                                                {when(grouped ? r.last_at : r.at)}
                                                {grouped && r.count > 1 && <div className="text-xs text-slate-500">since {when(r.first_at)}</div>}
                                            </td>
                                            <td className="px-5 py-3"><SourceBadge source={r.source} /></td>
                                            <td className="px-5 py-3"><LevelBadge level={r.level} /></td>
                                            <td className="px-5 py-3">
                                                <div className="font-medium text-slate-900">{r.message}</div>
                                                {about(entry) && <div className="text-xs text-slate-500">{about(entry)}</div>}
                                            </td>
                                            {grouped && <td className="px-5 py-3 text-right font-semibold tabular-nums text-slate-800">{r.count.toLocaleString()}</td>}
                                        </tr>
                                        {open === k && (
                                            <tr className="bg-slate-50/60">
                                                <td colSpan={grouped ? 5 : 4} className="px-5 py-3">
                                                    {grouped && r.count > 1 && <p className="mb-2 text-xs text-slate-500">The latest of {r.count.toLocaleString()} like it:</p>}
                                                    <EntryDetail entry={entry} />
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                );
                            })}
                        </tbody>
                    </table>
                </TableShell>
            )}
            <Pager pagination={pagination} onPage={onPage} loading={loading} noun={grouped ? 'problems' : 'entries'} />
        </div>
    );
}
