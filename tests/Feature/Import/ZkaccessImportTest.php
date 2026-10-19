<?php

namespace Tests\Feature\Import;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\Import\ZkaccessImportService;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ZkaccessImportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Tenant $tenant2;

    private int $otr;

    private int $itRoom;

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::clear();
        $this->seed(\DatabaseSeeder::class);
        Tenancy::clear();

        $this->tenant = Tenant::where('slug', 'acme')->firstOrFail();
        $this->tenant2 = Tenant::create([
            'name' => 'Importer collateral tenant',
            'slug' => 'importer-'.Str::random(4).'-tenant',
            'timezone' => 'Asia/Bahrain',
            'is_active' => true,
        ]);

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

        $this->itRoom = Device::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'IT ROOM #15',
            'ip_address' => '192.168.7.62',
            'port' => 4370,
            'protocol' => Device::PROTOCOL_BINARY,
            'serial_number' => null, // registered by IP only, like production #15
            'is_active' => true,
        ])->id;

        // Known employees the export should resolve to.
        $this->employee('50', 'Alice', '');
        $this->employee('51', 'Bob', 'Smith');
        $this->employee('55', 'Carol', 'Green');
        $this->employee('60', 'David', 'Brown');
        $this->employee('61', 'David', 'Brown');
    }

    private function employee(string $code, string $firstName, string $lastName, array $extra = []): Employee
    {
        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'employee_code' => $code,
            'name' => $firstName,
            'last_name' => $lastName,
            'is_active' => true,
        ], $extra));
    }

    /** @return array<string,mixed> */
    private function export(array $overrides = []): array
    {
        $data = [
            'source' => __FILE__,
            'exported_at' => '2026-10-06 09:00:00',
            'departments' => [
                ['id' => '47', 'parent_id' => '0', 'name' => 'P7H', 'code' => '47'],
            ],
            'machines' => [
                ['id' => '1', 'name' => 'OTR', 'ip' => '192.168.7.63', 'serial' => 'BOCK192660478'],
                ['id' => '2', 'name' => 'IT ROOM', 'ip' => '192.168.7.62', 'serial' => 'AIOR204860846'],
            ],
            'users' => [
                ['user_id' => '1', 'pin' => '50', 'first_name' => 'Alice', 'last_name' => '', 'gender' => 'F', 'card' => '5050', 'hire_date' => '2023-01-15', 'birth_date' => '1990-05-05', 'department_id' => '47', 'privilege' => '0', 'password' => '', 'email' => '', 'mobile_phone' => '', 'home_phone' => '', 'home_address' => '', 'street' => '', 'city' => '', 'country' => '', 'education' => '', 'political' => '', 'job_title' => '', 'emptype' => '0', 'status' => '0', 'deleted' => '0'],
                ['user_id' => '2', 'pin' => '51', 'first_name' => 'Bob', 'last_name' => 'Smith', 'gender' => 'M', 'card' => '', 'hire_date' => '2021-02-01', 'birth_date' => '', 'department_id' => '47', 'privilege' => '0', 'password' => '', 'email' => '', 'mobile_phone' => '0791111111', 'home_phone' => '', 'home_address' => '', 'street' => '', 'city' => '', 'country' => '', 'education' => '', 'political' => '', 'job_title' => '', 'emptype' => '0', 'status' => '0', 'deleted' => '0'],
                ['user_id' => '3', 'pin' => '300', 'first_name' => 'Carol', 'last_name' => 'Green', 'gender' => 'F', 'card' => '', 'hire_date' => '', 'birth_date' => '', 'department_id' => '47', 'privilege' => '0', 'password' => '', 'email' => '', 'mobile_phone' => '', 'home_phone' => '', 'home_address' => '', 'street' => '', 'city' => '', 'country' => '', 'education' => '', 'political' => '', 'job_title' => '', 'emptype' => '0', 'status' => '0', 'deleted' => '0'],
                ['user_id' => '4', 'pin' => '400', 'first_name' => 'John', 'last_name' => 'Doe', 'gender' => '', 'card' => '4040', 'hire_date' => '', 'birth_date' => '', 'department_id' => '', 'privilege' => '0', 'password' => '', 'email' => '', 'mobile_phone' => '', 'home_phone' => '', 'home_address' => '1 Main St', 'street' => '', 'city' => '', 'country' => '', 'education' => '', 'political' => '', 'job_title' => '', 'emptype' => '0', 'status' => '0', 'deleted' => '0'],
                ['user_id' => '5', 'pin' => '600', 'first_name' => 'David', 'last_name' => 'Brown', 'gender' => 'M', 'card' => '', 'hire_date' => '', 'birth_date' => '', 'department_id' => '47', 'privilege' => '0', 'password' => '', 'email' => '', 'mobile_phone' => '', 'home_phone' => '', 'home_address' => '', 'street' => '', 'city' => '', 'country' => '', 'education' => '', 'political' => '', 'job_title' => '', 'emptype' => '0', 'status' => '0', 'deleted' => '0'],
                ['user_id' => '6', 'pin' => '700', 'first_name' => 'Zed', 'last_name' => '', 'gender' => '', 'card' => '', 'hire_date' => '', 'birth_date' => '', 'department_id' => '', 'privilege' => '0', 'password' => '', 'email' => '', 'mobile_phone' => '', 'home_phone' => '', 'home_address' => '', 'street' => '', 'city' => '', 'country' => '', 'education' => '', 'political' => '', 'job_title' => '', 'emptype' => '0', 'status' => '0', 'deleted' => '1'],
                ['user_id' => '7', 'pin' => '', 'first_name' => 'NoPin', 'last_name' => '', 'gender' => '', 'card' => '', 'hire_date' => '', 'birth_date' => '', 'department_id' => '', 'privilege' => '0', 'password' => '', 'email' => '', 'mobile_phone' => '', 'home_phone' => '', 'home_address' => '', 'street' => '', 'city' => '', 'country' => '', 'education' => '', 'political' => '', 'job_title' => '', 'emptype' => '0', 'status' => '0', 'deleted' => '0'],
            ],
            'punches' => [
                // duplicate of a live row already in the system (10:00 local = 07:00 UTC)
                ['user_id' => '1', 'pin' => '50', 'serial' => 'BOCK192660478', 'at' => '2026-09-27 10:00:00', 'type' => 'I', 'verify' => '1'],
                // new check-out, status must come out as 1
                ['user_id' => '1', 'pin' => '50', 'serial' => 'BOCK192660478', 'at' => '2026-10-05 10:00:00', 'type' => 'O', 'verify' => '1'],
                // same instant as the duplicate but a different verify_method: new row
                ['user_id' => '1', 'pin' => '50', 'serial' => 'BOCK192660478', 'at' => '2026-09-27 10:00:00', 'type' => 'I', 'verify' => '4'],
                // serial absent from devices, resolves through the export's ip -> IT ROOM
                ['user_id' => '1', 'pin' => '50', 'serial' => 'AIOR204860846', 'at' => '2026-09-27 11:00:00', 'type' => 'I', 'verify' => '0'],
                // exact duplicate of the second punch: skipped by the in-export guard
                ['user_id' => '1', 'pin' => '50', 'serial' => 'BOCK192660478', 'at' => '2026-10-05 10:00:00', 'type' => 'O', 'verify' => '1'],
                // unknown user: orphan
                ['user_id' => '999', 'pin' => '999', 'serial' => 'BOCK192660478', 'at' => '2026-09-27 10:00:00', 'type' => 'I', 'verify' => '1'],
            ],
        ];

        return array_replace_recursive($data, $overrides);
    }

    private function service(array $data, bool $apply = false, bool $createNew = true, bool $attendance = true): ZkaccessImportService
    {
        return (new ZkaccessImportService)
            ->setData($data)
            ->setTenant((int) $this->tenant->id)
            ->setApply($apply)
            ->setCreateNew($createNew)
            ->setImportAttendance($attendance);
    }

    public function test_dry_run_writes_nothing_but_counts_everything(): void
    {
        $beforeDepts = Department::count();
        $beforeEmployees = Employee::count();
        $beforePunches = AttendanceLog::count();

        $report = $this->service($this->export())->run();

        $this->assertSame($beforeDepts, Department::count());
        $this->assertSame($beforeEmployees, Employee::count());
        $this->assertSame($beforePunches, AttendanceLog::count());

        $this->assertSame(1, $report['departments']['created']);
        $this->assertSame(2, $report['employees']['matched_by_pin']);
        $this->assertSame(1, $report['employees']['matched_by_name']);
        $this->assertSame(1, $report['employees']['created']);
        $this->assertSame(1, $report['employees']['ambiguous']);
        $this->assertSame(1, $report['employees']['skipped_deleted']);
        $this->assertSame(1, $report['employees']['skipped_no_pin']);
        $this->assertSame(5, $report['attendance']['candidates']);
        // No attendance exists in the DB in this test, so only the in-export
        // duplicate (identical instant/verify) is caught.
        $this->assertSame(1, $report['attendance']['duplicates']);
        $this->assertSame(4, $report['attendance']['inserted']);
        $this->assertSame(1, $report['attendance']['orphans']);
    }

    public function test_imports_departments_and_is_idempotent(): void
    {
        $this->service($this->export(), apply: true)->run();
        $this->service($this->export(), apply: true)->run();

        $departments = Department::where('tenant_id', $this->tenant->id)->get();
        $this->assertSame(1, $departments->count());
        $this->assertSame('P7H', $departments->first()->name);
        $this->assertSame('47', $departments->first()->code);
    }

    public function test_fills_empty_profile_fields_and_never_overwrites(): void
    {
        $alice = Employee::where('employee_code', '50')->first();
        $alice->update(['birthday' => '1990-01-01', 'gender' => null, 'card_number' => null, 'department_id' => null]);

        $report = $this->service($this->export(), apply: true)->run();

        $alice->refresh();
        $this->assertSame('F', $alice->gender);
        $this->assertSame(5050, $alice->card_number);
        $this->assertSame('1990-01-01', $alice->birthday->format('Y-m-d')); // untouched
        $this->assertSame('2023-01-15', $alice->hired_date->format('Y-m-d'));
        $this->assertNotNull($alice->department_id);
        $this->assertSame('P7H', $alice->department);

        $bob = Employee::where('employee_code', '51')->first();
        $bob->refresh();
        $this->assertSame('0791111111', $bob->mobile_phone);

        $this->assertSame('1 Main St', Employee::where('employee_code', '400')->first()->home_address);
        $this->assertSame(4, $report['employees']['profiles_filled']);
        // Alice 5 (gender, card, hired_date, dept_id, dept) + Bob 5 (gender,
        // mobile_phone, hired_date, dept_id, dept) + Carol 3 (gender, dept_id,
        // dept) + John 2 (card, home_address).
        $this->assertSame(15, $report['employees']['fields_filled']);
    }

    public function test_matches_unmatched_user_by_unique_name(): void
    {
        $report = $this->service($this->export(), apply: true)->run();

        $this->assertSame(1, $report['employees']['matched_by_name']);
        $carol = Employee::where('employee_code', '55')->first();
        $this->assertSame('F', $carol->gender);
    }

    public function test_creates_unmatched_employees_under_the_tenant(): void
    {
        $this->service($this->export(), apply: true)->run();

        $john = Employee::where('employee_code', '400')->first();
        $this->assertNotNull($john);
        $this->assertSame('John Doe', $john->name);
        $this->assertNull($john->last_name);
        $this->assertTrue($john->is_active);
        $this->assertSame((int) $this->tenant->id, (int) $john->tenant_id);
    }

    public function test_ambiguous_names_are_neither_merged_nor_created(): void
    {
        $report = $this->service($this->export(), apply: true)->run();

        $this->assertSame(1, $report['employees']['ambiguous']);
        $this->assertNull(Employee::where('employee_code', '600')->first());
        $this->assertSame(2, Employee::where('last_name', 'Brown')->count()); // both originals only
        $this->assertStringContainsString('shared by two existing employees', $report['warnings'][0]['reason']);
    }

    public function test_shared_card_keeps_the_first_holder_and_warns(): void
    {
        $users = $this->export()['users'];
        $users[1]['card'] = '5050'; // Bob now carries Alice's card

        $report = $this->service($this->export(['users' => $users]), apply: true)->run();

        $this->assertSame(5050, Employee::where('employee_code', '50')->first()->card_number);
        $this->assertNull(Employee::where('employee_code', '51')->first()->card_number);
        $this->assertSame(1, $report['employees']['cards_skipped']);
    }

    public function test_attendance_links_by_userid_records_pin_as_utc(): void
    {
        AttendanceLog::create([
            'tenant_id' => $this->tenant->id,
            'device_id' => $this->otr,
            'device_user_id' => '50',
            'employee_id' => Employee::where('employee_code', '50')->first()->id,
            'punched_at' => Carbon::parse('2026-09-27 07:00:00', 'UTC'),
            'status' => 0,
            'verify_method' => 1,
        ]);

        $report = $this->service($this->export(), apply: true)->run();

        $this->assertSame(5, $report['attendance']['candidates']);
        $this->assertSame(2, $report['attendance']['duplicates']);
        $this->assertSame(3, $report['attendance']['inserted']);
        $this->assertSame(1, $report['attendance']['orphans']);

        $alice = Employee::where('employee_code', '50')->first();
        $rows = AttendanceLog::where('employee_id', $alice->id)->where('device_id', $this->otr)->get();

        $checkOut = $rows->first(fn ($r) => $r->punched_at->format('Y-m-d H:i:s') === '2026-10-05 07:00:00');
        $this->assertNotNull($checkOut, 'expected the imported check-out punch');
        $this->assertSame(1, $checkOut->status);
        $this->assertSame('50', $checkOut->device_user_id);

        $verifyFour = $rows->firstWhere('verify_method', 4);
        $this->assertNotNull($verifyFour);
        $this->assertSame(0, $verifyFour->status);
    }

    public function test_device_is_resolved_by_ip_when_serial_is_missing(): void
    {
        $report = $this->service($this->export(), apply: true)->run();

        $this->assertSame(0, $report['attendance']['unresolvable_device']);

        $ipFallback = AttendanceLog::where('device_id', $this->itRoom)->first();
        $this->assertNotNull($ipFallback);
        $this->assertSame('2026-09-27 08:00:00', $ipFallback->punched_at->format('Y-m-d H:i:s'));
    }

    public function test_no_create_new_leaves_unmatched_users_uncreated(): void
    {
        $report = $this->service($this->export(), apply: true, createNew: false)->run();

        $this->assertSame(1, $report['employees']['unmatched']);
        $this->assertNull(Employee::where('employee_code', '400')->first());
    }

    public function test_pin_colliding_with_another_tenant_creates_nothing(): void
    {
        Tenancy::clear();
        $tenant2Employee = Employee::create([
            'tenant_id' => $this->tenant2->id,
            'employee_code' => '400',
            'name' => 'OtherTenant',
            'is_active' => true,
        ]);
        Tenancy::set($this->tenant->id);

        $report = $this->service($this->export(), apply: true)->run();

        $this->assertSame(1, $report['employees']['skipped_code_collision']);
        $this->assertNull(Employee::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('employee_code', '400')
            ->first());
        $this->assertStringContainsString('another tenant', $report['warnings'][0]['reason']);
    }

    public function test_attendance_can_be_skipped(): void
    {
        $report = $this->service($this->export(), apply: true, attendance: false)->run();

        $this->assertTrue($report['attendance']['skipped']);
        $this->assertSame(0, AttendanceLog::count());
    }
}
