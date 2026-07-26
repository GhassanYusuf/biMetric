import { Link } from 'react-router-dom';
import { Badge, Button, Card, EmptyState, PageHeader, SectionCard, SelectField, TableShell, Thead } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';
import { relativeTime } from './parts';
import { RECON_LABELS, ReconStatusBadge } from './statusParts';

const STATUSES = ['in_sync', 'missing_on_device', 'missing_in_system', 'mismatched'];

/*
| Desktop reconciliation: a per-device summary above the row-level detail.
|
| The summary answers "how bad is it" and carries the two device-wide actions; the
| table answers "who exactly" and carries the per-person one. Nothing on this screen
| resolves a discrepancy automatically — see the container for why.
*/
export default function ReconciliationDesktop({
    summary, maps, meta, filters, loading, busy,
    canSync, canPush, onFilter, onReconcile, onPush, onImportUnknown, onRefresh,
}) {
    const totalProblems = summary.reduce(
        (n, s) => n + s.missing_on_device + s.missing_in_system + s.mismatched, 0,
    );

    return (
        <div className="space-y-5">
            <PageHeader
                title="Reconciliation"
                subtitle={
                    summary.length === 0
                        ? 'Where the system and the terminals disagree about who is enrolled'
                        : totalProblems === 0
                            ? 'Every terminal agrees with the system'
                            : `${totalProblems.toLocaleString()} discrepanc${totalProblems === 1 ? 'y' : 'ies'} across ${summary.length} terminal${summary.length === 1 ? '' : 's'}`
                }
            >
                <Button variant="secondary" loading={loading} onClick={onRefresh}>Refresh</Button>
            </PageHeader>

            {summary.length === 0 ? (
                <Card>
                    <EmptyState
                        title="Nothing to reconcile"
                        hint="Add a directly addressable terminal and run a reconciliation read to compare its user table against your employee records."
                    />
                </Card>
            ) : (
                <div className="grid grid-cols-2 gap-4">
                    {summary.map((s) => (
                        <SectionCard
                            key={s.device_id}
                            title={s.device_name}
                            subtitle={s.last_reconciled_at
                                ? `Last compared ${relativeTime(s.last_reconciled_at)}`
                                : 'Never compared against this terminal'}
                            action={canSync && (
                                <Button
                                    variant="secondary"
                                    className="min-h-[34px] px-3 text-xs"
                                    loading={busy === `reconcile-${s.device_id}`}
                                    onClick={() => onReconcile(s.device_id, s.device_name)}
                                >
                                    Re-read device
                                </Button>
                            )}
                        >
                            <dl className="grid grid-cols-4 gap-3">
                                {[
                                    { label: RECON_LABELS.in_sync, value: s.in_sync, tone: 'text-emerald-700' },
                                    { label: RECON_LABELS.missing_on_device, value: s.missing_on_device, tone: s.missing_on_device ? 'text-rose-700' : 'text-slate-500' },
                                    { label: RECON_LABELS.missing_in_system, value: s.missing_in_system, tone: s.missing_in_system ? 'text-amber-700' : 'text-slate-500' },
                                    { label: RECON_LABELS.mismatched, value: s.mismatched, tone: s.mismatched ? 'text-amber-700' : 'text-slate-500' },
                                ].map((cell) => (
                                    <div key={cell.label}>
                                        <dd className={`text-xl font-bold tabular-nums tracking-[-0.02em] ${cell.tone}`}>
                                            {(cell.value ?? 0).toLocaleString()}
                                        </dd>
                                        <dt className="mt-0.5 text-xs text-slate-500">{cell.label}</dt>
                                    </div>
                                ))}
                            </dl>

                            {/*
                              | Unattributed punches: records whose device user id maps to
                              | nobody, typically former staff removed from the terminal.
                              | They are KEPT with a null employee rather than discarded, so
                              | payroll history stays complete and the gap stays visible
                              | instead of silently vanishing.
                            */}
                            {s.unattributed_punches > 0 && (
                                <p className="mt-4 rounded-xl bg-amber-50 px-3.5 py-2.5 text-xs text-amber-900">
                                    <strong className="font-semibold">{s.unattributed_punches.toLocaleString()} punches</strong> on this terminal belong to
                                    a device user nobody recognises. They are kept, not discarded — usually former staff whose record was removed from the
                                    device after they had already clocked in.
                                </p>
                            )}

                            {canSync && s.missing_in_system > 0 && (
                                <div className="mt-4">
                                    <Button
                                        variant="secondary"
                                        className="min-h-[36px] text-xs"
                                        loading={busy === `import-${s.device_id}`}
                                        onClick={() => onImportUnknown(s.device_id, s.device_name, s.missing_in_system)}
                                    >
                                        Import {s.missing_in_system} unknown user{s.missing_in_system === 1 ? '' : 's'}
                                    </Button>
                                </div>
                            )}
                        </SectionCard>
                    ))}
                </div>
            )}

            <div className="flex flex-wrap items-end gap-3">
                <SelectField label="Status" className="min-w-[14rem]" value={filters.status} onChange={(e) => onFilter('status', e.target.value)}>
                    <option value="">Everything needing attention</option>
                    {STATUSES.map((s) => <option key={s} value={s}>{RECON_LABELS[s]}</option>)}
                </SelectField>
                <SelectField label="Terminal" className="min-w-[14rem]" value={filters.device_id} onChange={(e) => onFilter('device_id', e.target.value)}>
                    <option value="">All terminals</option>
                    {summary.map((s) => <option key={s.device_id} value={s.device_id}>{s.device_name}</option>)}
                </SelectField>
            </div>

            <TableShell>
                <Thead>
                    <tr>
                        <th className="px-5 py-3 text-left">Employee</th>
                        <th className="px-5 py-3 text-left">Terminal</th>
                        <th className="px-5 py-3 text-left">Device PIN</th>
                        <th className="px-5 py-3 text-left">On the device</th>
                        <th className="px-5 py-3 text-left">Status</th>
                        <th className="px-5 py-3" />
                    </tr>
                </Thead>
                <tbody className="divide-y divide-slate-100">
                    {maps.length === 0 && (
                        <tr>
                            <td colSpan={6}>
                                <EmptyState
                                    title={filters.status ? 'Nothing in that state' : 'Nothing needs attention'}
                                    hint={filters.status
                                        ? 'Try a different status, or clear the filter.'
                                        : 'Every mapped employee matches what their terminal holds. Change the status filter to see in-sync records too.'}
                                />
                            </td>
                        </tr>
                    )}
                    {maps.map((m) => (
                        <tr key={m.id} className="align-top transition-colors duration-150 hover:bg-slate-50/70">
                            <td className="px-5 py-3.5">
                                {m.employee_name ? (
                                    <div className="font-medium text-slate-900">{m.employee_name}</div>
                                ) : (
                                    /*
                                     * No employee at all: the device holds a user the system has
                                     * never heard of. The name the DEVICE holds is the only
                                     * identifying information available.
                                     */
                                    <div className="text-slate-600">
                                        <span className="italic">Unknown to the system</span>
                                        {m.device_held_name && (
                                            <div className="text-xs text-slate-500">device calls them “{m.device_held_name}”</div>
                                        )}
                                    </div>
                                )}
                            </td>
                            <td className="px-5 py-3.5">
                                <Link to={`/devices/${m.device_id}`} className="font-medium text-violet-700 hover:underline">
                                    {m.device_name ?? `Device ${m.device_id}`}
                                </Link>
                            </td>
                            <td className="px-5 py-3.5 font-mono text-xs text-slate-600">
                                {m.device_user_id ?? '—'}
                                {/* The internal slot is stable per device but NOT portable between them. */}
                                {m.device_uid != null && <div className="text-slate-500">uid {m.device_uid}</div>}
                            </td>
                            <td className="px-5 py-3.5">
                                <div className="flex flex-wrap items-center gap-1.5">
                                    {m.fingerprint_slots == null ? (
                                        // Null is NOT zero: the template table could not be decoded,
                                        // and claiming "no fingerprints" would be a lie.
                                        <Badge tone="slate">Fingerprints unknown</Badge>
                                    ) : (
                                        <Badge tone={m.fingerprint_count > 0 ? 'violet' : 'slate'}>
                                            {m.fingerprint_count} finger{m.fingerprint_count === 1 ? '' : 's'}
                                        </Badge>
                                    )}
                                    {m.card_number && (
                                        <Badge tone="blue">
                                            <span aria-hidden="true">▮</span> {m.card_number}
                                        </Badge>
                                    )}
                                </div>
                            </td>
                            <td className="px-5 py-3.5">
                                <ReconStatusBadge status={m.reconciliation_status} />
                                {m.reconciliation_detail && (
                                    <div className="mt-1 max-w-xs text-xs text-slate-600">{m.reconciliation_detail}</div>
                                )}
                            </td>
                            <td className="px-5 py-3.5">
                                <div className="flex justify-end">
                                    {canPush && m.employee_id && m.reconciliation_status !== 'in_sync' && (
                                        <button
                                            onClick={() => onPush(m)}
                                            disabled={busy === `push-${m.id}`}
                                            className={`rounded-lg px-2 py-1 text-xs font-semibold text-violet-700 transition-colors hover:bg-violet-50 disabled:opacity-45 ${FOCUS}`}
                                        >
                                            {busy === `push-${m.id}` ? 'Queueing…' : 'Push to device'}
                                        </button>
                                    )}
                                </div>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </TableShell>

            <p className="text-xs text-slate-500">
                Nothing here resolves itself. Choosing whose version wins is an operator decision — the wrong choice either deletes a real enrollment or
                restores a departed employee's access, so every fix above is an explicit, queued action.
                {meta?.pagination?.total > maps.length && (
                    <> Showing {maps.length} of {meta.pagination.total.toLocaleString()} mappings.</>
                )}
            </p>
        </div>
    );
}
