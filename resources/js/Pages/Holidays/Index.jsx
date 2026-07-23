import { CalendarIcon, InboxIcon, PlusIcon, TrashIcon } from '@/Components/Icons';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TableActionButton from '@/Components/TableActionButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';

export default function Index({ holidays }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        date: '',
        name: '',
    });

    function submit(e) {
        e.preventDefault();
        post(route('holidays.store'), { onSuccess: () => reset() });
    }

    function destroy(holiday) {
        if (!confirm(`Remove "${holiday.name}" (${holiday.date})?`)) return;
        router.delete(route('holidays.destroy', holiday.id));
    }

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Public Holidays
                </h2>
            }
        >
            <Head title="Holidays" />

            <div className="pt-6 pb-12">
                <div className="mx-auto max-w-4xl sm:px-6 lg:px-8">
                    <p className="mb-4 text-xs leading-relaxed text-gray-500">
                        Dates listed here are excused for every employee - an absence on one of
                        these days won't count against attendance and is still credited its full
                        shift length toward payroll hours.
                    </p>

                    <form
                        onSubmit={submit}
                        className="mb-6 flex flex-wrap items-end gap-4 rounded-lg bg-white p-4 shadow-sm"
                    >
                        <div>
                            <InputLabel htmlFor="date" value="Date" />
                            <TextInput
                                id="date"
                                type="date"
                                className="mt-1 block"
                                value={data.date}
                                onChange={(e) => setData('date', e.target.value)}
                            />
                            <InputError message={errors.date} className="mt-1" />
                        </div>
                        <div className="flex-1">
                            <InputLabel htmlFor="name" value="Name" />
                            <TextInput
                                id="name"
                                className="mt-1 block w-full"
                                placeholder="e.g. Independence Day"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                            <InputError message={errors.name} className="mt-1" />
                        </div>
                        <PrimaryButton disabled={processing}>
                            <PlusIcon className="-ml-0.5 mr-1.5 h-4 w-4" />
                            Add Holiday
                        </PrimaryButton>
                    </form>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200 text-sm">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Date</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Name</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {holidays.length === 0 && (
                                        <tr>
                                            <td colSpan={3} className="px-4 py-16">
                                                <div className="flex flex-col items-center gap-2 text-center text-gray-500">
                                                    <InboxIcon className="h-8 w-8 text-gray-300" />
                                                    <p className="font-medium text-gray-600">No holidays added yet</p>
                                                </div>
                                            </td>
                                        </tr>
                                    )}
                                    {holidays.map((holiday) => (
                                        <tr key={holiday.id} className="hover:bg-gray-50/75">
                                            <td className="px-4 py-3">
                                                <span className="flex items-center gap-2 font-medium text-gray-900">
                                                    <CalendarIcon className="h-4 w-4 text-gray-400" />
                                                    {new Date(holiday.date).toLocaleDateString(undefined, {
                                                        weekday: 'short',
                                                        year: 'numeric',
                                                        month: 'short',
                                                        day: 'numeric',
                                                    })}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-gray-600">{holiday.name}</td>
                                            <td className="px-4 py-3 text-right">
                                                <TableActionButton
                                                    icon={TrashIcon}
                                                    variant="danger"
                                                    onClick={() => destroy(holiday)}
                                                >
                                                    Delete
                                                </TableActionButton>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
