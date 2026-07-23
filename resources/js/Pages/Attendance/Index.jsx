import Badge from '@/Components/Badge';
import { InboxIcon, RefreshIcon, SignalIcon } from '@/Components/Icons';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import useRealtimePunches from '@/hooks/useRealtimePunches';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

const VERIFY_METHODS = {
    0: 'Password',
    1: 'Fingerprint',
    2: 'Card',
    15: 'Face',
};

export default function Index({ logs, devices, filters }) {
    const [deviceId, setDeviceId] = useState(filters.device_id ?? '');
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const [reading, setReading] = useState(false);

    const { mqtt } = usePage().props;
    const reloadTimeout = useRef(null);

    const { connected: liveConnected } = useRealtimePunches(mqtt, () => {
        // Debounced reload of just the logs prop - reuses the same call
        // "Read from Device" already triggers on completion, just fired by
        // an incoming live punch instead of a button click. Debounced so a
        // burst of several punches only triggers one reload.
        clearTimeout(reloadTimeout.current);
        reloadTimeout.current = setTimeout(() => {
            router.reload({ only: ['logs'], preserveScroll: true });
        }, 1500);
    });

    useEffect(() => () => clearTimeout(reloadTimeout.current), []);

    function applyFilters(e) {
        e.preventDefault();
        router.get(
            route('attendance.index'),
            { device_id: deviceId || undefined, from: from || undefined, to: to || undefined },
            { preserveState: true },
        );
    }

    function readFromDevice() {
        const targetIds = deviceId ? [deviceId] : devices.map((d) => d.id);

        if (targetIds.length === 0) return;

        setReading(true);

        const runNext = (index) => {
            if (index >= targetIds.length) {
                setReading(false);
                router.reload({ only: ['logs'], preserveScroll: true });
                return;
            }

            router.post(
                route('devices.sync-attendance', targetIds[index]),
                {},
                {
                    preserveScroll: true,
                    preserveState: true,
                    onFinish: () => runNext(index + 1),
                },
            );
        };

        runNext(0);
    }

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <h2 className="text-xl font-semibold leading-tight text-gray-800">
                            Attendance Logs
                        </h2>
                        <span
                            className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ${
                                liveConnected ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-400'
                            }`}
                            title={
                                liveConnected
                                    ? 'Connected — new punches appear automatically'
                                    : 'Not connected — use "Read from Device" to pull new punches'
                            }
                        >
                            <span
                                className={`h-1.5 w-1.5 rounded-full ${liveConnected ? 'bg-emerald-500' : 'bg-gray-300'}`}
                            />
                            {liveConnected ? 'Live' : 'Offline'}
                        </span>
                    </div>
                    <PrimaryButton onClick={readFromDevice} disabled={reading}>
                        <RefreshIcon className={`-ml-0.5 mr-1.5 h-4 w-4 ${reading ? 'animate-spin' : ''}`} />
                        {reading ? 'Reading…' : 'Read from Device'}
                    </PrimaryButton>
                </div>
            }
        >
            <Head title="Attendance" />

            <div className="pt-6 pb-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <p className="mb-4 text-xs text-gray-500">
                        "Read from Device" pulls fresh punches from the device selected below (or every device, if "All devices" is selected) and refreshes this list.
                    </p>
                    <form
                        onSubmit={applyFilters}
                        className="mb-4 flex flex-wrap items-end gap-4 rounded-lg bg-white p-4 shadow-sm"
                    >
                        <div>
                            <InputLabel htmlFor="device_id" value="Device" />
                            <select
                                id="device_id"
                                className="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                value={deviceId}
                                onChange={(e) => setDeviceId(e.target.value)}
                            >
                                <option value="">All devices</option>
                                {devices.map((d) => (
                                    <option key={d.id} value={d.id}>
                                        {d.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <InputLabel htmlFor="from" value="From" />
                            <TextInput
                                id="from"
                                type="date"
                                className="mt-1 block"
                                value={from}
                                onChange={(e) => setFrom(e.target.value)}
                            />
                        </div>
                        <div>
                            <InputLabel htmlFor="to" value="To" />
                            <TextInput
                                id="to"
                                type="date"
                                className="mt-1 block"
                                value={to}
                                onChange={(e) => setTo(e.target.value)}
                            />
                        </div>
                        <SecondaryButton type="submit">
                            <SignalIcon className="-ml-0.5 mr-1.5 h-4 w-4" />
                            Filter
                        </SecondaryButton>
                    </form>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200 text-sm">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">When</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Employee</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Device</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Method</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {logs.data.length === 0 && (
                                        <tr>
                                            <td colSpan={4} className="px-4 py-16">
                                                <div className="flex flex-col items-center gap-2 text-center text-gray-500">
                                                    <InboxIcon className="h-8 w-8 text-gray-300" />
                                                    <p className="font-medium text-gray-600">No attendance records found</p>
                                                    <p className="text-xs">Try widening the date range, or sync a device to pull in punches.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    )}
                                    {logs.data.map((log) => (
                                        <tr key={log.id} className="hover:bg-gray-50/75">
                                            <td className="px-4 py-3 text-gray-600 tabular-nums">
                                                {new Date(log.punched_at).toLocaleString()}
                                            </td>
                                            <td className="px-4 py-3 text-gray-900">
                                                {log.employee ? (
                                                    <>
                                                        {log.employee.name}{' '}
                                                        <span className="font-mono text-xs text-gray-400">({log.employee.employee_code})</span>
                                                    </>
                                                ) : (
                                                    <Badge variant="warning">Unmapped ({log.device_user_id})</Badge>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-gray-600">
                                                {log.device?.name ?? '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge variant="neutral">
                                                    {VERIFY_METHODS[log.verify_method] ?? log.verify_method}
                                                </Badge>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {logs.links && (
                            <div className="flex flex-wrap items-center gap-1 border-t border-gray-100 p-4">
                                {logs.links.map((link, i) => (
                                    <Link
                                        key={i}
                                        href={link.url ?? '#'}
                                        preserveScroll
                                        className={`rounded-md px-3 py-1.5 text-sm transition duration-150 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1 ${
                                            link.active
                                                ? 'bg-gray-800 text-white'
                                                : 'text-gray-600 hover:bg-gray-100'
                                        } ${!link.url ? 'pointer-events-none opacity-40' : ''}`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
