import { useState } from 'react';
import { Button, Card, EmptyState, PageHeader } from '../../shared/ui';
import { AttendanceRulesCard, DayBadge, formatDate, partition } from './parts';

// Mobile holidays: upcoming as tap-friendly cards; past behind a toggle so the
// list opens on what matters.
export default function HolidaysMobile({ rows, canManage, onAdd, onEdit, onDelete, rulesProps }) {
    const { upcoming, past } = partition(rows);
    const [showPast, setShowPast] = useState(false);

    return (
        <div className="space-y-4">
            <PageHeader title="Holidays" compact>
                {canManage && <Button onClick={onAdd} className="px-3">+ Add</Button>}
            </PageHeader>

            <AttendanceRulesCard {...rulesProps} compact />

            {rows.length === 0 ? (
                <Card>
                    <EmptyState
                        title="No holidays yet"
                        hint="Add public holidays so they aren't counted as absences."
                        action={canManage && <Button onClick={onAdd}>+ Add your first holiday</Button>}
                    />
                </Card>
            ) : (
                <>
                    <div className="space-y-2">
                        {upcoming.length === 0 ? (
                            <Card className="p-4 text-center text-sm text-slate-500">Nothing scheduled ahead.</Card>
                        ) : (
                            upcoming.map((h) => (
                                <HolidayCard key={h.id} holiday={h} canManage={canManage} onEdit={onEdit} onDelete={onDelete} />
                            ))
                        )}
                    </div>

                    {past.length > 0 && (
                        <>
                            <button
                                onClick={() => setShowPast((v) => !v)}
                                className="w-full rounded-xl border border-slate-200 bg-white py-2.5 text-sm font-medium text-slate-600"
                            >
                                {showPast ? 'Hide' : 'Show'} past holidays ({past.length})
                            </button>
                            {showPast && (
                                <div className="space-y-2">
                                    {past.map((h) => (
                                        <HolidayCard key={h.id} holiday={h} canManage={canManage} onEdit={onEdit} onDelete={onDelete} />
                                    ))}
                                </div>
                            )}
                        </>
                    )}
                </>
            )}
        </div>
    );
}

function HolidayCard({ holiday, canManage, onEdit, onDelete }) {
    return (
        <Card className="p-4">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="truncate font-semibold text-slate-900">{holiday.name}</div>
                    <div className="text-xs text-slate-500">{formatDate(holiday.date)}</div>
                </div>
                <DayBadge ymd={holiday.date} />
            </div>
            {canManage && (
                <div className="mt-3 flex items-center gap-4 border-t border-slate-100 pt-3">
                    <button onClick={() => onEdit(holiday)} className="text-sm font-medium text-slate-500">Edit</button>
                    <button onClick={() => onDelete(holiday)} className="ml-auto text-sm font-medium text-rose-600">Delete</button>
                </div>
            )}
        </Card>
    );
}
