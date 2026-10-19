<?php

namespace Tests\Feature\Import;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZkaccessImportCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $file = '';

    private int $otr;

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::clear();
        $this->seed(\DatabaseSeeder::class);
        Tenancy::clear();

        $this->tenant = Tenant::where('slug', 'acme')->firstOrFail();
        Tenancy::set($this->tenant->id);

        Device::query()->where('tenant_id', $this->tenant->id)->delete();
        Department::query()->where('tenant_id', $this->tenant->id)->delete();
        $this->otr = Device::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'OTR #11',
            'ip_address' => '192.168.7.63',
            'port' => 4370,
            'protocol' => Device::PROTOCOL_BINARY,
            'serial_number' => 'BOCK192660478',
            'is_active' => true,
        ])->id;

        Employee::create([
            'tenant_id' => $this->tenant->id,
            'employee_code' => '50',
            'name' => 'Alice',
            'is_active' => true,
        ]);

        $this->file = tempnam(sys_get_temp_dir(), 'zkexport-');
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
        }
        parent::tearDown();
    }

    /** Writes a warning-free export so the command can finish with exit 0. */
    private function writeCleanExport(): void
    {
        $data = [
            'source' => 'command-test',
            'exported_at' => '2026-10-06 09:00:00',
            'departments' => [
                ['id' => '47', 'parent_id' => '0', 'name' => 'P7H', 'code' => '47'],
            ],
            'machines' => [
                ['id' => '1', 'name' => 'OTR', 'ip' => '192.168.7.63', 'serial' => 'BOCK192660478'],
            ],
            'users' => [
                ['user_id' => '1', 'pin' => '50', 'first_name' => 'Alice', 'last_name' => '', 'gender' => '', 'card' => '', 'hire_date' => '', 'birth_date' => '', 'department_id' => '', 'privilege' => '0', 'password' => '', 'email' => '', 'mobile_phone' => '', 'home_phone' => '', 'home_address' => '', 'street' => '', 'city' => '', 'country' => '', 'education' => '', 'political' => '', 'job_title' => '', 'emptype' => '0', 'status' => '0', 'deleted' => '0'],
            ],
            'punches' => [
                ['user_id' => '1', 'pin' => '50', 'serial' => 'BOCK192660478', 'at' => '2026-10-05 10:00:00', 'type' => 'I', 'verify' => '1'],
            ],
        ];

        file_put_contents($this->file, json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function test_dry_run_reports_but_writes_nothing(): void
    {
        $this->writeCleanExport();

        $this->artisan('zkaccess:import', ['file' => $this->file, '--tenant' => $this->tenant->id])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Departments: 0 existing, 1 to create')
            ->assertSuccessful();

        $this->assertSame(0, Department::count());
        $this->assertSame(0, AttendanceLog::count());
        $this->assertSame(1, Employee::where('employee_code', '50')->count());
    }

    public function test_apply_writes_departments_employees_and_attendance(): void
    {
        $this->writeCleanExport();

        $this->artisan('zkaccess:import', ['file' => $this->file, '--apply' => true, '--tenant' => $this->tenant->id])
            ->expectsOutputToContain('APPLY MODE')
            ->expectsOutputToContain('Employees: 1 by PIN, 0 by name, 0 created.')
            ->expectsOutputToContain('Attendance: 1 candidates, 0 already in the system, 1 inserted')
            ->assertSuccessful();

        $this->assertSame(1, Department::where('tenant_id', $this->tenant->id)->where('name', 'P7H')->count());
        $this->assertSame(1, AttendanceLog::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame('2026-10-05 07:00:00', AttendanceLog::first()->punched_at->format('Y-m-d H:i:s'));
    }

    public function test_skip_attendance_flag_leaves_logs_alone(): void
    {
        $this->writeCleanExport();

        $this->artisan('zkaccess:import', ['file' => $this->file, '--apply' => true, '--tenant' => $this->tenant->id, '--skip-attendance' => true])
            ->expectsOutputToContain('Attendance: skipped (--skip-attendance).')
            ->assertSuccessful();

        $this->assertSame(1, Department::count());
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_missing_file_returns_failure(): void
    {
        $this->artisan('zkaccess:import', ['file' => '/nonexistent/export.json'])
            ->expectsOutputToContain('File not found or not readable')
            ->assertFailed();
    }

    public function test_invalid_json_returns_failure(): void
    {
        file_put_contents($this->file, '{not json');

        $this->artisan('zkaccess:import', ['file' => $this->file])
            ->expectsOutputToContain('is not valid JSON')
            ->assertFailed();
    }

    public function test_export_missing_required_sections_returns_failure(): void
    {
        file_put_contents($this->file, json_encode(['source' => 'x'], JSON_THROW_ON_ERROR));

        $this->artisan('zkaccess:import', ['file' => $this->file])
            ->expectsOutputToContain('missing required sections')
            ->assertFailed();
    }
}
