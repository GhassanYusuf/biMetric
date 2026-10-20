import { ActionMenu, Button, Card, EmptyState, PageHeader, SectionCard, SkeletonCards, Spinner } from '../../shared/ui';
import { StatusBadge, SystemJobList, describe, jobActions, terminalsLabel, when } from './parts';

// Mobile: the company's jobs as stacked cards, the system's own below — the same actions as desktop.
export default function CronJobsMobile(props) {
    const { loaded, meta, canManage, onAdd } = props;

    return (
        <div className="space-y-4">
            <PageHeader title="Cron jobs" compact>
                {canManage && <Button onClick={onAdd} className="px-3">+ Job</Button>}
            </PageHeader>

            {!loaded ? <SkeletonCards count={3} height="h-32" /> : <JobCards {...props} />}

            <SectionCard title="Built into the system" subtitle="Always on and not changed here. Times are UTC.">
                {!loaded ? <SkeletonCards count={3} height="h-10" /> : <SystemJobList jobs={meta.system_jobs ?? []} zone={meta.timezone} />}
            </SectionCard>
        </div>
    );
}

function JobCards({ jobs, meta, busy, canManage, onAdd, ...handlers }) {
    if (jobs.length === 0) {
        return (
            <Card>
                <EmptyState title="No cron jobs" hint="Nothing reads punches or sets clocks on its own." action={canManage && <Button onClick={onAdd}>+ Add job</Button>} />
            </Card>
        );
    }

    return (
        <ul className="space-y-3">
            {jobs.map((j) => {
                const items = jobActions(j, { canManage, ...handlers });
                return (
                    <li key={j.id}>
                        <Card className="p-4">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="truncate font-medium text-slate-900">{j.name}</div>
                                    <div className="truncate text-xs text-slate-500">{j.action_label}</div>
                                </div>
                                {busy === j.id ? <Spinner className="h-4 w-4 shrink-0" /> : items.length > 0 && <ActionMenu label="Actions" className="min-h-[36px] shrink-0 px-3 text-xs" items={items} />}
                            </div>
                            <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm">
                                <StatusBadge job={j} />
                                <span className="text-slate-700">{describe(j.schedule)}</span>
                            </div>
                            <div className="mt-1.5 space-y-0.5 text-xs text-slate-600">
                                <div>{terminalsLabel(j, meta.devices ?? [])}</div>
                                <div>Next: {j.is_enabled ? when(j.next_runs[0], meta.timezone) : 'paused'}</div>
                                <div>Last: {j.last_run_at ? `${when(j.last_run_at, meta.timezone)} — ${j.last_result ?? ''}` : 'not yet'}</div>
                            </div>
                        </Card>
                    </li>
                );
            })}
        </ul>
    );
}
