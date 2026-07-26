import { Link } from 'react-router-dom';
import { Badge, Button, Card, EmptyState, PageHeader, SectionCard, SelectField } from '../../shared/ui';
import { relativeTime } from './parts';
import { RECON_LABELS, ReconStatusBadge } from './statusParts';

const STATUSES = ['in_sync', 'missing_on_device', 'missing_in_system', 'mismatched'];

/*
| Mobile reconciliation: per-device summary cards, then the rows that need a decision.
|
| Same read-only stance as the desktop view — the three resolving actions are all
| explicit and queued, because deciding whose version wins is an operator's call.
*/
export default function ReconciliationMobile({
    summary, maps, filters, loading, busy,
    canSync, canPush, onFilter, onReconcile, onPush, onImportUnknown, onRefresh,
}) {
    return (
        <div className="space-y-4">
            <PageHeader compact title="Reconciliation" subtitle="System versus terminal">
                <Button variant="secondary" className="min-h-[38px] px-3 text-xs" loading={loading} onClick={onRefresh}>
                    Refresh
                </Button>
            </PageHeader>

            {summary.length === 0 ? (
                <Card>
                    <EmptyState title="Nothing to reconcile" hint="Run a reconciliation read on a terminal to compare it against your employee records." />
                </Card>
            ) : (
                <div className="space-y-3">
                    {summary.map((s) => (
                        <SectionCard
                            key={s.device_id}
                            title={s.device_name}
                            subtitle={s.last_reconciled_at ? `Compared ${relativeTime(s.last_reconciled_at)}` : 'Never compared'}
                            bodyClass="p-4"
                        >
                            <dl className="grid grid-cols-2 gap-3">
                                {[
                                    { label: RECON_LABELS.in_sync, value: s.in_sync, tone: 'text-emerald-700' },
                                    { label: RECON_LABELS.missing_on_device, value: s.missing_on_device, tone: s.missing_on_device ? 'text-rose-700' : 'text-slate-500' },
                                    { label: RECON_LABELS.missing_in_system, value: s.missing_in_system, tone: s.missing_in_system ? 'text-amber-700' : 'text-slate-500' },
                                    { label: RECON_LABELS.mismatched, value: s.mismatched, tone: s.mismatched ? 'text-amber-700' : 'text-slate-500' },
                                ].map((cell) => (
                                    <div key={cell.label}>
                                        <dd className={`text-lg font-bold tabular-nums ${cell.tone}`}>{(cell.value ?? 0).toLocaleString()}</dd>
                                        <dt className="text-xs text-slate-500">{cell.label}</dt>
                                    </div>
                                ))}
                            </dl>

                            {s.unattributed_punches > 0 && (
                                <p className="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                    {s.unattributed_punches.toLocaleString()} punches belong to a device user nobody recognises. They are kept, not discarded.
                                </p>
                            )}

                            <div className="mt-3 flex flex-col gap-2">
                                {canSync && (
                                    <Button
                                        variant="secondary"
                                        className="w-full text-xs"
                                        loading={busy === `reconcile-${s.device_id}`}
                                        onClick={() => onReconcile(s.device_id, s.device_name)}
                                    >
                                        Re-read this device
                                    </Button>
                                )}
                                {canSync && s.missing_in_system > 0 && (
                                    <Button
                                        variant="soft"
                                        className="w-full text-xs"
                                        loading={busy === `import-${s.device_id}`}
                                        onClick={() => onImportUnknown(s.device_id, s.device_name, s.missing_in_system)}
                                    >
                                        Import {s.missing_in_system} unknown user{s.missing_in_system === 1 ? '' : 's'}
                                    </Button>
                                )}
                            </div>
                        </SectionCard>
                    ))}
                </div>
            )}

            <SelectField aria-label="Filter by status" value={filters.status} onChange={(e) => onFilter('status', e.target.value)}>
                <option value="">Everything needing attention</option>
                {STATUSES.map((s) => <option key={s} value={s}>{RECON_LABELS[s]}</option>)}
            </SelectField>

            {maps.length === 0 ? (
                <Card>
                    <EmptyState
                        title={filters.status ? 'Nothing in that state' : 'Nothing needs attention'}
                        hint="Every mapped employee matches what their terminal holds."
                    />
                </Card>
            ) : (
                <div className="space-y-3">
                    {maps.map((m) => (
                        <Card key={m.id} className="p-4">
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    {m.employee_name ? (
                                        <div className="truncate font-semibold text-slate-900">{m.employee_name}</div>
                                    ) : (
                                        <div className="text-slate-600">
                                            <span className="italic">Unknown to the system</span>
                                            {m.device_held_name && (
                                                <div className="truncate text-xs text-slate-500">device calls them “{m.device_held_name}”</div>
                                            )}
                                        </div>
                                    )}
                                    <Link to={`/devices/${m.device_id}`} className="mt-0.5 block text-xs font-medium text-violet-700">
                                        {m.device_name ?? `Device ${m.device_id}`}
                                    </Link>
                                </div>
                                <ReconStatusBadge status={m.reconciliation_status} />
                            </div>

                            <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                {m.device_user_id != null && (
                                    <span className="font-mono text-xs text-slate-600">PIN {m.device_user_id}</span>
                                )}
                                {m.fingerprint_slots == null ? (
                                    <Badge tone="slate">Fingerprints unknown</Badge>
                                ) : (
                                    <Badge tone={m.fingerprint_count > 0 ? 'violet' : 'slate'}>
                                        {m.fingerprint_count} finger{m.fingerprint_count === 1 ? '' : 's'}
                                    </Badge>
                                )}
                                {m.card_number && (
                                    <Badge tone="blue"><span aria-hidden="true">▮</span> {m.card_number}</Badge>
                                )}
                            </div>

                            {m.reconciliation_detail && (
                                <p className="mt-2 text-xs text-slate-600">{m.reconciliation_detail}</p>
                            )}

                            {canPush && m.employee_id && m.reconciliation_status !== 'in_sync' && (
                                <Button
                                    variant="soft"
                                    className="mt-3 w-full text-xs"
                                    loading={busy === `push-${m.id}`}
                                    onClick={() => onPush(m)}
                                >
                                    Push to device
                                </Button>
                            )}
                        </Card>
                    ))}
                </div>
            )}

            <p className="text-xs text-slate-500">
                Nothing here resolves itself — the wrong automatic fix either deletes a real enrollment or restores a departed employee's access.
            </p>
        </div>
    );
}
