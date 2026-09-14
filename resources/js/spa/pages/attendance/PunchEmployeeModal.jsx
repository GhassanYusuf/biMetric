import { useEffect, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useEscapeKey } from '../../shared/useEscapeKey';
import { useToast } from '../../shared/toast';
import { Badge, Button, Card, InlineAlert, Skeleton } from '../../shared/ui';
import { CardCell, FingerprintCell, TerminalsCell } from '../employees/enrollmentCells';
import { formatPunch, verifyLabel } from './parts';

// How many of this person's most recent punches the dialog lists. Enough to see
// a shift pattern without turning the dialog into a second attendance page.
const RECENT = 8;

/*
| Who is behind one punch, opened by clicking its row.
|
| Two questions an operator has when a line in the log looks wrong — "who is
| this?" and "is this normal for them?" — and both were previously a trip to the
| Employees screen and a search by code.
|
| The employee record is fetched fresh rather than taken from the row: a punch
| carries only a name and the device's user id, and the interesting part (which
| terminals hold them, which fingers, which card) is not in the attendance
| payload at all. The enrollment cells are the roster's, unchanged, so the three
| states rule 10 insists on — never read / unknown / a real value — stay drawn
| exactly as they are everywhere else.
|
| Read-only. Enrollment actions live in the Employees screen, which is where
| someone goes to change something; this answers a question about a punch.
*/
export default function PunchEmployeeModal({ log, onClose }) {
    const { can } = useAuth();
    const toast = useToast();

    // A user with attendance.view need not have employees.view. Asking anyway
    // would spend a request to earn a 403, so the profile block is skipped and
    // the dialog says why.
    const mayViewEmployee = can('employees.view');

    const [employee, setEmployee] = useState(null);
    const [recent, setRecent] = useState(null);
    const [loading, setLoading] = useState(true);
    const [exporting, setExporting] = useState(false);

    // Not while a download is being produced — the dialog is what holds the
    // request's context, same guard the enrollment dialog uses for writes.
    useEscapeKey(onClose, !exporting);

    useEffect(() => {
        let alive = true;
        setLoading(true);
        setEmployee(null);
        setRecent(null);

        const profile = mayViewEmployee
            ? api.get(`/employees/${log.employee_id}`).then((res) => res.data)
            : Promise.resolve(null);

        // The list endpoint already scopes an employee-role user to their own
        // punches and ignores the filter for them, which is the same answer here.
        const punches = api
            .get(`/attendance?employee_id=${log.employee_id}&per_page=${RECENT}`)
            .then((res) => res.data ?? []);

        Promise.all([profile, punches])
            .then(([emp, rows]) => {
                if (!alive) return;
                setEmployee(emp);
                setRecent(rows);
            })
            .catch((err) => {
                if (!alive) return;
                toast.error(err instanceof ApiError ? err.message : 'Could not load this employee.');
            })
            .finally(() => {
                if (alive) setLoading(false);
            });

        return () => {
            alive = false;
        };
        // toast is stable for the life of the provider; re-running on it would refetch forever.
    }, [log.employee_id, mayViewEmployee]); // eslint-disable-line react-hooks/exhaustive-deps

    /*
     * The employee's own attendance record, over the summary service's default
     * window (the last 30 days). The PDF prints the dates it used, so the file
     * always says what it covers even though this dialog has no range control.
     */
    async function exportPdf() {
        setExporting(true);
        try {
            const name = await api.download(
                `/exports/employees/${log.employee_id}/attendance`,
                'attendance.pdf',
            );
            toast.success(`Saved ${name}.`);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not produce the PDF.');
        } finally {
            setExporting(false);
        }
    }

    const punch = formatPunch(log.punched_at);
    const name = employee?.name ?? log.employee_name ?? 'This employee';
    const code = employee?.employee_code ?? log.employee_code;
    // `department` is the free-text column; `department_name` comes from the
    // departments table when the employee is linked to one. Either is the answer
    // to "which department", so whichever exists is shown.
    const department = employee?.department || employee?.department_name;

    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-slate-900/40 p-0 sm:items-center sm:p-4"
            onClick={exporting ? undefined : onClose}
            role="presentation"
        >
            <Card
                className="w-full max-w-lg rounded-b-none sm:rounded-2xl"
                onClick={(e) => e.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-label={`${name} — punch detail`}
            >
                <div className="p-5">
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <h2 className="truncate text-lg font-semibold tracking-[-0.01em] text-slate-900">{name}</h2>
                            <p className="mt-0.5 text-sm text-slate-500">
                                {code && <span className="font-mono text-xs">{code}</span>}
                                {department ? `${code ? ' · ' : ''}${department}` : ''}
                            </p>
                        </div>
                        {employee && !employee.is_active && <Badge tone="amber">Inactive</Badge>}
                    </div>

                    {/* The punch that was clicked — the reason this dialog is open. */}
                    <div className="mt-4 rounded-xl border border-violet-200 bg-violet-50/50 p-4">
                        <div className="text-xs font-semibold uppercase tracking-wide text-violet-700">
                            Selected punch
                        </div>
                        <div className="mt-2 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <span className="text-sm text-slate-700">
                                {log.device_name || 'Unknown device'} · {verifyLabel(log.verify_method)}
                            </span>
                            <span className="text-sm font-semibold tabular-nums text-slate-900">
                                {punch.date} {punch.time}
                            </span>
                        </div>
                    </div>

                    {!mayViewEmployee && (
                        <div className="mt-4">
                            <InlineAlert tone="info">
                                Your account can read attendance but not employee records, so only this
                                person&rsquo;s punches are shown.
                            </InlineAlert>
                        </div>
                    )}

                    {mayViewEmployee && (
                        <div className="mt-4 rounded-xl border border-slate-200 p-4">
                            <h3 className="text-sm font-semibold text-slate-800">Employee</h3>

                            {loading ? (
                                <div className="mt-3 space-y-2">
                                    <Skeleton className="h-3.5 w-48" />
                                    <Skeleton className="h-3.5 w-36" />
                                    <Skeleton className="h-3.5 w-56" />
                                </div>
                            ) : employee ? (
                                <>
                                    <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                        <Fact label="Department" value={department} />
                                        <Fact label="Email" value={employee.email} />
                                        <Fact
                                            label="Status"
                                            value={employee.is_active ? 'Active' : 'Inactive'}
                                        />
                                        <Fact
                                            label="Punches recorded"
                                            value={
                                                employee.attendance_logs_count != null
                                                    ? employee.attendance_logs_count.toLocaleString()
                                                    : null
                                            }
                                        />
                                    </dl>

                                    {/*
                                      * What the terminals hold for them. Reused from the
                                      * roster so "never read", "unknown" and a real count
                                      * keep the one appearance they have everywhere else.
                                      */}
                                    <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-slate-100 pt-3">
                                        <FingerprintCell employee={employee} />
                                        <CardCell employee={employee} />
                                    </div>
                                    <div className="mt-2">
                                        <TerminalsCell employee={employee} />
                                    </div>
                                </>
                            ) : (
                                <p className="mt-3 text-sm text-slate-500">
                                    This employee&rsquo;s record could not be loaded.
                                </p>
                            )}
                        </div>
                    )}

                    <div className="mt-4 rounded-xl border border-slate-200 p-4">
                        <div className="flex items-baseline justify-between gap-3">
                            <h3 className="text-sm font-semibold text-slate-800">Recent punches</h3>
                            {!loading && recent && (
                                <span className="text-xs text-slate-500">last {recent.length}</span>
                            )}
                        </div>

                        {loading ? (
                            <div className="mt-3 space-y-2">
                                {Array.from({ length: 4 }, (_, i) => (
                                    <Skeleton key={i} className="h-4 w-full" />
                                ))}
                            </div>
                        ) : recent && recent.length > 0 ? (
                            <ul className="mt-2 divide-y divide-slate-100">
                                {recent.map((row) => {
                                    const at = formatPunch(row.punched_at);
                                    const isThisOne = row.id === log.id;

                                    return (
                                        <li
                                            key={row.id}
                                            className={`flex items-center justify-between gap-3 py-2 text-sm ${
                                                isThisOne ? 'font-semibold text-slate-900' : 'text-slate-600'
                                            }`}
                                        >
                                            <span className="min-w-0 truncate">
                                                {at.date}
                                                <span className="ml-2 text-xs text-slate-500">
                                                    {row.device_name || '—'} · {verifyLabel(row.verify_method)}
                                                </span>
                                            </span>
                                            <span className="shrink-0 tabular-nums">{at.time}</span>
                                        </li>
                                    );
                                })}
                            </ul>
                        ) : (
                            <p className="mt-3 text-sm text-slate-500">No other punches recorded.</p>
                        )}
                    </div>

                    <div className="mt-5 flex justify-end gap-2">
                        {/*
                          * Gated on the same permission as the profile above: the
                          * export endpoint authorizes employees.view, so offering
                          * the button without it would only produce a 403.
                          */}
                        {mayViewEmployee && (
                            <Button
                                variant="soft"
                                loading={exporting}
                                disabled={exporting}
                                onClick={exportPdf}
                                title="Download this employee's last 30 days as a PDF"
                            >
                                {exporting ? 'Preparing…' : 'Export PDF'}
                            </Button>
                        )}
                        <Button variant="secondary" disabled={exporting} onClick={onClose}>Close</Button>
                    </div>
                </div>
            </Card>
        </div>
    );
}

/** One labelled fact. An absent value stays an em dash rather than a blank gap. */
function Fact({ label, value }) {
    return (
        <div className="min-w-0">
            <dt className="text-xs text-slate-500">{label}</dt>
            <dd className="truncate text-slate-800">{value || '—'}</dd>
        </div>
    );
}
