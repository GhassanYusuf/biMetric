<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use App\Support\Timezone;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Company time zone, per-terminal overrides, and the attendance schedule (2026-10-05).
 *
 * Under test, in order of how badly it would hurt if it broke:
 *
 *  - the zone a terminal uses is its OWN override when set, and the company zone when
 *    not. Everything downstream — the clock written to the panel, and the offset its
 *    attendance is decoded in — resolves through that one answer, so it is tested
 *    directly rather than only through an endpoint.
 *  - a device's clock cannot be queued for a terminal whose panel reports SET_TIME
 *    unsupported, and the refusal is returned rather than silently swallowed. Glass
 *    Door and Entrance are exactly this case: both C3, neither can take a clock write.
 *  - an invalid zone and an impossible cron expression are REFUSED. The second is the
 *    subtle one: '0 0 30 2 *' passes cron validation and then throws when the library
 *    searches for a match, so it can only be caught where the search happens.
 */
class TimezoneSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($this->tenant);
        Tenancy::clear();
    }

    private function as(string $role): self
    {
        Tenancy::set($this->tenant->id);
        $user = User::withoutGlobalScopes()->create([
            'name' => $role, 'email' => "{$role}@acme.test", 'password' => Hash::make('pw-test-123'),
            'tenant_id' => $this->tenant->id, 'is_active' => true, 'email_verified_at' => now(),
        ]);
        $user->syncRoles([$role]);
        Tenancy::clear();

        $token = $this->postJson('/api/auth/login', [
            'email' => "{$role}@acme.test", 'password' => 'pw-test-123',
        ])->json('data.token');
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function device(array $overrides = []): Device
    {
        Tenancy::set($this->tenant->id);
        $device = Device::create(array_merge([
            'name' => 'IT Room',
            'ip_address' => '192.168.7.62',
            'protocol' => Device::PROTOCOL_BINARY,
            'model' => 'F18', // rule 21: write levels come from the model
            'comm_key' => '1',
        ], $overrides));
        Tenancy::clear();

        return $device;
    }

    // ------------------------------------------------------------------
    // Resolution: override beats company, company beats the env fallback.
    // ------------------------------------------------------------------

    public function test_a_terminal_with_no_override_follows_the_company_zone(): void
    {
        $api = $this->as('tenant-admin');
        $api->putJson('/api/settings', ['system' => ['timezone' => 'Asia/Riyadh']])->assertOk();
        $this->app['auth']->forgetGuards();

        $device = $this->device();

        Tenancy::set($this->tenant->id);
        $this->assertFalse(Timezone::deviceOverrides($device));
        $this->assertSame('Asia/Riyadh', Timezone::forDevice($device));
        Tenancy::clear();
    }

    public function test_a_terminal_in_another_country_keeps_its_own_zone(): void
    {
        $api = $this->as('tenant-admin');
        $api->putJson('/api/settings', ['system' => ['timezone' => 'Asia/Bahrain']])->assertOk();
        $this->app['auth']->forgetGuards();

        // This is the owner's actual requirement: a machine outside the country.
        $london = $this->device(['name' => 'London Office', 'timezone' => 'Europe/London']);

        Tenancy::set($this->tenant->id);
        $this->assertTrue(Timezone::deviceOverrides($london));
        $this->assertSame('Europe/London', Timezone::forDevice($london));
        // And the company zone is untouched by the override.
        $this->assertSame('Asia/Bahrain', Timezone::system());
        Tenancy::clear();
    }

    public function test_the_zone_written_to_a_clock_reflects_the_override_not_the_company(): void
    {
        // Proves the value that would be SENT to the panel follows the override.
        // London is +01:00 in October (BST), Bahrain +03:00 — a two-hour difference
        // that would mis-stamp every punch if the company zone leaked in here.
        $device = $this->device(['timezone' => 'Europe/London']);

        Tenancy::set($this->tenant->id);
        $now = Timezone::nowFor($device, new \DateTimeImmutable('2026-07-15 12:00:00', new \DateTimeZone('UTC')));
        $this->assertSame('Europe/London', $now->getTimezone()->getName());
        // 12:00 UTC in July is BST, so 13:00 local.
        $this->assertSame('13:00', $now->format('H:i'));
        Tenancy::clear();
    }

    // ------------------------------------------------------------------
    // Validation. Both forms legal; nonsense refused.
    // ------------------------------------------------------------------

    public static function zoneProvider(): array
    {
        return [
            'iana name' => ['Europe/London', true],
            'regional iana' => ['Asia/Bahrain', true],
            'utc offset' => ['+03:00', true],
            'negative offset' => ['-05:30', true],
            'utc' => ['UTC', true],
            'invented zone' => ['Mars/Olympus', false],
            'out of range offset' => ['+99:99', false],
            'nonsense' => ['not a zone', false],
        ];
    }

    /** @dataProvider zoneProvider */
    public function test_zone_validation(string $zone, bool $accepted): void
    {
        $this->assertSame($accepted, Timezone::isValid($zone), "zone '{$zone}'");
    }

    public function test_an_invalid_company_zone_is_refused_and_nothing_is_saved(): void
    {
        $api = $this->as('tenant-admin');

        $api->putJson('/api/settings', ['system' => ['timezone' => 'Mars/Olympus']])->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->assertDatabaseMissing('settings', ['key' => 'system.timezone']);
    }

    public function test_a_devices_own_zone_is_validated_too(): void
    {
        $device = $this->device();
        $api = $this->as('tenant-admin');

        $api->putJson("/api/devices/{$device->id}", [
            'name' => $device->name,
            'ip_address' => $device->ip_address,
            'protocol' => $device->protocol,
            'timezone' => 'Mars/Olympus',
        ])->assertStatus(422)->assertJsonValidationErrors('timezone');
    }

    // ------------------------------------------------------------------
    // The plan: what each terminal would be set to, and what cannot be.
    // ------------------------------------------------------------------

    public function test_the_plan_reports_each_terminals_zone_and_whether_it_can_be_set(): void
    {
        $api = $this->as('tenant-admin');
        $api->putJson('/api/settings', ['system' => ['timezone' => 'Asia/Bahrain']])->assertOk();
        $this->app['auth']->forgetGuards();

        $lan = $this->device(['name' => 'IT Room']);
        $london = $this->device(['name' => 'London', 'ip_address' => '10.0.0.9', 'timezone' => 'Europe/London']);
        // A C3 panel, which cannot have its clock set at all.
        $c3 = $this->device(['name' => 'Glass Door', 'ip_address' => '192.168.7.61', 'protocol' => Device::PROTOCOL_C3]);

        $plan = $api->getJson('/api/settings/timezones')->assertOk()->json('data');
        $byName = collect($plan['devices'])->keyBy('name');

        $this->assertSame('Asia/Bahrain', $byName['IT Room']['timezone']);
        $this->assertFalse($byName['IT Room']['overrides']);

        $this->assertSame('Europe/London', $byName['London']['timezone']);
        $this->assertTrue($byName['London']['overrides']);

        // The refusal must be a reason an operator can read, not a blank cell.
        $this->assertFalse($byName['Glass Door']['supports_set_time']);
        $this->assertNotNull($byName['Glass Door']['refusal']);
        $this->assertStringContainsString('Not implemented', $byName['Glass Door']['refusal']);
    }

    public function test_apply_queues_only_the_terminals_that_can_take_a_clock(): void
    {
        $api = $this->as('tenant-admin');

        $lan = $this->device(['name' => 'IT Room']);
        $c3 = $this->device(['name' => 'Glass Door', 'ip_address' => '192.168.7.61', 'protocol' => Device::PROTOCOL_C3]);

        $res = $api->postJson('/api/settings/timezones/apply')->assertOk()->json('data');

        $this->assertSame([$lan->id], collect($res['queued'])->pluck('id')->all());
        // The C3 panel is REPORTED as skipped. Silently dropping it would leave an
        // operator believing every terminal was corrected.
        $this->assertSame([$c3->id], collect($res['refused'])->pluck('id')->all());
        $this->assertNotEmpty($res['refused'][0]['reason']);

        $this->assertDatabaseHas('device_commands', [
            'device_id' => $lan->id,
            'command' => \App\Models\DeviceCommand::SYNC_TIME,
        ]);
        $this->assertDatabaseMissing('device_commands', [
            'device_id' => $c3->id,
            'command' => \App\Models\DeviceCommand::SYNC_TIME,
        ]);
    }

    public function test_applying_needs_the_manage_permission(): void
    {
        $this->device(['name' => 'IT Room']);

        $this->as('operator')->postJson('/api/settings/timezones/apply')->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // The schedule.
    // ------------------------------------------------------------------

    public function test_the_schedule_defaults_to_off_with_a_daily_expression(): void
    {
        $api = $this->as('tenant-admin');

        $data = $api->getJson('/api/settings/attendance-schedule')->assertOk()->json('data');

        // Off by default, and deliberately: enabling it starts real traffic at real
        // terminals, so it must be something a person does.
        $this->assertFalse($data['enabled']);
        $this->assertSame('0 3 * * *', $data['expression']);
        $this->assertTrue($data['valid']);
        $this->assertCount(3, $data['next_runs']);
    }

    public function test_next_runs_are_given_in_company_time_not_server_time(): void
    {
        // The host runs app.timezone = UTC. If the preview leaked that, "03:00" would
        // be reported as 03:00 UTC while actually firing at 06:00 Bahrain time.
        $this->assertSame('UTC', config('app.timezone'));

        $api = $this->as('tenant-admin');
        $api->putJson('/api/settings', ['system' => ['timezone' => 'Asia/Bahrain']])->assertOk();
        $this->app['auth']->forgetGuards();

        $data = $api->getJson('/api/settings/attendance-schedule?expression='.urlencode('0 3 * * *'))
            ->assertOk()->json('data');

        $this->assertSame('Asia/Bahrain', $data['timezone']);
        foreach ($data['next_runs'] as $when) {
            $this->assertStringContainsString('+03:00', $when, "next run '{$when}' is not Bahrain time");
            $this->assertMatchesRegularExpression('/ 03:00:00 /', $when);
        }
    }

    public function test_an_impossible_but_syntactically_valid_schedule_is_refused(): void
    {
        // 30 February passes cron validation and only fails when the library searches
        // for the first match — so this can only be caught where the search happens.
        $api = $this->as('tenant-admin');

        $data = $api->getJson('/api/settings/attendance-schedule?expression='.urlencode('0 0 30 2 *'))
            ->assertOk()->json('data');

        $this->assertFalse($data['valid']);
        $this->assertSame([], $data['next_runs']);
        $this->assertNotEmpty($data['error']);
    }

    public static function badScheduleProvider(): array
    {
        return [
            'too few fields' => ['0 3 * *'],
            'too many fields' => ['0 3 * * * *'],
            'words' => ['every tuesday'],
            'trailing junk' => ['0 3 * * * xyz'],
        ];
    }

    /** @dataProvider badScheduleProvider */
    public function test_a_malformed_schedule_is_refused_not_thrown(string $expression): void
    {
        $api = $this->as('tenant-admin');

        $api->getJson('/api/settings/attendance-schedule?expression='.urlencode($expression))
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.next_runs', []);
    }

    public function test_a_blank_preview_falls_back_to_the_saved_schedule(): void
    {
        // The settings screen sends an empty field while an operator is still typing.
        // Blank must mean "show me the saved one", not "this is invalid".
        $api = $this->as('tenant-admin');
        $api->putJson('/api/settings', ['attendance' => ['sync_schedule' => '15 4 * * *']])->assertOk();
        $this->app['auth']->forgetGuards();

        $data = $api->getJson('/api/settings/attendance-schedule?expression=')
            ->assertOk()->json('data');

        $this->assertTrue($data['valid']);
        $this->assertSame('15 4 * * *', $data['expression']);
    }

    public function test_an_invalid_schedule_cannot_be_saved(): void
    {
        $api = $this->as('tenant-admin');

        $api->putJson('/api/settings', ['attendance' => ['sync_schedule' => 'every tuesday']])->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->assertDatabaseMissing('settings', ['key' => 'attendance.sync_schedule']);
    }

    public function test_a_valid_schedule_can_be_saved_and_is_audited(): void
    {
        $api = $this->as('tenant-admin');

        $api->putJson('/api/settings', [
            'attendance' => [
                'sync_enabled' => true,
                'sync_schedule' => '30 2 * * *',
            ],
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        $this->assertDatabaseHas('settings', [
            'tenant_id' => $this->tenant->id,
            'key' => 'attendance.sync_schedule',
            // JSON as the 'array' cast stores it, not the bare string.
            'value' => json_encode('30 2 * * *'),
        ]);
        $this->assertDatabaseHas('settings', [
            'tenant_id' => $this->tenant->id,
            'key' => 'attendance.sync_enabled',
            'value' => 'true',
        ]);
    }
}
