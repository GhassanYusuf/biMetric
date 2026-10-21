<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceSyncRun;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Devices\SyncProgressRecorder;
use App\Support\DeviceProgress;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Live sync progress: what gets published, and what the poller sees.
 *
 * The assertions that matter most are the negative ones. A progress bar is a claim
 * about hardware, and this project's standing rule is that an unknown must never
 * render as a number (rules 3 and 10) — an invented denominator is unfalsifiable
 * from the screen, which is exactly how a wrong "0 fingerprints" survived for
 * months.
 */
class SyncProgressTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();

        $tenant = Tenant::query()->firstOrFail();
        Tenancy::set($tenant->id);

        $this->device = Device::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Terminal',
            'ip_address' => '10.0.0.9',
            'port' => 4370,
            'is_active' => true,
        ]);

        Cache::store('file')->forget(SyncProgressRecorder::cacheKey($this->device->id));
    }

    protected function tearDown(): void
    {
        Cache::store('file')->forget(SyncProgressRecorder::cacheKey($this->device->id));
        parent::tearDown();
    }

    public function test_it_reports_a_running_read_with_real_numbers(): void
    {
        $recorder = new SyncProgressRecorder($this->newRun());

        $recorder(new DeviceProgress('attendance', read: 3_251, total: 10_302));

        $this->getJson("/api/devices/{$this->device->id}/sync-progress", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.running', true)
            ->assertJsonPath('data.progress.phase', AttendanceSyncRun::PHASE_READING)
            ->assertJsonPath('data.progress.read', 3_251)
            ->assertJsonPath('data.progress.total', 10_302)
            ->assertJsonPath('data.progress.unit', 'records')
            ->assertJsonPath('data.progress.percent', 32);
    }

    public function test_percent_is_null_when_the_device_declared_no_total(): void
    {
        $recorder = new SyncProgressRecorder($this->newRun());

        // Not 0. A bar must go indeterminate rather than claim it is at the start.
        $recorder(new DeviceProgress('attendance', read: 900, total: 0, unit: DeviceProgress::UNIT_BYTES));

        $this->getJson("/api/devices/{$this->device->id}/sync-progress", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.progress.percent', null)
            ->assertJsonPath('data.progress.total', null)
            ->assertJsonPath('data.progress.unit', 'bytes');
    }

    public function test_the_unit_is_reported_so_bytes_are_never_shown_as_punches(): void
    {
        $recorder = new SyncProgressRecorder($this->newRun());

        $recorder(new DeviceProgress('attendance', read: 162_816, total: 412_084, unit: DeviceProgress::UNIT_BYTES));

        // 412,084 is a byte count. A screen that assumed records would tell an
        // operator there were four hundred thousand punches to read.
        $this->getJson("/api/devices/{$this->device->id}/sync-progress", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.progress.unit', 'bytes');
    }

    public function test_the_import_phase_is_distinct_from_the_read(): void
    {
        $recorder = new SyncProgressRecorder($this->newRun());

        $recorder->beginImport(10_302);
        $recorder->importedSoFar(5_000, 10_302);

        $this->getJson("/api/devices/{$this->device->id}/sync-progress", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.progress.phase', AttendanceSyncRun::PHASE_IMPORTING)
            ->assertJsonPath('data.progress.percent', 49);
    }

    public function test_a_finished_run_reports_no_progress_at_all(): void
    {
        $run = $this->newRun();
        $recorder = new SyncProgressRecorder($run);

        $recorder(new DeviceProgress('attendance', read: 10_302, total: 10_302));
        $recorder->finish();

        $run->forceFill(['status' => AttendanceSyncRun::STATUS_SUCCEEDED, 'records_read' => 10_302])->save();

        // A leftover "10,302 of 10,302" would make a finished run look in-flight
        // forever. The final counts are the authority once it is over.
        $this->getJson("/api/devices/{$this->device->id}/sync-progress", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.running', false)
            ->assertJsonPath('data.progress', null)
            ->assertJsonPath('data.counts.read', 10_302);
    }

    public function test_a_stale_reading_from_an_older_run_is_ignored(): void
    {
        $old = $this->newRun();
        (new SyncProgressRecorder($old))(new DeviceProgress('attendance', read: 500, total: 10_302));
        $old->forceFill(['status' => AttendanceSyncRun::STATUS_FAILED, 'finished_at' => now()])->save();

        // A newer run exists, so the cached reading belongs to a dead one and must
        // not be presented as this run's progress.
        $this->newRun();

        $this->getJson("/api/devices/{$this->device->id}/sync-progress", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.progress', null);
    }

    public function test_a_device_that_never_synced_says_so_rather_than_404(): void
    {
        $this->getJson("/api/devices/{$this->device->id}/sync-progress", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.running', false)
            ->assertJsonPath('data.progress', null);
    }

    public function test_progress_requires_authentication(): void
    {
        $this->getJson("/api/devices/{$this->device->id}/sync-progress")->assertStatus(401);
    }

    /**
     * 2026-10-21: a read of an empty terminal finished before the screen's first poll,
     * so it never saw "running" and waited forever. The run now names its command.
     */
    public function test_a_finished_run_names_the_command_that_started_it(): void
    {
        $command = \App\Models\DeviceCommand::create([
            'tenant_id' => $this->device->tenant_id, 'device_id' => $this->device->id,
            'command' => \App\Models\DeviceCommand::SYNC_ATTENDANCE, 'status' => \App\Models\DeviceCommand::STATUS_SUCCEEDED,
        ]);
        $run = $this->newRun();
        $run->forceFill(['device_command_id' => $command->id, 'status' => AttendanceSyncRun::STATUS_SUCCEEDED, 'finished_at' => now(), 'records_read' => 0])->save();

        $this->getJson("/api/devices/{$this->device->id}/sync-progress", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.running', false)
            ->assertJsonPath('data.status', AttendanceSyncRun::STATUS_SUCCEEDED)
            ->assertJsonPath('data.device_command_id', $command->id)
            ->assertJsonPath('data.counts.read', 0);
    }

    private function newRun(): AttendanceSyncRun
    {
        return AttendanceSyncRun::create([
            'tenant_id' => $this->device->tenant_id,
            'device_id' => $this->device->id,
            'mode' => AttendanceSyncRun::MODE_INCREMENTAL,
            'status' => AttendanceSyncRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    /** @return array<string,string> */
    private function auth(): array
    {
        $user = User::withoutGlobalScopes()->firstOrFail();
        $token = $user->createToken('progress-test')->plainTextToken;

        return ['Authorization' => "Bearer {$token}"];
    }
}
