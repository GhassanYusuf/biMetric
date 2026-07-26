import { useState } from 'react';
import { Badge, Button, EmptyState, PageHeader, SelectField, TableShell, Thead } from '../../shared/ui';
import { GROUP_COMMANDS, commandLabel } from './statusParts';

/*
| Desktop groups: one row per group, with a command picker per row.
|
| The picker is per row rather than a single toolbar control because the command and
| its target are one decision. A global "run" button next to a row selection is how
| somebody fires a full re-read at the wrong site.
*/
export default function DeviceGroupsDesktop({
    groups, devices, dispatching,
    canCreate, canUpdate, canDelete,
    onAdd, onEdit, onDelete, onDispatch,
}) {
    const [pending, setPending] = useState({}); // { [groupId]: command }
    const showActions = canUpdate || canDelete;

    return (
        <div className="space-y-5">
            <PageHeader
                title="Device groups"
                subtitle={
                    groups.length === 0
                        ? 'Group terminals so one action can target a whole site'
                        : `${groups.length} group${groups.length > 1 ? 's' : ''} across ${devices.length} terminal${devices.length === 1 ? '' : 's'}`
                }
            >
                {canCreate && <Button onClick={onAdd}>+ New group</Button>}
            </PageHeader>

            <TableShell>
                <Thead>
                    <tr>
                        <th className="px-5 py-3 text-left">Group</th>
                        <th className="px-5 py-3 text-left">Terminals</th>
                        <th className="px-5 py-3 text-left">Run on all members</th>
                        {showActions && <th className="px-5 py-3" />}
                    </tr>
                </Thead>
                <tbody className="divide-y divide-slate-100">
                    {groups.length === 0 && (
                        <tr>
                            <td colSpan={4}>
                                <EmptyState
                                    title="No groups yet"
                                    hint="A group lets you sync or health-check every terminal at a site in one action, while still tracking each device's outcome separately."
                                    action={canCreate && <Button onClick={onAdd}>+ Create your first group</Button>}
                                />
                            </td>
                        </tr>
                    )}
                    {groups.map((g) => {
                        const count = g.devices_count ?? g.devices?.length ?? 0;
                        const command = pending[g.id] ?? '';
                        return (
                            <tr key={g.id} className="align-top transition-colors duration-150 hover:bg-slate-50/70">
                                <td className="px-5 py-3.5">
                                    <div className="font-medium text-slate-900">{g.name}</div>
                                    {g.description && <div className="text-xs text-slate-500">{g.description}</div>}
                                </td>
                                <td className="px-5 py-3.5">
                                    {count === 0 ? (
                                        <Badge tone="amber">No terminals</Badge>
                                    ) : (
                                        <span className="text-slate-700">
                                            <span className="font-semibold tabular-nums">{count}</span> terminal{count === 1 ? '' : 's'}
                                        </span>
                                    )}
                                </td>
                                <td className="px-5 py-3.5">
                                    {canUpdate ? (
                                        <div className="flex items-end gap-2">
                                            <SelectField
                                                className="min-w-[13rem]"
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
                                                className="min-h-[42px]"
                                                disabled={!command || count === 0 || dispatching === g.id}
                                                loading={dispatching === g.id}
                                                onClick={() => onDispatch(g, command)}
                                            >
                                                Queue
                                            </Button>
                                        </div>
                                    ) : (
                                        <span className="text-xs text-slate-500">Requires device update permission</span>
                                    )}
                                </td>
                                {showActions && (
                                    <td className="px-5 py-3.5">
                                        <div className="flex items-center justify-end gap-1.5">
                                            {canUpdate && (
                                                <button onClick={() => onEdit(g)} className="rounded-lg px-2 py-1 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900">
                                                    Edit
                                                </button>
                                            )}
                                            {canDelete && (
                                                <button onClick={() => onDelete(g)} className="rounded-lg px-2 py-1 text-sm font-medium text-rose-600 transition-colors hover:bg-rose-50">
                                                    Delete
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                )}
                            </tr>
                        );
                    })}
                </tbody>
            </TableShell>

            {groups.length > 0 && (
                <p className="text-xs text-slate-500">
                    A group command queues one command <strong className="font-semibold text-slate-600">per terminal</strong>. Watch each device's
                    queue for its own outcome — a group that half-succeeds stays legible per unit, and retrying only re-hits what failed.
                </p>
            )}
        </div>
    );
}
