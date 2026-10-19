<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Tenant;
use App\Support\Tenancy;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;

/**
 * The fortnight both report tests read (2026-10-18): Sunday 2 to Thursday 13 August
 * 2026, ten Sun–Thu work days, under a "Lab" department with a "Bench" branch.
 *
 * Punctual — every day, early in, late out.  Patchy — late on the 3rd, absent the
 * 4th and 5th, no punch-out on the 6th.  No Show — hired, never punched.  Never
 * Tracked — no hire date, never punched.  New Starter — hired the 9th, perfect since.
 */
trait AttendanceScenario
{
    private const FROM = '2026-08-02';

    private const TO = '2026-08-13';

    private const WORK_DAYS = ['2026-08-02', '2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06',
        '2026-08-09', '2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13'];

    private Tenant $tenant;

    private Device $device;

    private Department $lab;

    /** @var array<string, Employee> */
    private array $people = [];

    private function seedScenario(): void
    {

        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();

        $this->tenant = Tenant::query()->firstOrFail();
        Tenancy::set($this->tenant->id);

        $this->device = Device::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Main Gate', 'ip_address' => '192.0.2.1',
            'port' => 4370, 'is_active' => true,
        ]);

        // Every scenario person sits under "Lab" (or a branch of it), so the report is
        // scoped to them and the seeder's own employees stay out of the arithmetic.
        $this->lab = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Lab']);
        $bench = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Bench', 'parent_id' => $this->lab->id]);

        $make = fn (string $name, ?string $hired, Department $dept) => $this->people[$name] = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'employee_code' => 'L'.(count($this->people) + 1),
            'is_active' => true, 'hired_date' => $hired, 'department_id' => $dept->id,
        ]);

        $make('Punctual', '2020-01-01', $this->lab);
        $make('Patchy', '2020-01-01', $bench);
        $make('No Show', '2020-01-01', $this->lab);
        $make('Never Tracked', null, $this->lab);
        $make('New Starter', '2026-08-09', $bench);

        foreach (self::WORK_DAYS as $day) {
            $this->punch('Punctual', $day, '08:50');
            $this->punch('Punctual', $day, '18:05');
        }

        // Late on the 3rd, absent on the 4th and 5th, no punch-out on the 6th.
        foreach (self::WORK_DAYS as $day) {
            if (in_array($day, ['2026-08-04', '2026-08-05'], true)) {
                continue;
            }
            $this->punch('Patchy', $day, $day === '2026-08-03' ? '09:40' : '08:55');
            if ($day !== '2026-08-06') {
                $this->punch('Patchy', $day, '18:01');
            }
        }

        foreach (['2026-08-09', '2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13'] as $day) {
            $this->punch('New Starter', $day, '08:45');
            $this->punch('New Starter', $day, '18:10');
        }
    }

    private function punch(string $who, string $day, string $time): void
    {
        AttendanceLog::create([
            'tenant_id' => $this->tenant->id,
            'device_id' => $this->device->id,
            'device_user_id' => $this->people[$who]->employee_code,
            'employee_id' => $this->people[$who]->id,
            'punched_at' => Carbon::parse("{$day} {$time}", config('attendance.timezone'))->utc(),
            'verify_method' => 1,
            'status' => 0,
        ]);
    }

}
