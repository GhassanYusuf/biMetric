<?php

namespace App\Services\Import;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use Carbon\Carbon;

/**
 * Turns the system's current state into the same canonical snapshot shape the
 * MDB reader (and scripts/export-zkaccess.ps1) produce, so a backup downloaded
 * from this screen can be re-imported into this or any other tenant, or handed
 * to the MDB reader's importer unchanged.
 *
 * ROUND TRIP: the importer resolves a punch to an employee through the punch's
 * `user_id` looking up the users list's `user_id`, stores device_user_id = PIN,
 * dedupes on (device, pin, UTC timestamp, verify), and reads `at` as terminal
 * local time (Asia/Baghdad, UTC+3). Every date and every key on the way out is
 * therefore emitted in exactly that coordinate system:
 *
 *   - users here are our employees, so their `user_id` is our employee id and
 *     their PIN is employee_code → re-import matches by PIN to the same rows;
 *   - punches carry the employee id as `user_id`, the employee's PIN, the
 *     device serial, `at` in Asia/Baghdad, and O/I derived from status;
 *   - null employee_id stays an orphan (no `user_id`) exactly like the MDB's
 *     no-longer-matched USERIDs, so a restore never fabricates attendance.
 */
class ZkaccessSnapshotExportService
{
    /**
     * @return array{
     *     source: string,
     *     exported_at: string,
     *     departments: array<int,array<string,string>>,
     *     machines: array<int,array<string,string>>,
     *     users: array<int,array<string,string>>,
     *     punches: array<int,array<string,string>>,
     * }
     */
    public function snapshot(int $tenantId): array
    {
        $departments = Department::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get(['id', 'name', 'code'])
            ->map(fn (Department $d): array => [
                'id' => (string) $d->id,
                'name' => (string) $d->name,
                'parent_id' => '',
                'code' => (string) ($d->code ?? ''),
            ])
            ->all();

        $machines = Device::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get(['id', 'name', 'ip_address', 'serial_number', 'model', 'firmware_version'])
            ->map(fn (Device $d): array => [
                'id' => (string) $d->id,
                'name' => (string) ($d->name ?? ''),
                'ip' => (string) ($d->ip_address ?? ''),
                'serial' => (string) ($d->serial_number ?? ''),
                'product' => (string) ($d->model ?? ''),
                'firmware' => (string) ($d->firmware_version ?? ''),
                'machine_number' => '',
            ])
            ->all();

        $serialByDevice = [];
        $ipByDevice = [];
        foreach ($machines as $machine) {
            $serialByDevice[$machine['id']] = $machine['serial'];
            $ipByDevice[$machine['id']] = $machine['ip'];
        }

        $users = [];
        $pinByEmployeeId = [];
        $employees = Employee::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get(['id', 'employee_code', 'name', 'last_name', 'gender', 'card_number',
                'hired_date', 'birthday', 'department_id', 'device_password', 'email',
                'mobile_phone', 'home_phone', 'home_address', 'city', 'country', 'education',
                'political_status', 'job_title']);

        foreach ($employees as $e) {
            $pin = (string) ($e->employee_code ?? '');
            $pinByEmployeeId[$e->id] = $pin;
            $users[] = [
                'user_id' => (string) $e->id,
                'pin' => $pin,
                'first_name' => (string) ($e->name ?? ''),
                'last_name' => (string) ($e->last_name ?? ''),
                'gender' => (string) ($e->gender ?? ''),
                'card' => $e->card_number === null ? '' : (string) $e->card_number,
                'hire_date' => $e->hired_date instanceof Carbon ? $e->hired_date->format('Y-m-d') : '',
                'birth_date' => $e->birthday instanceof Carbon ? $e->birthday->format('Y-m-d') : '',
                'department_id' => $e->department_id === null ? '' : (string) $e->department_id,
                'privilege' => '',
                'password' => (string) ($e->device_password ?? ''),
                'email' => (string) ($e->email ?? ''),
                'mobile_phone' => (string) ($e->mobile_phone ?? ''),
                'home_phone' => (string) ($e->home_phone ?? ''),
                'home_address' => (string) ($e->home_address ?? ''),
                'street' => '',
                'city' => (string) ($e->city ?? ''),
                'country' => (string) ($e->country ?? ''),
                'education' => (string) ($e->education ?? ''),
                'political' => (string) ($e->political_status ?? ''),
                'job_title' => (string) ($e->job_title ?? ''),
                'emptype' => '',
                'status' => $e->is_active ? '' : '0',
                'deleted' => '0',
            ];
        }

        $punches = [];
        AttendanceLog::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->chunk(1000, function ($rows) use (&$punches, $pinByEmployeeId, $serialByDevice, $ipByDevice): void {
                foreach ($rows as $log) {
                    $employeeId = $log->employee_id;
                    $at = $log->punched_at instanceof Carbon
                        ? $log->punched_at->copy()
                        : Carbon::parse((string) $log->punched_at);

                    $punches[] = [
                        'user_id' => $employeeId === null ? '' : (string) $employeeId,
                        // The stored roster identity, not today's employee_code: a
                        // PIN renumbered after a sync would otherwise rebuild a key
                        // the database never held and re-import would duplicate the
                        // old rows. Punches keep the identity they were written with.
                        'pin' => (string) $log->device_user_id !== ''
                            ? (string) $log->device_user_id
                            : ($pinByEmployeeId[$employeeId] ?? ''),
                        'serial' => $serialByDevice[(string) ($log->device_id ?? '')] ?? '',
                        // Terminals whose serial is blank (rarely on record for the
                        // older IT-room units) still resolve on re-import through IP.
                        'ip' => $ipByDevice[(string) ($log->device_id ?? '')] ?? '',
                        'at' => $at->setTimezone('Asia/Baghdad')->format('Y-m-d H:i:s'),
                        'type' => (int) $log->status === 1 ? 'O' : 'I',
                        'verify' => (int) ($log->verify_method ?? 0),
                    ];
                }
            });

        return [
            'source' => 'snapshot',
            'exported_at' => now()->format('Y-m-d H:i:s'),
            'departments' => $departments,
            'machines' => $machines,
            'users' => $users,
            'punches' => $punches,
        ];
    }
}
