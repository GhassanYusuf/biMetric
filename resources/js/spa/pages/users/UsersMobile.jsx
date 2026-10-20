import { ActionMenu, Button, Card, EmptyState, PageHeader, SkeletonCards } from '../../shared/ui';
import { LevelCard, StatusBadge, Tabs, lastActive, userActions } from './parts';

// Mobile: users as stacked cards, levels as stacked cards — the same actions as desktop.
export default function UsersMobile(props) {
    const { loaded, tab, onTab, showLevels, users, levels, can, onAddUser, onAddLevel } = props;

    return (
        <div className="space-y-4">
            <PageHeader title="Users" compact>
                {tab === 'users' && can('users.create') && <Button onClick={onAddUser} className="px-3">+ User</Button>}
                {tab === 'levels' && can('roles.manage') && <Button onClick={onAddLevel} className="px-3">+ Level</Button>}
            </PageHeader>

            {showLevels && <Tabs tab={tab} onTab={onTab} counts={loaded ? { users: users.length, levels: levels.length } : null} />}

            {!loaded
                ? <SkeletonCards count={4} height="h-24" />
                : tab === 'users' || !showLevels ? <UserCards {...props} /> : <LevelCards {...props} />}
        </div>
    );
}

function UserCards({ users, can, onAddUser, ...handlers }) {
    if (users.length === 0) {
        return (
            <Card>
                <EmptyState title="No users yet" hint="Add the people who should sign in." action={can('users.create') && <Button onClick={onAddUser}>+ Add user</Button>} />
            </Card>
        );
    }

    return (
        <ul className="space-y-3">
            {users.map((u) => {
                const items = userActions(u, { can, ...handlers });
                return (
                    <li key={u.id}>
                        <Card className="p-4">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="truncate font-medium text-slate-900">{u.name}{u.is_self && <span className="ml-1.5 text-xs font-normal text-slate-500">(you)</span>}</div>
                                    <div className="truncate text-xs text-slate-500">{u.email}</div>
                                </div>
                                {items.length > 0 && <ActionMenu label="Actions" className="min-h-[36px] shrink-0 px-3 text-xs" items={items} />}
                            </div>
                            <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm">
                                <StatusBadge user={u} />
                                <span className="text-slate-700">{u.role_label ?? 'No level'}</span>
                                <span className="text-xs text-slate-500">{lastActive(u.last_active_at)}</span>
                            </div>
                            {u.employee && <div className="mt-1.5 text-xs text-slate-600">Employee: {u.employee.name} #{u.employee.employee_code}</div>}
                        </Card>
                    </li>
                );
            })}
        </ul>
    );
}

function LevelCards({ levels, groups, can, onEditLevel, onDeleteLevel }) {
    return (
        <div className="space-y-3">
            {levels.map((l) => (
                <LevelCard key={l.id} level={l} groups={groups} canManage={can('roles.manage')} onEdit={onEditLevel} onDelete={onDeleteLevel} />
            ))}
        </div>
    );
}
