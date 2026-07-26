import { useState } from 'react';
import { Badge, Button, Card, EmptyState, PageHeader, SelectField } from '../../shared/ui';
import { GROUP_COMMANDS, commandLabel } from './statusParts';

/*
| Mobile groups: one card per group, stacked. Same decisions as the desktop table,
| but the command picker sits full width under the group so the target is never
| ambiguous on a narrow screen.
*/
export default function DeviceGroupsMobile({
    groups, dispatching,
    canCreate, canUpdate, canDelete,
    onAdd, onEdit, onDelete, onDispatch,
}) {
    const [pending, setPending] = useState({});

    return (
        <div className="space-y-4">
            <PageHeader compact title="Device groups" subtitle="Run one action across a whole site">
                {canCreate && <Button className="min-h-[38px] px-3 text-xs" onClick={onAdd}>+ New</Button>}
            </PageHeader>

            {groups.length === 0 ? (
                <Card>
                    <EmptyState
                        title="No groups yet"
                        hint="Group terminals to sync or health-check a whole site in one action."
                        action={canCreate && <Button onClick={onAdd}>+ Create a group</Button>}
                    />
                </Card>
            ) : (
                <div className="space-y-3">
                    {groups.map((g) => {
                        const count = g.devices_count ?? g.devices?.length ?? 0;
                        const command = pending[g.id] ?? '';
                        return (
                            <Card key={g.id} className="p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="font-semibold text-slate-900">{g.name}</div>
                                        {g.description && <div className="mt-0.5 text-xs text-slate-500">{g.description}</div>}
                                    </div>
                                    {count === 0
                                        ? <Badge tone="amber">Empty</Badge>
                                        : <Badge tone="slate">{count} terminal{count === 1 ? '' : 's'}</Badge>}
                                </div>

                                {canUpdate && (
                                    <div className="mt-3 space-y-2">
                                        <SelectField
                                            aria-label={`Command to run on ${g.name}`}
                                            value={command}
                                            onChange={(e) => setPending((p) => ({ ...p, [g.id]: e.target.value }))}
                                        >
                                            <option value="">Choose a command…</option>
                                            {GROUP_COMMANDS.map((c) => (
                                                <option key={c} value={c}>{commandLabel(c)}</option>
                                            ))}
                                        </SelectField>
                                        <Button
                                            variant="soft"
                                            className="w-full"
                                            disabled={!command || count === 0 || dispatching === g.id}
                                            loading={dispatching === g.id}
                                            onClick={() => onDispatch(g, command)}
                                        >
                                            Queue on {count} terminal{count === 1 ? '' : 's'}
                                        </Button>
                                    </div>
                                )}

                                {(canUpdate || canDelete) && (
                                    <div className="mt-3 flex gap-2 border-t border-slate-100 pt-3">
                                        {canUpdate && (
                                            <Button variant="secondary" className="min-h-[38px] flex-1 text-xs" onClick={() => onEdit(g)}>Edit</Button>
                                        )}
                                        {canDelete && (
                                            <Button variant="secondary" className="min-h-[38px] flex-1 text-xs !text-rose-600" onClick={() => onDelete(g)}>Delete</Button>
                                        )}
                                    </div>
                                )}
                            </Card>
                        );
                    })}
                </div>
            )}

            {groups.length > 0 && (
                <p className="text-xs text-slate-500">
                    Each member terminal gets its own queued command, so a partial failure stays visible per device.
                </p>
            )}
        </div>
    );
}
