import { ActionMenu, Button, Card, EmptyState, PageHeader, SkeletonCards, SkeletonTable, TableShell, Thead } from '../../shared/ui';
import { LevelCard, StatusBadge, Tabs, lastActive, userActions } from './parts';

// Desktop: users as a table, levels as cards side by side.
export default function UsersDesktop(props) {
    const { loaded, tab, onTab, showLevels, users, levels, can, onAddUser, onAddLevel } = props;

    return (
        <div className="space-y-5">
            <PageHeader title="Users" subtitle="Who can sign in, and what each permission level may do">
                {tab === 'users' && can('users.create') && <Button onClick={onAddUser}>+ Add user</Button>}
                {tab === 'levels' && can('roles.manage') && <Button onClick={onAddLevel}>+ New level</Button>}
            </PageHeader>

            {showLevels && <Tabs tab={tab} onTab={onTab} counts={loaded ? { users: users.length, levels: levels.length } : null} />}

            {tab === 'users' || !showLevels
                ? (!loaded ? <SkeletonTable rows={4} cols={6} /> : <UserTable {...props} />)
                : (!loaded ? <SkeletonCards count={4} height="h-44" /> : <LevelGrid {...props} />)}
        </div>
    );
}

function UserTable({ users, can, onAddUser, ...handlers }) {
    if (users.length === 0) {
        return (
            <Card>
                <EmptyState title="No users yet" hint="Add the people who should sign in." action={can('users.create') && <Button onClick={onAddUser}>+ Add user</Button>} />
            </Card>
        );
    }

    return (
        <TableShell>
            <Thead>
                <tr>
                    <th scope="col" className="px-5 py-3 text-left">Name</th>
                    <th scope="col" className="px-5 py-3 text-left">Permission level</th>
                    <th scope="col" className="px-5 py-3 text-left">Linked employee</th>
                    <th scope="col" className="px-5 py-3 text-left">Last active</th>
                    <th scope="col" className="px-5 py-3 text-left">Status</th>
                    <th scope="col" className="px-5 py-3"><span className="sr-only">Actions</span></th>
                </tr>
            </Thead>
            <tbody className="divide-y divide-slate-100">
                {users.map((u) => {
                    const items = userActions(u, { can, ...handlers });
                    return (
                        <tr key={u.id} className={u.is_active ? '' : 'bg-slate-50/60'}>
                            <td className="px-5 py-3">
                                <div className="font-medium text-slate-900">{u.name}{u.is_self && <span className="ml-1.5 text-xs font-normal text-slate-500">(you)</span>}</div>
                                <div className="text-xs text-slate-500">{u.email}</div>
                            </td>
                            <td className="px-5 py-3 text-slate-700">{u.role_label ?? <span className="text-slate-500">None</span>}</td>
                            <td className="px-5 py-3 text-slate-700">
                                {u.employee ? <>{u.employee.name} <span className="text-xs text-slate-500">#{u.employee.employee_code}</span></> : <span className="text-slate-500">—</span>}
                            </td>
                            <td className="px-5 py-3 text-slate-600">{lastActive(u.last_active_at)}</td>
                            <td className="px-5 py-3"><StatusBadge user={u} /></td>
                            <td className="px-5 py-3 text-right">
                                {items.length > 0 && <ActionMenu label="Actions" className="min-h-[36px] px-3 text-xs" items={items} />}
                            </td>
                        </tr>
                    );
                })}
            </tbody>
        </TableShell>
    );
}

function LevelGrid({ levels, groups, can, onEditLevel, onDeleteLevel }) {
    return (
        <div className="grid grid-cols-1 items-stretch gap-5 lg:grid-cols-2 2xl:grid-cols-4">
            {levels.map((l) => (
                <LevelCard key={l.id} level={l} groups={groups} canManage={can('roles.manage')} onEdit={onEditLevel} onDelete={onDeleteLevel} />
            ))}
        </div>
    );
}
