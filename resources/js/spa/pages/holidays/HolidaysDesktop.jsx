import { Button, Card, EmptyState, PageHeader, SectionCard } from '../../shared/ui';
import { DayBadge, formatDate, partition } from './parts';

// Desktop holidays: upcoming first (that's what anyone opening this page wants),
// past collapsed underneath as reference.
export default function HolidaysDesktop({ rows, canManage, onAdd, onEdit, onDelete }) {
    const { upcoming, past } = partition(rows);

    return (
        <div className="space-y-5">
            <PageHeader title="Holidays" subtitle="Days excluded from absence calculations">
                {canManage && <Button onClick={onAdd}>+ Add holiday</Button>}
            </PageHeader>

            {rows.length === 0 ? (
                <Card>
                    <EmptyState
                        title="No holidays yet"
                        hint="Add public holidays so they aren't counted as absences."
                        action={canManage && <Button onClick={onAdd}>+ Add your first holiday</Button>}
                    />
                </Card>
            ) : (
                <div className="grid grid-cols-2 gap-5 items-start">
                    <SectionCard title={`Upcoming (${upcoming.length})`} bodyClass="p-2">
                        <HolidayList rows={upcoming} canManage={canManage} onEdit={onEdit} onDelete={onDelete} emptyHint="Nothing scheduled ahead." />
                    </SectionCard>
                    <SectionCard title={`Past (${past.length})`} bodyClass="p-2">
                        <HolidayList rows={past} canManage={canManage} onEdit={onEdit} onDelete={onDelete} emptyHint="No past holidays." />
                    </SectionCard>
                </div>
            )}
        </div>
    );
}

function HolidayList({ rows, canManage, onEdit, onDelete, emptyHint }) {
    if (rows.length === 0) {
        return <p className="px-3 py-6 text-center text-sm text-slate-500">{emptyHint}</p>;
    }

    return (
        <ul className="divide-y divide-slate-100">
            {rows.map((h) => (
                <li key={h.id} className="flex items-center gap-3 rounded-xl px-3 py-3 hover:bg-slate-50/60">
                    <div className="min-w-0 flex-1">
                        <div className="truncate font-medium text-slate-800">{h.name}</div>
                        <div className="text-xs text-slate-500">{formatDate(h.date)}</div>
                    </div>
                    <DayBadge ymd={h.date} />
                    {canManage && (
                        <div className="flex shrink-0 items-center gap-1">
                            <button onClick={() => onEdit(h)} className="px-1.5 text-sm text-slate-500 hover:text-slate-700">Edit</button>
                            <button onClick={() => onDelete(h)} className="px-1.5 text-sm text-rose-600 hover:underline">Delete</button>
                        </div>
                    )}
                </li>
            ))}
        </ul>
    );
}
