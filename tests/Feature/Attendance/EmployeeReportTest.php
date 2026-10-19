<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Attendance\EmployeeReportService;
use App\Support\Tenancy;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The employee report (2026-09-27). Its per-day verdicts are the attendance
 * summary's; what is pinned here is what the report adds on top — one outcome per
 * day, the period statistics, the terminal of the first punch, the three
 * enrollment states — and that an empty period says null rather than 0.
 *
 * The window: 30 days ending Thu 2026-09-24. Shift 09:00–18:00, late after 09:15.
 */
class EmployeeReportTest extends TestCase
{
    use RefreshDatabase;

    private const TO = '2026-09-24';

    private Tenant $tenant;

    private Employee $employee;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        Tenancy::set($this->tenant->id);

        $this->device = Device::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Entrance', 'ip_address' => '192.0.2.1',
            'port' => 4370, 'is_active' => true,
        ]);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Ali Jaafar', 'employee_code' => '101', 'is_active' => true,
        ]);
    }

    public function test_each_work_day_gets_one_outcome_and_the_period_is_counted(): void
    {
        $this->day('2026-09-20', '08:50', '18:05');           // on time
        $this->day('2026-09-21', '09:40', '18:10');           // late (after 09:15)
        $this->day('2026-09-22', '08:55', '16:00');           // left before the shift end
        $this->day('2026-09-23', '08:58');                    // no punch-out
        Holiday::create(['tenant_id' => $this->tenant->id, 'date' => '2026-09-24', 'name' => 'Test Day']);

        $r = app(EmployeeReportService::class)->report($this->employee, 30, self::TO);

        $byDate = collect($r['days'])->keyBy('date');
        $this->assertSame('on_time', $byDate['2026-09-20']['outcome']);
        $this->assertSame('late', $byDate['2026-09-21']['outcome']);
        $this->assertSame('left_early', $byDate['2026-09-22']['outcome']);
        $this->assertSame('missed_punch_out', $byDate['2026-09-23']['outcome']);
        $this->assertSame('excused', $byDate['2026-09-24']['outcome']);
        $this->assertSame('Test Day', $byDate['2026-09-24']['excused_reason']);

        // Minutes after local midnight, and no departure on the unclosed day.
        $this->assertSame(9 * 60 + 40, $byDate['2026-09-21']['first_minutes']);
        $this->assertNull($byDate['2026-09-23']['last_minutes']);
        $this->assertNull($byDate['2026-09-23']['hours']);
        $this->assertSame('Entrance', $byDate['2026-09-21']['device']);

        $s = $r['stats'];
        $this->assertSame(4, $s['present_days']);
        $this->assertSame(1, $s['late_arrivals']);
        $this->assertSame(1, $s['missed_punch_outs']);
        $this->assertSame(1, $s['outcomes']['excused']);
        $this->assertSame(0.75, $s['on_time_rate']);
        // 40 minutes after 09:00 — the grace decides "late", not how late.
        $this->assertSame(40.0, $s['average_minutes_late']);
        $this->assertSame(7, $r['punches']['total']);
        $this->assertSame([['device' => 'Entrance', 'count' => 7]], $r['punches']['by_device']);
    }

    public function test_days_before_anyone_tracked_them_are_not_absences(): void
    {
        // Never punched, no hire date: nothing to report, and certainly not a month
        // of absence. Every statistic is null rather than 0.
        $r = app(EmployeeReportService::class)->report($this->employee, 30, self::TO);
        $this->assertNull($r['tracking_from']);
        $this->assertSame(['untracked'], array_values(array_unique(array_column($r['days'], 'outcome'))));
        $this->assertNull($r['stats']['attendance_rate']);
        $this->assertNull($r['stats']['on_time_rate']);
        $this->assertNull($r['stats']['average_arrival_minutes']);

        // Hired on the 21st: the work days before it drop out, the ones after count.
        $this->employee->forceFill(['hired_date' => '2026-09-21'])->save();
        $s = app(EmployeeReportService::class)->report($this->employee->fresh(), 30, self::TO)['stats'];
        $this->assertSame(4, $s['work_days']);          // Mon 21 – Thu 24
        $this->assertSame(4, $s['outcomes']['absent']);
        $this->assertSame(0.0, (float) $s['attendance_rate']);
        $this->assertNull($s['on_time_rate']);
    }

    public function test_enrollment_keeps_never_read_unknown_and_a_count_apart(): void
    {
        $other = Device::create(['tenant_id' => $this->tenant->id, 'name' => 'Glass Door', 'ip_address' => '192.0.2.2', 'port' => 4370, 'is_active' => true]);
        $third = Device::create(['tenant_id' => $this->tenant->id, 'name' => 'IT Room', 'ip_address' => '192.0.2.3', 'port' => 4370, 'is_active' => true]);

        $this->employee->deviceMaps()->create(['device_id' => $this->device->id, 'device_user_id' => '1', 'fingerprint_slots' => [0, 6], 'synced_at' => now()]);
        $this->employee->deviceMaps()->create(['device_id' => $other->id, 'device_user_id' => '1', 'fingerprint_slots' => null, 'synced_at' => now()]);
        $this->employee->deviceMaps()->create(['device_id' => $third->id, 'device_user_id' => '1', 'fingerprint_slots' => null, 'synced_at' => null]);

        $states = collect(app(EmployeeReportService::class)->report($this->employee, 30, self::TO)['enrollment'])->keyBy('device');

        $this->assertSame(['read', 2], [$states['Entrance']['state'], $states['Entrance']['fingerprints']]);
        $this->assertSame(['unknown', null], [$states['Glass Door']['state'], $states['Glass Door']['fingerprints']]);
        $this->assertSame('never_read', $states['IT Room']['state']);
    }

    public function test_the_endpoint_validates_the_period_and_answers(): void
    {
        $token = $this->adminToken();

        $this->getJson("/api/employees/{$this->employee->id}/report?period=45", ['Authorization' => "Bearer {$token}"])
            ->assertStatus(422);

        $this->getJson("/api/employees/{$this->employee->id}/report?period=90", ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.period.days', 90)
            ->assertJsonPath('data.employee.name', 'Ali Jaafar');
    }

    private function day(string $date, string $in, ?string $out = null): void
    {
        foreach (array_filter([$in, $out]) as $time) {
            AttendanceLog::create([
                'tenant_id' => $this->tenant->id,
                'device_id' => $this->device->id,
                'device_user_id' => '1',
                'employee_id' => $this->employee->id,
                'punched_at' => Carbon::parse("{$date} {$time}", config('attendance.timezone'))->utc(),
                'verify_method' => 1,
                'status' => 0,
            ]);
        }
    }

    private function adminToken(): string
    {
        Tenancy::clear();
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($this->tenant);
        Tenancy::set($this->tenant->id);
        $user = User::withoutGlobalScopes()->create([
            'name' => 'Admin', 'email' => 'admin@acme.test', 'password' => Hash::make('pw-123456'),
            'tenant_id' => $this->tenant->id, 'is_active' => true, 'email_verified_at' => now(),
        ]);
        $user->syncRoles(['tenant-admin']);
        Tenancy::clear();

        $token = $this->postJson('/api/auth/login', ['email' => 'admin@acme.test', 'password' => 'pw-123456'])->json('data.token');
        $this->app['auth']->forgetGuards();

        return $token;
    }
}
