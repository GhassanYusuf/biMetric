import Badge from '@/Components/Badge';
import Dropdown from '@/Components/Dropdown';
import {
    ChevronDownIcon,
    InboxIcon,
    PencilIcon,
    PlusIcon,
    RefreshIcon,
    SignalIcon,
    SpinnerIcon,
    TrashIcon,
    UserPlusIcon,
} from '@/Components/Icons';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TableActionButton from '@/Components/TableActionButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ devices }) {
    const confirm = useConfirm();
    const [showAddModal, setShowAddModal] = useState(false);
    const [editingDevice, setEditingDevice] = useState(null);
    const [busyDeviceId, setBusyDeviceId] = useState(null);
    const [busyAction, setBusyAction] = useState(null);

    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '',
        ip_address: '',
        port: 4370,
        protocol: 'binary',
        comm_key: '',
        web_username: '',
        web_password: '',
        location: '',
    });

    function openAdd() {
        reset();
        setEditingDevice(null);
        setShowAddModal(true);
    }

    function openEdit(device) {
        setData({
            name: device.name,
            ip_address: device.ip_address,
            port: device.port,
            protocol: device.protocol ?? 'binary',
            comm_key: '',
            web_username: device.web_username ?? '',
            web_password: '',
            location: device.location ?? '',
        });
        setEditingDevice(device);
        setShowAddModal(true);
    }

    function submit(e) {
        e.preventDefault();

        if (editingDevice) {
            put(route('devices.update', editingDevice.id), {
                onSuccess: () => setShowAddModal(false),
            });
        } else {
            post(route('devices.store'), {
                onSuccess: () => setShowAddModal(false),
            });
        }
    }

    async function destroy(device) {
        const ok = await confirm({
            title: `Remove device "${device.name}"?`,
            body: 'Punches already imported from it stay in attendance history.',
            confirmLabel: 'Remove device',
            danger: true,
        });
        if (!ok) return;

        router.delete(route('devices.destroy', device.id));
    }

    function runAction(routeName, action, device) {
        setBusyDeviceId(device.id);
        setBusyAction(action);
        router.post(
            route(routeName, device.id),
            {},
            {
                onFinish: () => {
                    setBusyDeviceId(null);
                    setBusyAction(null);
                },
                preserveScroll: true,
            },
        );
    }

    const isBusy = (device, action) => busyDeviceId === device.id && busyAction === action;

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        Biometric Devices
                    </h2>
                    <PrimaryButton onClick={openAdd}>
                        <PlusIcon className="-ml-0.5 mr-1.5 h-4 w-4" />
                        Add Device
                    </PrimaryButton>
                </div>
            }
        >
            <Head title="Devices" />

            <div className="pt-6 pb-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200 text-sm">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Name</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">IP : Port</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Protocol</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Location</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Punches</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Last Sync</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {devices.length === 0 && (
                                        <tr>
                                            <td colSpan={8} className="px-4 py-16">
                                                <div className="flex flex-col items-center gap-2 text-center text-gray-500">
                                                    <InboxIcon className="h-8 w-8 text-gray-300" />
                                                    <p className="font-medium text-gray-600">No devices added yet</p>
                                                    <p className="text-xs">Add a biometric terminal to start pulling attendance data.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    )}
                                    {devices.map((device) => (
                                        <tr key={device.id} className="hover:bg-gray-50/75">
                                            <td className="px-4 py-3 font-medium text-gray-900">
                                                <div className="flex items-center gap-2">
                                                    {device.name}
                                                    {!device.is_active && <Badge variant="neutral">inactive</Badge>}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 font-mono text-xs text-gray-600">
                                                {device.ip_address}:{device.port}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge variant={device.protocol === 'csl_http' ? 'info' : 'neutral'}>
                                                    {device.protocol === 'csl_http' ? 'CSL/Secutime (web)' : 'Binary (4370)'}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-gray-600">
                                                {device.location ?? '—'}
                                            </td>
                                            <td className="px-4 py-3 text-gray-600 tabular-nums">
                                                {device.attendance_logs_count}
                                            </td>
                                            <td className="px-4 py-3 text-gray-600">
                                                {device.last_synced_at
                                                    ? new Date(device.last_synced_at).toLocaleString()
                                                    : 'never'}
                                            </td>
                                            <td className="px-4 py-3">
                                                {device.last_sync_status === 'error' ? (
                                                    <Badge
                                                        variant="error"
                                                        title={device.last_sync_error}
                                                        className="cursor-help decoration-dotted underline underline-offset-2"
                                                    >
                                                        error
                                                    </Badge>
                                                ) : device.last_sync_status === 'success' ? (
                                                    <Badge variant="success">ok</Badge>
                                                ) : (
                                                    <span className="text-xs text-gray-400">—</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="inline-flex flex-wrap items-center justify-end gap-1.5">
                                                    <Dropdown>
                                                        <Dropdown.Trigger>
                                                            <button
                                                                type="button"
                                                                disabled={busyDeviceId === device.id}
                                                                className="inline-flex items-center gap-1.5 rounded-md border border-indigo-200 bg-white px-2.5 py-1.5 text-xs font-medium text-indigo-700 shadow-sm transition duration-150 ease-in-out hover:border-indigo-300 hover:bg-indigo-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1 disabled:pointer-events-none disabled:opacity-40"
                                                            >
                                                                {busyDeviceId === device.id ? (
                                                                    <SpinnerIcon className="h-3.5 w-3.5" />
                                                                ) : (
                                                                    <SignalIcon className="h-3.5 w-3.5" />
                                                                )}
                                                                <span>Device</span>
                                                                <ChevronDownIcon className="h-3.5 w-3.5" />
                                                            </button>
                                                        </Dropdown.Trigger>
                                                        <Dropdown.Content align="right" width="56">
                                                            <Dropdown.Button
                                                                disabled={busyDeviceId === device.id}
                                                                onClick={() => runAction('devices.test-connection', 'test', device)}
                                                            >
                                                                <SignalIcon className="h-4 w-4 text-indigo-600" />
                                                                {isBusy(device, 'test') ? 'Testing…' : 'Test Connection'}
                                                            </Dropdown.Button>
                                                            <Dropdown.Button
                                                                disabled={busyDeviceId === device.id}
                                                                onClick={() => runAction('devices.import-employees', 'import', device)}
                                                            >
                                                                <UserPlusIcon className="h-4 w-4 text-indigo-600" />
                                                                {isBusy(device, 'import') ? 'Importing…' : 'Import Employees'}
                                                            </Dropdown.Button>
                                                            <Dropdown.Button
                                                                disabled={busyDeviceId === device.id}
                                                                onClick={() => runAction('devices.sync-attendance', 'sync', device)}
                                                            >
                                                                <RefreshIcon className="h-4 w-4 text-indigo-600" />
                                                                {isBusy(device, 'sync') ? 'Syncing…' : 'Sync Attendance'}
                                                            </Dropdown.Button>
                                                        </Dropdown.Content>
                                                    </Dropdown>
                                                    <TableActionButton
                                                        icon={PencilIcon}
                                                        disabled={busyDeviceId === device.id}
                                                        onClick={() => openEdit(device)}
                                                    >
                                                        Edit
                                                    </TableActionButton>
                                                    <TableActionButton
                                                        icon={TrashIcon}
                                                        variant="danger"
                                                        disabled={busyDeviceId === device.id}
                                                        onClick={() => destroy(device)}
                                                    >
                                                        Delete
                                                    </TableActionButton>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <Modal show={showAddModal} onClose={() => setShowAddModal(false)}>
                <form onSubmit={submit} className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        {editingDevice ? 'Edit Device' : 'Add Device'}
                    </h2>

                    <div className="mt-4">
                        <InputLabel htmlFor="name" value="Name" />
                        <TextInput
                            id="name"
                            className="mt-1 block w-full"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                        />
                        <InputError message={errors.name} className="mt-1" />
                    </div>

                    <div className="mt-4">
                        <InputLabel htmlFor="ip_address" value="IP Address" />
                        <TextInput
                            id="ip_address"
                            placeholder="192.168.1.201"
                            className="mt-1 block w-full"
                            value={data.ip_address}
                            onChange={(e) => setData('ip_address', e.target.value)}
                        />
                        <InputError message={errors.ip_address} className="mt-1" />
                    </div>

                    <div className="mt-4">
                        <InputLabel htmlFor="port" value="Port" />
                        <TextInput
                            id="port"
                            type="number"
                            className="mt-1 block w-full"
                            value={data.port}
                            onChange={(e) => setData('port', e.target.value)}
                        />
                        <InputError message={errors.port} className="mt-1" />
                    </div>

                    <div className="mt-4">
                        <InputLabel htmlFor="protocol" value="Protocol" />
                        <select
                            id="protocol"
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            value={data.protocol}
                            onChange={(e) => setData('protocol', e.target.value)}
                        >
                            <option value="binary">Binary (classic ZK protocol, port 4370)</option>
                            <option value="csl_http">CSL/Secutime web export (HTTP login)</option>
                        </select>
                        <p className="mt-1 text-xs text-gray-500">
                            If "Test" fails with a timeout on Binary, your device likely uses reseller-branded
                            firmware ("Secutime"/"CSL") — try the web export option instead.
                        </p>
                        <InputError message={errors.protocol} className="mt-1" />
                    </div>

                    {data.protocol === 'binary' && (
                        <div className="mt-4">
                            <InputLabel htmlFor="comm_key" value="Comm Key (optional)" />
                            <TextInput
                                id="comm_key"
                                type="password"
                                placeholder={editingDevice ? '(unchanged)' : ''}
                                className="mt-1 block w-full"
                                value={data.comm_key}
                                onChange={(e) => setData('comm_key', e.target.value)}
                            />
                            <InputError message={errors.comm_key} className="mt-1" />
                        </div>
                    )}

                    {data.protocol === 'csl_http' && (
                        <>
                            <div className="mt-4">
                                <InputLabel htmlFor="web_username" value="Web Login ID" />
                                <TextInput
                                    id="web_username"
                                    className="mt-1 block w-full"
                                    value={data.web_username}
                                    onChange={(e) => setData('web_username', e.target.value)}
                                />
                                <InputError message={errors.web_username} className="mt-1" />
                            </div>
                            <div className="mt-4">
                                <InputLabel htmlFor="web_password" value="Web Password" />
                                <TextInput
                                    id="web_password"
                                    type="password"
                                    placeholder={editingDevice ? '(unchanged)' : ''}
                                    className="mt-1 block w-full"
                                    value={data.web_password}
                                    onChange={(e) => setData('web_password', e.target.value)}
                                />
                                <InputError message={errors.web_password} className="mt-1" />
                            </div>
                        </>
                    )}

                    <div className="mt-4">
                        <InputLabel htmlFor="location" value="Location (optional)" />
                        <TextInput
                            id="location"
                            className="mt-1 block w-full"
                            value={data.location}
                            onChange={(e) => setData('location', e.target.value)}
                        />
                        <InputError message={errors.location} className="mt-1" />
                    </div>

                    <div className="mt-6 flex justify-end space-x-3">
                        <SecondaryButton type="button" onClick={() => setShowAddModal(false)} disabled={processing}>
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={processing}>
                            {processing ? (editingDevice ? 'Saving…' : 'Adding…') : editingDevice ? 'Save' : 'Add'}
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
