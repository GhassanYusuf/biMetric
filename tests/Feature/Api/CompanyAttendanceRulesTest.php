<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\CompanyAttendanceRule;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Attendance\AttendanceSummaryService;
use App\Services\Attendance\SummaryBatch;
use App\Support\CompanyAccess;
use App\Support\Tenancy;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Shift and holidays per company (owner, 2026-11-22): a company with its own rules is judged by them, the
 * rest by the default; a holiday is for every company or for the ticked ones.
 */
class CompanyAttendanceRulesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Company $gems;

    private Company $avana;

    private Employee $inGems;

    private Employee $inAvana;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();
        CompanyAccess::forget();
        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($this->tenant);
        Tenancy::set($this->tenant->id);

        $this->admin = $this->user('admin@acme.test');
        $this->gems = Company::create(['name' => 'GEMS']);
        $this->avana = Company::create(['name' => 'AVANA']);
        $this->inGems = Employee::create(['name' => 'Gemma', 'employee_code' => 'G1', 'company_id' => $this->gems->id, 'is_active' => true]);
        $this->inAvana = Employee::create(['name' => 'Ava', 'employee_code' => 'A1', 'company_id' => $this->avana->id, 'is_active' => true]);

        // Both punch 07:30 and 15:00 office time on Monday 2026-08-03.
        $device = Device::create(['name' => 'Gate', 'ip_address' => '192.0.2.1', 'port' => 4370, 'is_active' => true]);
        $tz = config('attendance.timezone');
        foreach ([$this->inGems, $this->inAvana] as $e) {
            foreach (['07:30', '15:00'] as $t) {
                AttendanceLog::create(['device_id' => $device->id, 'device_user_id' => $e->employee_code, 'employee_id' => $e->id,
                    'punched_at' => Carbon::parse("2026-08-03 {$t}", $tz)->utc(), 'verify_method' => 1, 'status' => 0]);
            }
        }
        Tenancy::clear();
    }

    protected function tearDown(): void
    {
        CompanyAccess::forget(); // a limited user's companies are cached statically; never leak them to the next test
        parent::tearDown();
    }

    private function user(string $email): User
    {
        Tenancy::set($this->tenant->id);
        $u = User::withoutGlobalScopes()->create(['name' => $email, 'email' => $email, 'password' => 'secret-pass-1',
            'tenant_id' => $this->tenant->id, 'is_active' => true, 'email_verified_at' => now()]);
        $u->syncRoles(['tenant-admin']);

        return $u;
    }

    private function as(User $u)
    {
        CompanyAccess::forget();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    private function ownRules(array $overrides = []): array
    {
        return [
            'shift_start' => '07:00', 'shift_end' => '15:00', 'weekend_days' => [5, 6],
            'late_grace_minutes' => 0, 'early_leave_grace_minutes' => 0, 'leave_late_after_minutes' => 0, ...$overrides,
        ];
    }

    /** @return array<string, array<string,mixed>> the day's summary row per employee name */
    private function monday(string $day = '2026-08-03'): array
    {
        Tenancy::set($this->tenant->id);
        $people = Employee::query()->whereIn('id', [$this->inGems->id, $this->inAvana->id])->get();
        $tz = config('attendance.timezone');
        $batch = SummaryBatch::for($people, Carbon::parse($day, $tz)->startOfDay(), Carbon::parse($day, $tz)->endOfDay());
        $out = [];
        foreach ($people as $e) {
            $daily = app(AttendanceSummaryService::class)->summary($e, $day, $day, $batch)['daily'];
            $out[$e->name] = $daily[0] ?? null;
        }
        Tenancy::clear();

        return $out;
    }

    public function test_a_company_with_its_own_shift_is_judged_by_it_and_the_rest_by_the_default(): void
    {
        $this->as($this->admin)->putJson("/api/holidays/rules/companies/{$this->gems->id}", $this->ownRules())
            ->assertOk()
            ->assertJsonPath('data.companies.1.name', 'GEMS')
            ->assertJsonPath('data.companies.1.rules.shift_start', '07:00')
            ->assertJsonPath('data.companies.0.rules', null);

        $day = $this->monday();
        $this->assertSame('come_late', $day['Gemma']['arrival'], '07:30 is late against GEMS\'s 07:00');
        $this->assertSame('early', $day['Ava']['arrival'], '07:30 is early against the default 09:00');
        $this->assertSame(8.0, $day['Gemma']['payroll_hours'] + 0.5, 'payroll window is GEMS\'s 07:00–15:00');
    }

    public function test_a_company_weekend_removes_the_day_for_that_company_only(): void
    {
        $this->as($this->admin)->putJson("/api/holidays/rules/companies/{$this->gems->id}", $this->ownRules(['weekend_days' => [1]]))->assertOk();

        $day = $this->monday();
        $this->assertNull($day['Gemma'], 'Monday is GEMS\'s weekend');
        $this->assertNotNull($day['Ava']);
    }

    public function test_resetting_puts_the_company_back_on_the_default(): void
    {
        $this->as($this->admin)->putJson("/api/holidays/rules/companies/{$this->gems->id}", $this->ownRules())->assertOk();
        $this->as($this->admin)->deleteJson("/api/holidays/rules/companies/{$this->gems->id}")->assertOk()->assertJsonPath('data.companies.1.rules', null);

        $this->assertSame(0, CompanyAttendanceRule::withoutGlobalScopes()->count());
        $this->assertSame('early', $this->monday()['Gemma']['arrival']);
    }

    public function test_company_rules_are_checked_like_the_default(): void
    {
        $this->as($this->admin)->putJson("/api/holidays/rules/companies/{$this->gems->id}", ['shift_start' => '07:00'])
            ->assertStatus(422)->assertJsonValidationErrors(['shift_end', 'late_grace_minutes']);
    }

    public function test_a_holiday_for_chosen_companies_excuses_only_their_people(): void
    {
        $this->as($this->admin)->postJson('/api/holidays', ['date' => '2026-08-04', 'name' => 'GEMS Day', 'company_ids' => [$this->gems->id]])
            ->assertCreated()->assertJsonPath('data.companies.0.name', 'GEMS');

        $day = $this->monday('2026-08-04');
        $this->assertSame('excused', $day['Gemma']['status']);
        $this->assertSame('GEMS Day', $day['Gemma']['excused_reason']);
        $this->assertSame('absent', $day['Ava']['status']);
    }

    public function test_a_holiday_with_no_companies_is_for_everyone(): void
    {
        $this->as($this->admin)->postJson('/api/holidays', ['date' => '2026-08-04', 'name' => 'National Day'])
            ->assertCreated()->assertJsonPath('data.companies', []);

        $day = $this->monday('2026-08-04');
        $this->assertSame('excused', $day['Gemma']['status']);
        $this->assertSame('excused', $day['Ava']['status']);
    }

    public function test_one_day_never_gives_a_company_two_holidays(): void
    {
        $this->as($this->admin)->postJson('/api/holidays', ['date' => '2026-08-04', 'name' => 'GEMS Day', 'company_ids' => [$this->gems->id]])->assertCreated();
        // A different company on the same day is fine.
        $this->as($this->admin)->postJson('/api/holidays', ['date' => '2026-08-04', 'name' => 'AVANA Day', 'company_ids' => [$this->avana->id]])->assertCreated();
        // Every company, or GEMS again, is not.
        $this->as($this->admin)->postJson('/api/holidays', ['date' => '2026-08-04', 'name' => 'National Day'])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->as($this->admin)->postJson('/api/holidays', ['date' => '2026-08-04', 'name' => 'Again', 'company_ids' => [$this->gems->id, $this->avana->id]])->assertStatus(422);

        // Editing one keeps it from clashing with itself.
        $id = Holiday::withoutGlobalScopes()->where('name', 'GEMS Day')->value('id');
        $this->as($this->admin)->putJson("/api/holidays/{$id}", ['date' => '2026-08-04', 'name' => 'GEMS Founders Day', 'company_ids' => [$this->gems->id]])->assertOk();
    }

    public function test_a_user_limited_to_one_company_sets_its_holidays_and_rules_only(): void
    {
        $hr = $this->user('hr@acme.test');
        DB::table('user_companies')->insert(['user_id' => $hr->id, 'company_id' => $this->gems->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->as($hr)->postJson('/api/holidays', ['date' => '2026-08-04', 'name' => 'Everyone'])->assertStatus(422)->assertJsonValidationErrors('company_ids');
        $this->as($hr)->postJson('/api/holidays', ['date' => '2026-08-04', 'name' => 'AVANA', 'company_ids' => [$this->avana->id]])->assertStatus(422);
        $this->as($hr)->postJson('/api/holidays', ['date' => '2026-08-04', 'name' => 'GEMS Day', 'company_ids' => [$this->gems->id]])->assertCreated();

        $this->as($hr)->putJson('/api/holidays/rules', ['late_grace_minutes' => 5])->assertForbidden();
        $this->as($hr)->putJson("/api/holidays/rules/companies/{$this->avana->id}", $this->ownRules())->assertNotFound(); // hidden from them altogether
        $this->as($hr)->putJson("/api/holidays/rules/companies/{$this->gems->id}", $this->ownRules())->assertOk()
            ->assertJsonPath('data.may_edit_default', false)
            ->assertJsonCount(1, 'data.companies');

        // An every-company holiday is visible to them but not theirs to change.
        $this->as($this->admin)->postJson('/api/holidays', ['date' => '2026-08-05', 'name' => 'National Day'])->assertCreated();
        $national = Holiday::withoutGlobalScopes()->where('name', 'National Day')->value('id');
        $this->as($hr)->getJson('/api/holidays')->assertOk()->assertJsonCount(2, 'data');
        $this->as($hr)->deleteJson("/api/holidays/{$national}")->assertForbidden();
    }

    public function test_an_overnight_shift_closes_the_day_it_began(): void
    {
        // Camelot (2026-11-22): 17:00 to 02:00 the next morning. Its work day runs 09:30 to 09:30.
        $this->as($this->admin)->putJson("/api/holidays/rules/companies/{$this->gems->id}", $this->ownRules([
            'shift_start' => '17:00', 'shift_end' => '02:00', 'weekend_days' => [], 'early_leave_grace_minutes' => 0,
        ]))->assertOk()->assertJsonPath('data.companies.1.rules.shift_end', '02:00');

        Tenancy::set($this->tenant->id);
        AttendanceLog::query()->where('employee_id', $this->inGems->id)->delete();
        $device = Device::query()->firstOrFail();
        $tz = config('attendance.timezone');
        foreach (['2026-08-03 17:10', '2026-08-04 02:05', '2026-08-04 16:55', '2026-08-05 01:30'] as $at) {
            AttendanceLog::create(['device_id' => $device->id, 'device_user_id' => 'G1', 'employee_id' => $this->inGems->id,
                'punched_at' => Carbon::parse($at, $tz)->utc(), 'verify_method' => 1, 'status' => 0]);
        }
        $gemma = Employee::query()->findOrFail($this->inGems->id);
        $from = Carbon::parse('2026-08-03', $tz)->startOfDay();
        $to = Carbon::parse('2026-08-04', $tz)->endOfDay();
        $summary = app(AttendanceSummaryService::class)->summary($gemma, '2026-08-03', '2026-08-04', SummaryBatch::for([$gemma], $from, $to));
        Tenancy::clear();

        [$mon, $tue] = $summary['daily'];
        $this->assertSame('2026-08-03', $mon['date']);
        $this->assertSame('present', $mon['status']);
        $this->assertSame('come_late', $mon['arrival'], '17:10 is after 17:00 with no grace');
        $this->assertSame(17 * 60 + 10, $mon['first_minutes']);
        $this->assertSame(1440 + 2 * 60 + 5, $mon['last_minutes'], '02:05 the next morning closes Monday');
        $this->assertSame('leave_late', $mon['departure']);
        $this->assertSame(8.8, $mon['payroll_hours'], '17:10 to 02:00');

        $this->assertSame('early', $tue['arrival'], '16:55 is early for Tuesday\'s 17:00');
        $this->assertSame('leave_on_time', $tue['departure'], '01:30 is before 02:00');
        $this->assertSame([], $summary['off_days'], 'no punch is left over on a calendar day');
    }

    public function test_a_shift_cannot_start_and_end_at_the_same_time(): void
    {
        $this->as($this->admin)->putJson("/api/holidays/rules/companies/{$this->gems->id}", $this->ownRules(['shift_start' => '08:00', 'shift_end' => '08:00']))
            ->assertStatus(422)->assertJsonValidationErrors('shift_end');
        $this->as($this->admin)->putJson('/api/holidays/rules', ['shift_start' => '17:00', 'shift_end' => '02:00'])->assertOk();
    }

    public function test_a_company_open_24_hours_has_no_late_and_pays_first_to_last_punch(): void
    {
        $this->as($this->admin)->putJson("/api/holidays/rules/companies/{$this->gems->id}", $this->ownRules([
            'no_fixed_hours' => true, 'weekend_days' => [], 'shift_start' => '08:00', 'shift_end' => '16:00', 'late_grace_minutes' => 15,
        ]))->assertOk()->assertJsonPath('data.companies.1.rules.no_fixed_hours', true)
            ->assertJsonPath('data.companies.1.rules.late_grace_minutes', 0);

        Tenancy::set($this->tenant->id);
        AttendanceLog::query()->where('employee_id', $this->inGems->id)->delete();
        $device = Device::query()->firstOrFail();
        $tz = config('attendance.timezone');
        // Mon: a night — 22:00 in, 06:10 out next morning. Tue: nothing more. Wed: 15:30 in, 23:00 out.
        foreach (['2026-08-03 22:00', '2026-08-04 06:10', '2026-08-05 15:30', '2026-08-05 23:00'] as $at) {
            AttendanceLog::create(['device_id' => $device->id, 'device_user_id' => 'G1', 'employee_id' => $this->inGems->id,
                'punched_at' => Carbon::parse($at, $tz)->utc(), 'verify_method' => 1, 'status' => 0]);
        }
        Holiday::create(['date' => '2026-08-06', 'name' => 'Thursday off']);
        $gemma = Employee::query()->findOrFail($this->inGems->id);
        $from = Carbon::parse('2026-08-03', $tz)->startOfDay();
        $to = Carbon::parse('2026-08-06', $tz)->endOfDay();
        $daily = collect(app(AttendanceSummaryService::class)->summary($gemma, '2026-08-03', '2026-08-06', SummaryBatch::for([$gemma], $from, $to))['daily'])->keyBy('date');
        Tenancy::clear();

        $this->assertSame('present', $daily['2026-08-03']['status']);
        $this->assertNotSame('come_late', $daily['2026-08-03']['arrival'], '22:00 is not late: there are no fixed hours');
        $this->assertSame(8.2, $daily['2026-08-03']['payroll_hours'], '22:00 to 06:10, across midnight');
        $this->assertSame('absent', $daily['2026-08-04']['status'], 'the 06:10 punch closed Monday; Tuesday had none of its own');
        $this->assertSame('present', $daily['2026-08-05']['status']);
        $this->assertSame(7.5, $daily['2026-08-05']['payroll_hours']);
        $this->assertSame('excused', $daily['2026-08-06']['status']);
        $this->assertSame(8.0, $daily['2026-08-06']['payroll_hours'], 'a holiday is paid the normal day, 08:00–16:00');
    }
}
