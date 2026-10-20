<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\ScheduledJobController;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\ScheduledJob;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The Cron jobs screen's API (owner, 2026-10-20). Took over the schedule tests that were
 * in TimezoneSettingsTest when the schedule was a setting.
 *
 * Under test: only settings.view sees it and only settings.manage changes it; a job is
 * an action from the fixed list, never anything else; an invalid, impossible or
 * too-frequent schedule is refused on its field; next runs are COMPANY time; terminals
 * must be the company's own; another company's job is invisible; "Run now" queues and
 * is audited; and the read-only system list matches what the scheduler really runs.
 */
class ScheduledJobsApiTest extends TestCase
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
        Queue::fake();
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
            'name' => 'IT Room', 'ip_address' => '192.0.2.62', 'protocol' => Device::PROTOCOL_BINARY,
            'model' => 'F18', 'comm_key' => '1', 'is_active' => true,
        ], $overrides));
        Tenancy::clear();

        return $device;
    }

    public function test_only_settings_permissions_see_and_change_it(): void
    {
        $this->as('operator')->getJson('/api/scheduled-jobs')->assertStatus(403);
        $this->as('hr-manager')->postJson('/api/scheduled-jobs', ['name' => 'x', 'action' => 'set_clocks', 'schedule' => '0 5 * * *'])->assertStatus(403);
    }

    public function test_a_job_is_added_listed_paused_edited_and_deleted_and_every_change_audited(): void
    {
        $api = $this->as('tenant-admin');

        $id = $api->postJson('/api/scheduled-jobs', ['name' => 'Morning', 'action' => 'clock_and_read', 'schedule' => '0  5,10 * * *'])
            ->assertCreated()->assertJsonPath('data.schedule', '0 5,10 * * *')->json('data.id');

        $list = $api->getJson('/api/scheduled-jobs')->assertOk();
        $list->assertJsonPath('data.0.name', 'Morning')->assertJsonPath('data.0.action_label', 'Set clocks, then read the past day');
        $this->assertCount(2, $list->json('data.0.next_runs'));
        $this->assertCount(count(ScheduledJob::ACTIONS), $list->json('meta.actions'));

        $api->putJson("/api/scheduled-jobs/{$id}", ['is_enabled' => false])->assertOk()
            ->assertJsonPath('message', '“Morning” is paused.')->assertJsonPath('data.next_runs', []);
        $api->putJson("/api/scheduled-jobs/{$id}", ['schedule' => '30 6 * * 0-4'])->assertOk()->assertJsonPath('data.schedule', '30 6 * * 0-4');
        $api->deleteJson("/api/scheduled-jobs/{$id}")->assertOk();

        $this->assertSame(0, ScheduledJob::withoutGlobalScopes()->count());
        $this->assertSame(4, AuditLog::withoutGlobalScopes()->where('auditable_type', ScheduledJob::class)->count());
    }

    public static function badScheduleProvider(): array
    {
        return [
            'too few fields' => ['0 3 * *'],
            'too many fields' => ['0 3 * * * *'],
            'words' => ['every tuesday'],
            'impossible date' => ['0 0 30 2 *'],
            'every minute' => ['* * * * *'],
            'every five minutes' => ['*/5 * * * *'],
        ];
    }

    /** @dataProvider badScheduleProvider */
    public function test_a_bad_schedule_is_refused_on_its_field_and_previews_as_invalid(string $expression): void
    {
        $api = $this->as('tenant-admin');

        $api->postJson('/api/scheduled-jobs', ['name' => 'x', 'action' => 'set_clocks', 'schedule' => $expression])
            ->assertStatus(422)->assertJsonValidationErrors('schedule');
        $this->assertSame(0, ScheduledJob::withoutGlobalScopes()->count());

        $api->getJson('/api/scheduled-jobs/preview?expression='.urlencode($expression))
            ->assertOk()->assertJsonPath('data.valid', false)->assertJsonPath('data.next_runs', []);
    }

    public function test_only_actions_from_the_list_and_only_our_own_terminals(): void
    {
        $api = $this->as('tenant-admin');

        $api->postJson('/api/scheduled-jobs', ['name' => 'x', 'action' => 'php artisan db:wipe', 'schedule' => '0 5 * * *'])
            ->assertStatus(422)->assertJsonValidationErrors('action');

        $theirs = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        Tenancy::set($theirs->id);
        $foreign = Device::create(['name' => 'Theirs', 'ip_address' => '192.0.2.9', 'protocol' => Device::PROTOCOL_BINARY, 'model' => 'F18', 'comm_key' => '1']);
        Tenancy::clear();

        $api->postJson('/api/scheduled-jobs', ['name' => 'x', 'action' => 'set_clocks', 'schedule' => '0 5 * * *', 'device_ids' => [$foreign->id]])
            ->assertStatus(422)->assertJsonValidationErrors('device_ids.0');
        $api->postJson('/api/scheduled-jobs', ['name' => 'x', 'action' => 'set_clocks', 'schedule' => '0 5 * * *', 'device_ids' => [$this->device()->id]])
            ->assertCreated();
    }

    public function test_another_companys_job_is_invisible(): void
    {
        $theirs = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        $job = ScheduledJob::create(['tenant_id' => $theirs->id, 'name' => 'Theirs', 'action' => 'set_clocks', 'schedule' => '0 5 * * *']);

        $api = $this->as('tenant-admin');
        $api->getJson('/api/scheduled-jobs')->assertOk()->assertJsonCount(0, 'data');
        $api->putJson("/api/scheduled-jobs/{$job->id}", ['is_enabled' => false])->assertNotFound();
        $api->postJson("/api/scheduled-jobs/{$job->id}/run")->assertNotFound();
    }

    public function test_next_runs_are_company_time_not_server_time(): void
    {
        // The host runs app.timezone = UTC; "03:00" must be 03:00 where the company is.
        $this->assertSame('UTC', config('app.timezone'));
        $api = $this->as('tenant-admin');
        $api->putJson('/api/settings', ['system' => ['timezone' => 'Asia/Bahrain']])->assertOk();
        $this->app['auth']->forgetGuards();

        $data = $api->getJson('/api/scheduled-jobs/preview?expression='.urlencode('0 3 * * *'))->assertOk()->json('data');

        $this->assertSame('Asia/Bahrain', $data['timezone']);
        $this->assertCount(3, $data['next_runs']);
        foreach ($data['next_runs'] as $when) {
            $this->assertStringEndsWith('T03:00:00+03:00', substr($when, 10), "next run '{$when}' is not 03:00 Bahrain");
        }
    }

    public function test_run_now_queues_the_commands_and_is_audited(): void
    {
        $this->device();
        $api = $this->as('tenant-admin');
        $id = $api->postJson('/api/scheduled-jobs', ['name' => 'Clocks', 'action' => 'set_clocks', 'schedule' => '0 5 * * *'])->json('data.id');

        $api->postJson("/api/scheduled-jobs/{$id}/run")->assertOk()->assertJsonPath('message', 'Queued 1 command on 1 terminal.');

        $this->assertSame([DeviceCommand::SYNC_TIME], DeviceCommand::withoutGlobalScopes()->pluck('command')->all());
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'scheduled_job.run')->where('auditable_id', $id)->exists());
    }

    public function test_the_system_list_is_what_the_scheduler_really_runs(): void
    {
        $this->app->make(Kernel::class)->all(); // loads routes/console.php
        $events = collect($this->app->make(Schedule::class)->events())
            ->mapWithKeys(fn ($e) => [$e->description => $e->expression])
            ->except('scheduled-jobs')
            ->all();

        $this->assertEqualsCanonicalizing(
            $events,
            array_map(fn (array $j) => $j[0], ScheduledJobController::SYSTEM_JOBS),
            'SYSTEM_JOBS must list every scheduler entry (except the job runner) with its real expression',
        );
    }
}
