import { useCallback, useEffect, useState } from 'react';
import { useMqtt } from '../../shared/useMqtt';
import { api, ApiError } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useConfirm } from '../../shared/confirm';
import { useToast } from '../../shared/toast';
import CronJobsDesktop from './CronJobsDesktop';
import CronJobsMobile from './CronJobsMobile';
import { JobFormModal, describe } from './parts';

/*
| Cron jobs (owner, 2026-10-20: "a tab that shows the cron jobs that exist, lets users
| add another and tinker with the time"). Owns the data and every write; the layouts
| only render (rule 5). /scheduled-jobs is ScheduledJobController. The company's jobs
| are editable; the system's own are listed so nothing runs unseen.
*/
export default function CronJobs() {
    const deviceClass = useDeviceClass();
    const { can, user: me } = useAuth();
    const confirm = useConfirm();
    const toast = useToast();
    const [jobs, setJobs] = useState([]);
    const [meta, setMeta] = useState({ actions: [], devices: [], system_jobs: [], timezone: '' });
    const [loaded, setLoaded] = useState(false);
    // { row?: job } while the dialog is open
    const [editing, setEditing] = useState(null);
    const [busy, setBusy] = useState(null);

    const load = useCallback(async () => {
        try {
            const res = await api.get('/scheduled-jobs');
            setJobs(res.data ?? []);
            setMeta(res.meta ?? {});
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not load the cron jobs.');
        } finally {
            setLoaded(true);
        }
    }, [toast]);

    useEffect(() => { load(); }, [load]);

    // LIVE (rule 18): another admin's change, and every run (it stamps the job), show here.
    useMqtt(me?.tenant ? [`tenant/${me.tenant.id}/module/schedules/+`] : [], () => load());

    async function save(payload) {
        const row = editing.row;
        let res;
        try {
            res = row ? await api.put(`/scheduled-jobs/${row.id}`, payload) : await api.post('/scheduled-jobs', payload);
        } catch (err) {
            // 422 stays in the dialog, on its fields (rule 2); everything else is an event.
            if (!(err instanceof ApiError && err.status === 422 && err.errors && Object.keys(err.errors).length)) {
                toast.error(err instanceof ApiError ? err.message : 'Could not save the job.');
            }
            throw err;
        }
        toast.success(res.message);
        setEditing(null);
        load();
    }

    async function run(job) {
        const ok = await confirm({
            title: `Run “${job.name}” now?`,
            body: [`${job.action_label}, on ${job.device_ids ? `${job.device_ids.length} terminal${job.device_ids.length === 1 ? '' : 's'}` : 'every terminal'}, straight away.`, 'Its schedule does not change.'],
            confirmLabel: 'Run now',
        });
        if (!ok) return;
        setBusy(job.id);
        try {
            const res = await api.post(`/scheduled-jobs/${job.id}/run`);
            toast.success(res.message);
            load();
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : `Could not run ${job.name}.`);
        } finally {
            setBusy(null);
        }
    }

    async function toggle(job) {
        setBusy(job.id);
        try {
            const res = await api.put(`/scheduled-jobs/${job.id}`, { is_enabled: !job.is_enabled });
            toast.success(res.message);
            load();
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : `Could not change ${job.name}.`);
        } finally {
            setBusy(null);
        }
    }

    async function remove(job) {
        const ok = await confirm({
            title: `Delete “${job.name}”?`,
            body: [`${job.action_label} — ${describe(job.schedule)}. It will not run again.`, 'Pause it instead to keep it for later.'],
            confirmLabel: 'Delete job',
            danger: true,
        });
        if (!ok) return;
        try {
            const res = await api.delete(`/scheduled-jobs/${job.id}`);
            toast.success(res.message);
            load();
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : `Could not delete ${job.name}.`);
        }
    }

    const props = {
        loaded,
        jobs,
        meta,
        busy,
        canManage: can('settings.manage'),
        onAdd: () => setEditing({}),
        onEdit: (row) => setEditing({ row }),
        onRun: run,
        onToggle: toggle,
        onDelete: remove,
    };

    const Presentation = deviceClass === 'desktop' ? CronJobsDesktop : CronJobsMobile;

    return (
        <>
            <Presentation {...props} />
            {editing && (
                <JobFormModal
                    job={editing.row ?? null}
                    actions={meta.actions ?? []}
                    devices={meta.devices ?? []}
                    zone={meta.timezone}
                    onSubmit={save}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}
