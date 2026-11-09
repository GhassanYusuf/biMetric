import { useState } from 'react';
import { Button, Card, EmptyState, PageHeader, Pager, Skeleton, Spinner } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';
import { EntryDetail, ErrorFilters, LevelBadge, SourceBadge, Summary, about, when } from './parts';

// Mobile: the same filters behind a button, entries as cards that open (rule 5).
export default function ErrorLogsMobile({ rows, meta, grouped, pagination, loading, loaded, filters, onFilter, onClear, onPage }) {
    const [open, setOpen] = useState(null);
    const [showFilters, setShowFilters] = useState(false);
    const toggle = (k) => setOpen((o) => (o === k ? null : k));

    return (
        <div className="space-y-4">
            <PageHeader title="Error logs" compact>
                {loading && loaded && <Spinner className="h-5 w-5 text-violet-600" />}
                <Button variant="secondary" className="min-h-[38px] px-3 text-sm" onClick={() => setShowFilters((v) => !v)} aria-expanded={showFilters}>Filters</Button>
            </PageHeader>

            {showFilters && <Card className="p-4"><ErrorFilters filters={filters} onFilter={onFilter} stacked /></Card>}
            <Summary counts={meta?.counts} entries={meta?.entries ?? 0} />

            {!loaded ? (
                <div className="space-y-2" aria-busy="true" aria-label="Loading">
                    {Array.from({ length: 6 }, (_, i) => <Card key={i} className="p-3.5"><Skeleton className="h-3.5 w-48" /><Skeleton className="mt-2 h-2.5 w-32" /></Card>)}
                </div>
            ) : rows.length === 0 ? (
                <Card><EmptyState title="Nothing went wrong" hint="No entry matches these filters." action={<Button variant="secondary" onClick={onClear}>Last 7 days, errors</Button>} /></Card>
            ) : (
                <div className="space-y-2">
                    {rows.map((r) => {
                        const entry = grouped ? r.sample : r;
                        const k = grouped ? r.signature : r.id;
                        return (
                            <Card key={k} className="p-3.5">
                                <div
                                    tabIndex={0}
                                    role="button"
                                    aria-expanded={open === k}
                                    onClick={() => toggle(k)}
                                    onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(k); } }}
                                    className={`rounded-lg ${FOCUS}`}
                                >
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        <SourceBadge source={r.source} />
                                        <LevelBadge level={r.level} />
                                        {grouped && r.count > 1 && <span className="text-xs font-semibold text-slate-700">×{r.count.toLocaleString()}</span>}
                                        <span className="ml-auto text-xs tabular-nums text-slate-500">{when(grouped ? r.last_at : r.at)}</span>
                                    </div>
                                    <div className="mt-1.5 text-sm font-medium text-slate-900">{r.message}</div>
                                    {about(entry) && <div className="text-xs text-slate-500">{about(entry)}</div>}
                                </div>
                                {open === k && <div className="mt-2 border-t border-slate-100 pt-2"><EntryDetail entry={entry} /></div>}
                            </Card>
                        );
                    })}
                </div>
            )}
            <Pager pagination={pagination} onPage={onPage} loading={loading} noun={grouped ? 'problems' : 'entries'} />
        </div>
    );
}
