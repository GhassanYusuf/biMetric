import { ActionMenu, Button, Card, EmptyState, PageHeader, SectionCard, SkeletonCards, SkeletonTable, Spinner, TableShell, Thead } from '../../shared/ui';
import { StatusBadge, SystemJobList, describe, jobActions, terminalsLabel, when } from './parts';

// Desktop: the company's jobs as a table, the system's own below it.
export default function CronJobsDesktop(props) {
    const { loaded, meta, canManage, onAdd } = props;

    return (
        <div className="space-y-5">
            <PageHeader title="Cron jobs" subtitle={`What runs on its own, and when — in ${meta.timezone || 'company time'}`}>
                {canManage && <Button onClick={onAdd}>+ Add job</Button>}
            </PageHeader>

            {!loaded ? <SkeletonTable rows={3} cols={7} /> : <JobTable {...props} />}

            <SectionCard title="Built into the system" subtitle="Always on and not changed here. Times are the server's (UTC).">
                {!loaded ? <SkeletonCards count={3} height="h-10" /> : <SystemJobList jobs={meta.system_jobs ?? []} zone={meta.timezone} />}
            </SectionCard>
        </div>
    );
}

function JobTable({ jobs, meta, busy, canManage, onAdd, ...handlers }) {
    if (jobs.length === 0) {
        return (
            <Card>
                <EmptyState title="No cron jobs" hint="Nothing reads punches or sets clocks on its own." action={canManage && <Button onClick={onAdd}>+ Add job</Button>} />
            </Card>
        );
    }

    return (
        <TableShell>
            <Thead>
                <tr>
                    <th scope="col" className="px-5 py-3 text-left">Job</th>
                    <th scope="col" className="px-5 py-3 text-left">When</th>
                    <th scope="col" className="px-5 py-3 text-left">Terminals</th>
                    <th scope="col" className="px-5 py-3 text-left">Next run</th>
                    <th scope="col" className="px-5 py-3 text-left">Last run</th>
                    <th scope="col" className="px-5 py-3 text-left">Status</th>
                    <th scope="col" className="px-5 py-3"><span className="sr-only">Actions</span></th>
                </tr>
            </Thead>
            <tbody className="divide-y divide-slate-100">
                {jobs.map((j) => {
                    const items = jobActions(j, { canManage, ...handlers });
                    return (
                        <tr key={j.id} className={j.is_enabled ? '' : 'bg-slate-50/60'}>
                            <td className="px-5 py-3">
                                <div className="font-medium text-slate-900">{j.name}</div>
                                <div className="text-xs text-slate-500">{j.action_label}</div>
                            </td>
                            <td className="px-5 py-3 text-slate-700">
                                {describe(j.schedule)}
                                <div className="text-xs text-slate-500"><code>{j.schedule}</code></div>
                            </td>
                            <td className="px-5 py-3 text-slate-700">{terminalsLabel(j, meta.devices ?? [])}</td>
                            <td className="px-5 py-3 text-slate-700">{j.is_enabled ? when(j.next_runs[0], meta.timezone) : <span className="text-slate-500">Paused</span>}</td>
                            <td className="px-5 py-3 text-slate-600">
                                {j.last_run_at ? when(j.last_run_at, meta.timezone) : <span className="text-slate-500">Not yet</span>}
                                {j.last_result && <div className="max-w-xs text-xs text-slate-500">{j.last_result}</div>}
                            </td>
                            <td className="px-5 py-3"><StatusBadge job={j} /></td>
                            <td className="px-5 py-3 text-right">
                                {busy === j.id ? <Spinner className="ml-auto h-4 w-4" /> : items.length > 0 && <ActionMenu label="Actions" className="min-h-[36px] px-3 text-xs" items={items} />}
                            </td>
                        </tr>
                    );
                })}
            </tbody>
        </TableShell>
    );
}
