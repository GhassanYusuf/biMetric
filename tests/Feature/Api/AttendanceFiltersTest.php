<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Attendance page's filters (2026-10-11): the Company › Department tree, the
 * search box, and the full name on each punch. All three live in PunchLogQuery, so
 * the PDF export applies exactly what the screen does.
 */
class AttendanceFiltersTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'test-password';

    private Department $company;

    private Department $finance;

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();

        $acme = Tenant::where('slug', 'acme')->firstOrFail();
        Tenancy::set($acme->id);

        User::create([
            'name' => 'Admin',
            'email' => 'punches@acme.test',
            'password' => Hash::make(self::PASSWORD),
            'tenant_id' => $acme->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ])->syncRoles(['tenant-admin']);

        $device = Device::create([
            'name' => 'IT Room', 'ip_address' => '192.0.2.21', 'port' => 4370,
            'protocol' => Device::PROTOCOL_BINARY, 'is_active' => true,
        ]);

        $this->company = Department::create(['name' => 'Punch Co']);
        $this->finance = Department::create(['name' => 'Finance', 'parent_id' => $this->company->id]);

        $people = [
            ['P-1', 'Zaynab', 'Qudsi Alwardi', $this->finance->id],
            ['P-2', 'Omar', null, $this->company->id],
            ['P-3', 'Hadi', 'Nowhere', null],
        ];
        foreach ($people as $i => [$code, $first, $last, $dept]) {
            $e = Employee::create(['name' => $first, 'last_name' => $last, 'employee_code' => $code, 'department_id' => $dept, 'is_active' => true]);
            AttendanceLog::create([
                'device_id' => $device->id, 'device_user_id' => $code, 'employee_id' => $e->id,
                'punched_at' => now()->subMinutes($i + 1), 'verify_method' => 1, 'status' => 0,
            ]);
        }
        // A punch nobody is linked to, findable only by the terminal PIN.
        AttendanceLog::create([
            'device_id' => $device->id, 'device_user_id' => '77123', 'employee_id' => null,
            'punched_at' => now()->subMinutes(9), 'verify_method' => 4, 'status' => 0,
        ]);

        Tenancy::clear();
    }

    /** @return list<string> the device_user_id of each listed punch from this test */
    private function codes(string $query): array
    {
        $token = $this->postJson('/api/auth/login', ['email' => 'punches@acme.test', 'password' => self::PASSWORD])->json('data.token');
        $this->app['auth']->forgetGuards();

        $rows = $this->withToken($token)->getJson('/api/attendance?per_page=200&'.$query)->assertOk()->json('data');

        return collect($rows)->pluck('employee_code')
            ->filter(fn ($c) => str_starts_with((string) $c, 'P-') || $c === '77123')
            ->sort()->values()->all();
    }

    public function test_a_company_includes_its_departments_punches(): void
    {
        $this->assertSame(['P-1', 'P-2'], $this->codes('department_id='.$this->company->id));
        $this->assertSame(['P-1'], $this->codes('department_id='.$this->finance->id));
    }

    public function test_unassigned_means_an_employee_with_no_department_not_an_unmatched_punch(): void
    {
        $this->assertSame(['P-3'], $this->codes('department_id=none'));
    }

    public function test_search_matches_the_full_name_as_displayed(): void
    {
        $this->assertSame(['P-1'], $this->codes('search='.urlencode('zaynab alwardi')));
        $this->assertSame(['P-3'], $this->codes('search=nowhere'));
    }

    public function test_search_finds_an_unmatched_punch_by_its_pin(): void
    {
        $this->assertSame(['77123'], $this->codes('search=77123'));
    }

    public function test_each_punch_carries_the_full_name(): void
    {
        $token = $this->postJson('/api/auth/login', ['email' => 'punches@acme.test', 'password' => self::PASSWORD])->json('data.token');
        $this->app['auth']->forgetGuards();

        $row = collect($this->withToken($token)->getJson('/api/attendance?per_page=200')->json('data'))->firstWhere('employee_code', 'P-1');

        $this->assertSame('Zaynab Qudsi Alwardi', $row['employee_name']);
    }

    public function test_a_malformed_department_is_refused(): void
    {
        $token = $this->postJson('/api/auth/login', ['email' => 'punches@acme.test', 'password' => self::PASSWORD])->json('data.token');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/attendance?department_id=drop')->assertStatus(422);
    }
}
