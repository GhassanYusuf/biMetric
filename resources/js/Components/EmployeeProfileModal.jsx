import DonutChart from '@/Components/DonutChart';
import { BuildingIcon, CalendarIcon, InboxIcon, MailIcon, SpinnerIcon, XIcon } from '@/Components/Icons';
import Modal from '@/Components/Modal';
import axios from 'axios';
import { useCallback, useEffect, useMemo, useState } from 'react';

const CATEGORY_META = {
    early: { label: 'Early arrival', shortLabel: 'Early', color: '#10b981' },
    come_late: { label: 'Came late', shortLabel: 'Late', color: '#f43f5e' },
    leave_on_time: { label: 'Left on time', shortLabel: 'On time', color: '#0ea5e9' },
    leave_late: { label: 'Left late', shortLabel: 'Left late', color: '#f59e0b' },
    absent: { label: 'Absent', shortLabel: 'Absent', color: '#dc2626' },
    excused: { label: 'Excused / Holiday', shortLabel: 'Excused', color: '#8b5cf6' },
    missed_punch_out: { label: 'Missing punch-out', shortLabel: 'No punch-out', color: '#f97316' },
};

function initials(name) {
    return (name ?? '')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

function Pill({ text, category }) {
    const meta = CATEGORY_META[category];
    return (
        <span
            className="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
            style={{ backgroundColor: `${meta.color}1a`, color: meta.color }}
        >
            <span className="h-1.5 w-1.5 shrink-0 rounded-full" style={{ backgroundColor: meta.color }} />
            {text}
        </span>
    );
}

function isoDaysAgo(n) {
    const d = new Date();
    d.setDate(d.getDate() - n);
    return d.toISOString().slice(0, 10);
}

export default function EmployeeProfileModal({ employee, onClose }) {
    const [from, setFrom] = useState(isoDaysAgo(29));
    const [to, setTo] = useState(isoDaysAgo(0));
    const [summary, setSummary] = useState(null);
    const [loading, setLoading] = useState(false);
    const [selectedCategory, setSelectedCategory] = useState(null);
    const [waivingDate, setWaivingDate] = useState(null);

    const show = employee !== null;

    const fetchSummary = useCallback(() => {
        if (!employee) return;
        setLoading(true);
        return axios
            .get(route('employees.attendance-summary', employee.id), { params: { from, to } })
            .then((res) => setSummary(res.data))
            .finally(() => setLoading(false));
    }, [employee?.id, from, to]);

    useEffect(() => {
        if (!show) return;
        setSelectedCategory(null);
        fetchSummary();
    }, [show, fetchSummary]);

    function toggleWaive(date) {
        setWaivingDate(date);
        axios
            .post(route('employees.waive-day', employee.id), { date })
            .then(() => fetchSummary())
            .finally(() => setWaivingDate(null));
    }

    const donutSegments = useMemo(() => {
        if (!summary) return [];
        return Object.entries(CATEGORY_META).map(([key, meta]) => ({
            key,
            label: meta.label,
            shortLabel: meta.shortLabel,
            color: meta.color,
            value: summary.categories[key] ?? 0,
        }));
    }, [summary]);

    const visibleDays = useMemo(() => {
        if (!summary) return [];

        const filtered = selectedCategory
            ? summary.daily.filter(
                  (d) =>
                      d.arrival === selectedCategory ||
                      d.departure === selectedCategory ||
                      d.status === selectedCategory ||
                      (selectedCategory === 'missed_punch_out' && d.missed_punch_out),
              )
            : summary.daily;

        return [...filtered].reverse();
    }, [summary, selectedCategory]);

    return (
        <Modal show={show} maxWidth="6xl" onClose={onClose}>
            {employee && (
                <div className="flex max-h-[80vh] flex-col overflow-hidden">
                    <div className="relative shrink-0 bg-gradient-to-br from-indigo-600 via-indigo-500 to-purple-500 px-6 py-6 text-white">
                        <button
                            onClick={onClose}
                            className="absolute right-4 top-4 rounded-full bg-white/10 p-1.5 text-white/80 transition hover:bg-white/20 hover:text-white"
                        >
                            <XIcon className="h-4 w-4" />
                        </button>

                        <div className="flex items-center gap-4">
                            {employee.photo_url ? (
                                <img
                                    src={employee.photo_url}
                                    alt={employee.name}
                                    className="h-20 w-20 shrink-0 rounded-full border-4 border-white/30 object-cover shadow-lg"
                                />
                            ) : (
                                <div className="flex h-20 w-20 shrink-0 items-center justify-center rounded-full border-4 border-white/30 bg-white/15 text-2xl font-bold shadow-lg">
                                    {initials(employee.name) || '?'}
                                </div>
                            )}

                            <div className="min-w-0">
                                <h2 className="truncate text-xl font-bold">{employee.name}</h2>
                                <p className="font-mono text-sm text-indigo-100">#{employee.employee_code}</p>
                                <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-indigo-50">
                                    {employee.department && (
                                        <span className="flex items-center gap-1.5">
                                            <BuildingIcon className="h-3.5 w-3.5" />
                                            {employee.department}
                                        </span>
                                    )}
                                    {employee.email && (
                                        <span className="flex items-center gap-1.5">
                                            <MailIcon className="h-3.5 w-3.5" />
                                            {employee.email}
                                        </span>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="flex min-h-0 flex-1 flex-col gap-6 p-6">
                        <div className="flex shrink-0 flex-wrap items-end gap-4">
                            <div>
                                <label className="block text-xs font-medium uppercase tracking-wide text-gray-500">
                                    From
                                </label>
                                <div className="mt-1 flex items-center gap-1.5 rounded-md border border-gray-300 px-2 py-1.5 shadow-sm">
                                    <CalendarIcon className="h-4 w-4 text-gray-400" />
                                    <input
                                        type="date"
                                        value={from}
                                        max={to}
                                        onChange={(e) => setFrom(e.target.value)}
                                        className="border-0 p-0 text-sm focus:ring-0"
                                    />
                                </div>
                            </div>
                            <div>
                                <label className="block text-xs font-medium uppercase tracking-wide text-gray-500">
                                    To
                                </label>
                                <div className="mt-1 flex items-center gap-1.5 rounded-md border border-gray-300 px-2 py-1.5 shadow-sm">
                                    <CalendarIcon className="h-4 w-4 text-gray-400" />
                                    <input
                                        type="date"
                                        value={to}
                                        min={from}
                                        max={isoDaysAgo(0)}
                                        onChange={(e) => setTo(e.target.value)}
                                        className="border-0 p-0 text-sm focus:ring-0"
                                    />
                                </div>
                            </div>

                            {summary && (
                                <div className="ml-auto flex gap-3">
                                    <div className="rounded-lg bg-gray-50 px-4 py-2 text-right">
                                        <div className="text-lg font-bold text-gray-700">{summary.total_punches}</div>
                                        <div className="text-[11px] font-medium uppercase tracking-wide text-gray-400">
                                            punches
                                        </div>
                                    </div>
                                    <div className="rounded-lg bg-indigo-50 px-4 py-2 text-right">
                                        <div className="text-lg font-bold text-indigo-700">
                                            {summary.total_payroll_hours}h
                                        </div>
                                        <div className="text-[11px] font-medium uppercase tracking-wide text-indigo-400">
                                            payroll hours
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>

                        {loading && !summary ? (
                            <div className="flex flex-1 items-center justify-center text-gray-400">
                                <SpinnerIcon className="h-6 w-6" />
                            </div>
                        ) : (
                            summary && (
                                <div className="grid min-h-0 flex-1 gap-6 lg:grid-cols-12">
                                    <div className="flex min-h-0 flex-col overflow-y-auto rounded-xl border border-gray-100 p-6 shadow-sm lg:col-span-5">
                                        <h3 className="shrink-0 text-sm font-semibold text-gray-700">
                                            Timeliness breakdown
                                        </h3>
                                        <div className="flex flex-1 items-center justify-center py-4">
                                            <DonutChart
                                                segments={donutSegments}
                                                selected={selectedCategory}
                                                onSelect={setSelectedCategory}
                                            />
                                        </div>
                                        <p className="shrink-0 text-xs text-gray-400">
                                            Click a segment to see which days it's made up of.
                                        </p>
                                    </div>

                                    <div className="flex min-h-0 flex-col rounded-xl border border-gray-100 p-4 shadow-sm lg:col-span-7">
                                        <div className="mb-3 flex items-center justify-between">
                                            <h3 className="text-sm font-semibold text-gray-700">
                                                {selectedCategory
                                                    ? CATEGORY_META[selectedCategory].label
                                                    : 'All work days'}
                                                <span className="ml-1.5 font-normal text-gray-400">
                                                    ({visibleDays.length})
                                                </span>
                                            </h3>
                                            {selectedCategory && (
                                                <button
                                                    type="button"
                                                    onClick={() => setSelectedCategory(null)}
                                                    className="text-xs font-medium text-indigo-600 hover:text-indigo-800"
                                                >
                                                    Show all
                                                </button>
                                            )}
                                        </div>

                                        {visibleDays.length === 0 ? (
                                            <div
                                                key={selectedCategory ?? 'all'}
                                                className="flex flex-1 flex-col items-center justify-center gap-2 text-center text-gray-400 [animation:fade-slide-in_0.3s_ease]"
                                            >
                                                <InboxIcon className="h-6 w-6 text-gray-300" />
                                                <p className="text-sm">No matching days.</p>
                                            </div>
                                        ) : (
                                            <div className="min-h-0 flex-1 overflow-y-auto">
                                                <table className="min-w-full text-sm">
                                                    <thead className="sticky top-0 bg-white text-xs uppercase tracking-wide text-gray-400">
                                                        <tr>
                                                            <th className="py-1.5 text-left font-medium">Date</th>
                                                            <th className="py-1.5 text-left font-medium">First</th>
                                                            <th className="py-1.5 text-left font-medium">Last</th>
                                                            <th className="py-1.5 text-right font-medium">Payroll</th>
                                                            <th className="py-1.5 text-right font-medium" />
                                                        </tr>
                                                    </thead>
                                                    <tbody
                                                        key={selectedCategory ?? 'all'}
                                                        className="divide-y divide-gray-100"
                                                    >
                                                        {visibleDays.map((d, i) => (
                                                            <tr
                                                                key={d.date}
                                                                className="[animation:fade-slide-in_0.3s_ease_backwards]"
                                                                style={{ animationDelay: `${Math.min(i, 12) * 25}ms` }}
                                                            >
                                                                <td className="py-1.5 text-gray-700">
                                                                    {new Date(d.date).toLocaleDateString(undefined, {
                                                                        weekday: 'short',
                                                                        month: 'short',
                                                                        day: 'numeric',
                                                                    })}
                                                                </td>
                                                                <td className="py-1.5">
                                                                    {d.status === 'present' && (
                                                                        <Pill text={d.first} category={d.arrival} />
                                                                    )}
                                                                    {d.status === 'absent' && (
                                                                        <Pill text="Absent" category="absent" />
                                                                    )}
                                                                    {d.status === 'excused' && (
                                                                        <Pill
                                                                            text={d.excused_reason ?? 'Excused'}
                                                                            category="excused"
                                                                        />
                                                                    )}
                                                                </td>
                                                                <td className="py-1.5">
                                                                    {d.status === 'present' && d.departure && (
                                                                        <Pill text={d.last} category={d.departure} />
                                                                    )}
                                                                    {d.status === 'present' && d.missed_punch_out && (
                                                                        <Pill text="No punch-out" category="missed_punch_out" />
                                                                    )}
                                                                    {d.status !== 'present' && (
                                                                        <span className="text-gray-300">—</span>
                                                                    )}
                                                                </td>
                                                                <td className="py-1.5 text-right tabular-nums text-gray-600">
                                                                    {d.payroll_hours ? `${d.payroll_hours}h` : '—'}
                                                                </td>
                                                                <td className="py-1.5 text-right">
                                                                    {d.status === 'absent' && (
                                                                        <button
                                                                            type="button"
                                                                            disabled={waivingDate === d.date}
                                                                            onClick={() => toggleWaive(d.date)}
                                                                            className="text-xs font-medium text-indigo-600 hover:text-indigo-800 disabled:opacity-50"
                                                                        >
                                                                            {waivingDate === d.date ? '…' : 'Waive'}
                                                                        </button>
                                                                    )}
                                                                    {d.status === 'excused' && d.excused_source === 'waiver' && (
                                                                        <button
                                                                            type="button"
                                                                            disabled={waivingDate === d.date}
                                                                            onClick={() => toggleWaive(d.date)}
                                                                            className="text-xs font-medium text-gray-400 hover:text-gray-600 disabled:opacity-50"
                                                                        >
                                                                            {waivingDate === d.date ? '…' : 'Unwaive'}
                                                                        </button>
                                                                    )}
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )
                        )}
                    </div>
                </div>
            )}
        </Modal>
    );
}
