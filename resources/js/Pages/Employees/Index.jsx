import Dropdown from '@/Components/Dropdown';
import EmployeeProfileModal from '@/Components/EmployeeProfileModal';
import {
    ChevronDownIcon,
    CreditCardIcon,
    FingerprintIcon,
    InboxIcon,
    PencilIcon,
    PlusIcon,
    SignalIcon,
    SpinnerIcon,
    TrashIcon,
    UserMinusIcon,
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
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

function initials(name) {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

// Matches the device's own finger numbering (0-4 right hand, 5-9 left hand).
const FINGER_LABELS = [
    'Right thumb',
    'Right index',
    'Right middle',
    'Right ring',
    'Right little',
    'Left thumb',
    'Left index',
    'Left middle',
    'Left ring',
    'Left little',
];

export default function Index({ employees, binaryDevices }) {
    const [showModal, setShowModal] = useState(false);
    const [editingEmployee, setEditingEmployee] = useState(null);
    const [busyEmployeeId, setBusyEmployeeId] = useState(null);
    const [busyAction, setBusyAction] = useState(null);
    const [enrollEmployee, setEnrollEmployee] = useState(null);
    const [fingerIndex, setFingerIndex] = useState(1);
    const [enrolling, setEnrolling] = useState(false);
    const [cardEnrollEmployee, setCardEnrollEmployee] = useState(null);
    const [cardEnrolling, setCardEnrolling] = useState(false);
    const [profileEmployee, setProfileEmployee] = useState(null);
    const primaryDevice = binaryDevices?.[0] ?? null;

    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '',
        employee_code: '',
        email: '',
        department: '',
    });

    function openAdd() {
        reset();
        setEditingEmployee(null);
        setShowModal(true);
    }

    function openEdit(employee) {
        setData({
            name: employee.name,
            employee_code: employee.employee_code,
            email: employee.email ?? '',
            department: employee.department ?? '',
        });
        setEditingEmployee(employee);
        setShowModal(true);
    }

    function submit(e) {
        e.preventDefault();

        if (editingEmployee) {
            put(route('employees.update', editingEmployee.id), {
                onSuccess: () => setShowModal(false),
            });
        } else {
            post(route('employees.store'), {
                onSuccess: () => setShowModal(false),
            });
        }
    }

    function destroy(employee) {
        if (!confirm(`Remove employee "${employee.name}"?`)) return;
        router.delete(route('employees.destroy', employee.id));
    }

    function pushToDevice(employee) {
        if (!primaryDevice) return;
        setBusyEmployeeId(employee.id);
        setBusyAction('push');
        router.post(
            route('employees.push-to-device', [employee.id, primaryDevice.id]),
            {},
            {
                onFinish: () => {
                    setBusyEmployeeId(null);
                    setBusyAction(null);
                },
                preserveScroll: true,
            },
        );
    }

    function removeFromDevice(employee) {
        if (!primaryDevice) return;
        if (!confirm(`Remove "${employee.name}" from ${primaryDevice.name}? They will no longer be able to badge in/out until re-enrolled.`)) return;
        setBusyEmployeeId(employee.id);
        setBusyAction('remove');
        router.post(
            route('employees.remove-from-device', [employee.id, primaryDevice.id]),
            {},
            {
                onFinish: () => {
                    setBusyEmployeeId(null);
                    setBusyAction(null);
                },
                preserveScroll: true,
            },
        );
    }

    // A single source of truth for "is some device operation in flight for this
    // employee" - covers the fire-and-forget push/remove requests below as well
    // as the two enrollment modals, so every device action on a row (not just
    // the one that was clicked) disables while any of them is running. Two
    // concurrent operations against the same physical device can otherwise
    // step on each other (e.g. one cancels the other's capture mode).
    const isDeviceBusy = (employee) => busyEmployeeId === employee.id;
    const isBusy = (employee, action) => busyEmployeeId === employee.id && busyAction === action;

    function openEnroll(employee) {
        setEnrollEmployee(employee);
        setFingerIndex(1);
    }

    function submitEnroll(e) {
        e.preventDefault();
        if (!primaryDevice || !enrollEmployee) return;

        setEnrolling(true);
        setBusyEmployeeId(enrollEmployee.id);
        setBusyAction('enroll-finger');
        router.post(
            route('employees.enroll-fingerprint', [enrollEmployee.id, primaryDevice.id]),
            { finger_index: fingerIndex },
            {
                onFinish: () => {
                    setEnrolling(false);
                    setEnrollEmployee(null);
                    setBusyEmployeeId(null);
                    setBusyAction(null);
                },
                preserveScroll: true,
            },
        );
    }

    function openCardEnroll(employee) {
        setCardEnrollEmployee(employee);
    }

    function submitCardEnroll() {
        if (!primaryDevice || !cardEnrollEmployee) return;

        setCardEnrolling(true);
        setBusyEmployeeId(cardEnrollEmployee.id);
        setBusyAction('enroll-card');
        router.post(
            route('employees.enroll-card', [cardEnrollEmployee.id, primaryDevice.id]),
            {},
            {
                onFinish: () => {
                    setCardEnrolling(false);
                    setCardEnrollEmployee(null);
                    setBusyEmployeeId(null);
                    setBusyAction(null);
                },
                preserveScroll: true,
            },
        );
    }

    function openProfile(employee) {
        setProfileEmployee(employee);
    }

    function handleRowKeyDown(e, employee) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            openProfile(employee);
        }
    }

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        Employees
                    </h2>
                    <PrimaryButton onClick={openAdd}>
                        <PlusIcon className="-ml-0.5 mr-1.5 h-4 w-4" />
                        Add Employee
                    </PrimaryButton>
                </div>
            }
        >
            <Head title="Employees" />

            <div className="pt-6 pb-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    {primaryDevice ? (
                        <p className="mb-4 text-xs leading-relaxed text-gray-500">
                            <span className="font-medium text-gray-600">Push to Device</span> enrolls/updates this employee on {primaryDevice.name} over TCP/IP so they can badge in/out. {' '}
                            <span className="font-medium text-gray-600">Remove from Device</span> un-enrolls them from the device only — Delete removes the payroll record entirely.
                        </p>
                    ) : (
                        <div className="mb-4 flex items-center gap-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-700">
                            <SignalIcon className="h-4 w-4 shrink-0" />
                            No active biometric device is configured, so device actions (push, fingerprint,
                            card) aren't available here. {' '}
                            <Link href={route('devices.index')} className="font-medium underline hover:text-amber-900">
                                Add one in Devices
                            </Link>
                            .
                        </div>
                    )}
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200 text-sm">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Name</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Employee Code</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Department</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Punches</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {employees.length === 0 && (
                                        <tr>
                                            <td colSpan={5} className="px-4 py-16">
                                                <div className="flex flex-col items-center gap-2 text-center text-gray-500">
                                                    <InboxIcon className="h-8 w-8 text-gray-300" />
                                                    <p className="font-medium text-gray-600">No employees added yet</p>
                                                    <p className="text-xs">Add someone manually, or import the roster straight from a device.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    )}
                                    {employees.map((employee) => (
                                        <tr
                                            key={employee.id}
                                            onClick={() => openProfile(employee)}
                                            onKeyDown={(e) => handleRowKeyDown(e, employee)}
                                            role="button"
                                            tabIndex={0}
                                            aria-label={`View attendance profile for ${employee.name}`}
                                            className="cursor-pointer transition hover:bg-indigo-50/60 focus:outline-none focus-visible:bg-indigo-50/60 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500"
                                        >
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-3">
                                                    {employee.photo_url ? (
                                                        <img
                                                            src={employee.photo_url}
                                                            alt={employee.name}
                                                            className="h-8 w-8 flex-shrink-0 rounded-full object-cover"
                                                        />
                                                    ) : (
                                                        <span className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-indigo-50 text-xs font-semibold text-indigo-600">
                                                            {initials(employee.name) || '?'}
                                                        </span>
                                                    )}
                                                    <span className="font-medium text-gray-900">{employee.name}</span>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 font-mono text-xs text-gray-600">
                                                {employee.employee_code}
                                            </td>
                                            <td className="px-4 py-3 text-gray-600">
                                                {employee.department ?? '—'}
                                            </td>
                                            <td className="px-4 py-3 text-gray-600 tabular-nums">
                                                {employee.attendance_logs_count}
                                            </td>
                                            <td className="px-4 py-3 text-right" onClick={(e) => e.stopPropagation()}>
                                                <div className="inline-flex flex-wrap items-center justify-end gap-1.5">
                                                    {primaryDevice && (
                                                        <Dropdown>
                                                            <Dropdown.Trigger>
                                                                <button
                                                                    type="button"
                                                                    disabled={isDeviceBusy(employee)}
                                                                    className="inline-flex items-center gap-1.5 rounded-md border border-indigo-200 bg-white px-2.5 py-1.5 text-xs font-medium text-indigo-700 shadow-sm transition duration-150 ease-in-out hover:border-indigo-300 hover:bg-indigo-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1 disabled:pointer-events-none disabled:opacity-40"
                                                                >
                                                                    {isDeviceBusy(employee) ? (
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
                                                                    disabled={isDeviceBusy(employee)}
                                                                    onClick={() => pushToDevice(employee)}
                                                                >
                                                                    <UserPlusIcon className="h-4 w-4 text-indigo-600" />
                                                                    {isBusy(employee, 'push') ? 'Pushing…' : 'Push to Device'}
                                                                </Dropdown.Button>
                                                                <Dropdown.Button
                                                                    disabled={isDeviceBusy(employee)}
                                                                    onClick={() => openEnroll(employee)}
                                                                >
                                                                    <FingerprintIcon className="h-4 w-4 text-indigo-600" />
                                                                    Enroll Fingerprint
                                                                </Dropdown.Button>
                                                                <Dropdown.Button
                                                                    disabled={isDeviceBusy(employee)}
                                                                    onClick={() => openCardEnroll(employee)}
                                                                >
                                                                    <CreditCardIcon className="h-4 w-4 text-indigo-600" />
                                                                    Enroll Card
                                                                </Dropdown.Button>
                                                                <Dropdown.Button
                                                                    disabled={isDeviceBusy(employee)}
                                                                    onClick={() => removeFromDevice(employee)}
                                                                    className="text-amber-700 hover:bg-amber-50"
                                                                >
                                                                    <UserMinusIcon className="h-4 w-4 text-amber-600" />
                                                                    {isBusy(employee, 'remove') ? 'Removing…' : 'Remove from Device'}
                                                                </Dropdown.Button>
                                                            </Dropdown.Content>
                                                        </Dropdown>
                                                    )}
                                                    <TableActionButton icon={PencilIcon} onClick={() => openEdit(employee)}>
                                                        Edit
                                                    </TableActionButton>
                                                    <TableActionButton icon={TrashIcon} variant="danger" onClick={() => destroy(employee)}>
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

            <Modal show={showModal} onClose={() => setShowModal(false)}>
                <form onSubmit={submit} className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        {editingEmployee ? 'Edit Employee' : 'Add Employee'}
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
                        <InputLabel htmlFor="employee_code" value="Employee Code" />
                        <p className="text-xs text-gray-500">
                            Must match the "User ID" this person is enrolled under on the biometric device.
                            Use numbers only — the device rejects "Push to Device" for non-numeric codes.
                        </p>
                        <TextInput
                            id="employee_code"
                            className="mt-1 block w-full"
                            value={data.employee_code}
                            onChange={(e) => setData('employee_code', e.target.value)}
                        />
                        <InputError message={errors.employee_code} className="mt-1" />
                    </div>

                    <div className="mt-4">
                        <InputLabel htmlFor="email" value="Email (optional)" />
                        <TextInput
                            id="email"
                            type="email"
                            className="mt-1 block w-full"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                        />
                        <InputError message={errors.email} className="mt-1" />
                    </div>

                    <div className="mt-4">
                        <InputLabel htmlFor="department" value="Department (optional)" />
                        <TextInput
                            id="department"
                            className="mt-1 block w-full"
                            value={data.department}
                            onChange={(e) => setData('department', e.target.value)}
                        />
                        <InputError message={errors.department} className="mt-1" />
                    </div>

                    <div className="mt-6 flex justify-end space-x-3">
                        <SecondaryButton type="button" onClick={() => setShowModal(false)} disabled={processing}>
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={processing}>
                            {processing ? (editingEmployee ? 'Saving…' : 'Adding…') : editingEmployee ? 'Save' : 'Add'}
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>

            <Modal show={enrollEmployee !== null} onClose={() => (!enrolling ? setEnrollEmployee(null) : null)}>
                <form onSubmit={submitEnroll} className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        Enroll Fingerprint — {enrollEmployee?.name}
                    </h2>
                    <p className="mt-1 text-sm text-gray-500">
                        This puts {primaryDevice?.name} into fingerprint-capture mode for this person.
                        It will show their name on its own screen and prompt for a finger — have them
                        stand at the device and place the selected finger 3 times when asked.
                    </p>

                    <div className="mt-4">
                        <InputLabel htmlFor="finger_index" value="Finger" />
                        <select
                            id="finger_index"
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            value={fingerIndex}
                            disabled={enrolling}
                            onChange={(e) => setFingerIndex(Number(e.target.value))}
                        >
                            {FINGER_LABELS.map((label, index) => (
                                <option key={index} value={index}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </div>

                    {enrolling && (
                        <div className="mt-4 flex items-center gap-2 rounded-md bg-indigo-50 px-3 py-2 text-sm text-indigo-700">
                            <FingerprintIcon className="h-4 w-4 animate-pulse" />
                            Waiting for the fingerprint to be captured on the device… this can take up to 30 seconds.
                        </div>
                    )}

                    <div className="mt-6 flex justify-end space-x-3">
                        <SecondaryButton type="button" onClick={() => setEnrollEmployee(null)} disabled={enrolling}>
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={enrolling}>
                            {enrolling ? 'Waiting…' : 'Start Enrollment'}
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>

            <Modal show={cardEnrollEmployee !== null} onClose={() => (!cardEnrolling ? setCardEnrollEmployee(null) : null)}>
                <div className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        Enroll Card — {cardEnrollEmployee?.name}
                    </h2>
                    <p className="mt-1 text-sm text-gray-500">
                        This puts {primaryDevice?.name} into card-read mode for this person and beeps to
                        confirm it started. Have them tap their RFID card on the device within 20 seconds —
                        it'll beep again once the card is captured and saved.
                    </p>

                    {cardEnrolling && (
                        <div className="mt-4 flex items-center gap-2 rounded-md bg-indigo-50 px-3 py-2 text-sm text-indigo-700">
                            <CreditCardIcon className="h-4 w-4 animate-pulse" />
                            Waiting for the card to be scanned on the device…
                        </div>
                    )}

                    <div className="mt-6 flex justify-end space-x-3">
                        <SecondaryButton type="button" onClick={() => setCardEnrollEmployee(null)} disabled={cardEnrolling}>
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton type="button" disabled={cardEnrolling} onClick={submitCardEnroll}>
                            {cardEnrolling ? 'Waiting…' : 'Start Enrollment'}
                        </PrimaryButton>
                    </div>
                </div>
            </Modal>

            <EmployeeProfileModal employee={profileEmployee} onClose={() => setProfileEmployee(null)} />
        </AuthenticatedLayout>
    );
}
