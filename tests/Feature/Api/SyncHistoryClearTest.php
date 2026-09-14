<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceLog;
use App\Models\AttendanceRawLog;
use App\Models\AttendanceSyncRun;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Clearing sync history.
 *
 * The assertions that matter are the ones about what SURVIVES. This endpoint is the
 * only thing in the app that deletes device history in bulk, and the three ways it
 * could quietly ruin a workspace are: taking imported punches with it, moving the
 * incremental watermark so the next sync skips real records, or deleting a run that
 * is still transferring out from under the progress recorder.
 */
class SyncHistoryClearTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Device $device;

    private Device $other;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();

        $this->tenant = Tenant::query()->firstOrFail();
        Tenancy::set($this->tenant->id);

        $this->device = $this->newDevice('Gassan', '10.0.0.9');
        $this->other = $this->newDevice('IT Room', '10.0.0.10');
    }

    public function test_it_deletes_finished_runs_and_the_payloads_they_archived(): void
    {
        $run = $this->newRun($this->device, AttendanceSyncRun::STATUS_SUCCEEDED);
        $this->newRawLog($run);
        $this->newRawLog($run);

        $this->deleteJson('/api/device-sync-runs', [], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.runs_deleted', 1)
            ->assertJsonPath('data.raw_logs_deleted', 2);

        $this->assertSame(0, AttendanceSyncRun::query()->count());
        $this->assertSame(0, AttendanceRawLog::query()->count());
    }

    public function test_a_run_that_is_still_transferring_is_kept(): void
    {
        $this->newRun($this->device, AttendanceSyncRun::STATUS_SUCCEEDED);
        $running = $this->newRun($this->device, AttendanceSyncRun::STATUS_RUNNING);

        // Deleting this row mid-transfer would leave SyncProgressRecorder and the
        // progress poller writing to and reading from a row that no longer exists.
        $this->deleteJson('/api/device-sync-runs', [], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.runs_deleted', 1)
            ->assertJsonPath('data.runs_kept_running', 1);

        $this->assertSame([$running->id], AttendanceSyncRun::query()->pluck('id')->all());
    }

    public function test_imported_attendance_and_the_watermark_are_untouched(): void
    {
        $watermark = now()->subHour()->startOfSecond();
        $this->device->forceFill(['attendance_watermark' => $watermark])->save();

        $run = $this->newRun($this->device, AttendanceSyncRun::STATUS_SUCCEEDED);
        $this->newRawLog($run);

        AttendanceLog::create([
            'tenant_id' => $this->tenant->id,
            'device_id' => $this->device->id,
            'device_user_id' => '1001',
            'punched_at' => now()->subMinutes(30),
        ]);

        $this->deleteJson('/api/device-sync-runs', [], $this->auth())->assertOk();

        // Punches are the thing an operator is actually afraid of losing, and the
        // watermark is the thing whose loss would be invisible until records went
        // missing weeks later.
        $this->assertSame(1, AttendanceLog::query()->count());
        $this->assertTrue($this->device->fresh()->attendance_watermark->equalTo($watermark));
    }

    public function test_it_clears_only_the_filtered_device(): void
    {
        $mine = $this->newRun($this->device, AttendanceSyncRun::STATUS_SUCCEEDED);
        $theirs = $this->newRun($this->other, AttendanceSyncRun::STATUS_SUCCEEDED);
        $this->newRawLog($theirs);

        $this->deleteJson("/api/device-sync-runs?device_id={$this->device->id}", [], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.runs_deleted', 1)
            ->assertJsonPath('data.raw_logs_deleted', 0);

        $this->assertSame([$theirs->id], AttendanceSyncRun::query()->pluck('id')->all());
        $this->assertSame(1, AttendanceRawLog::query()->count());
        $this->assertNull(AttendanceSyncRun::query()->find($mine->id));
    }

    public function test_it_clears_only_the_filtered_outcome(): void
    {
        $failed = $this->newRun($this->device, AttendanceSyncRun::STATUS_FAILED);
        $ok = $this->newRun($this->device, AttendanceSyncRun::STATUS_SUCCEEDED);

        $this->deleteJson('/api/device-sync-runs?status=failed', [], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.runs_deleted', 1);

        $this->assertSame([$ok->id], AttendanceSyncRun::query()->pluck('id')->all());
        $this->assertNull(AttendanceSyncRun::query()->find($failed->id));
    }

    public function test_an_empty_history_says_so_rather_than_reporting_a_clear(): void
    {
        $this->deleteJson('/api/device-sync-runs', [], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.runs_deleted', 0)
            ->assertJsonPath('message', 'There was no sync history to clear.');
    }

    public function test_filtering_to_running_alone_clears_nothing_and_says_why(): void
    {
        $this->newRun($this->device, AttendanceSyncRun::STATUS_RUNNING);

        $this->deleteJson('/api/device-sync-runs?status=running', [], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.runs_deleted', 0)
            ->assertJsonPath('data.runs_kept_running', 1)
            ->assertJsonPath('message', 'Nothing cleared. A sync that is still running is never deleted.');

        $this->assertSame(1, AttendanceSyncRun::query()->count());
    }

    public function test_the_clear_is_audited(): void
    {
        $run = $this->newRun($this->device, AttendanceSyncRun::STATUS_SUCCEEDED);
        $this->newRawLog($run);

        $this->deleteJson("/api/device-sync-runs?device_id={$this->device->id}", [], $this->auth())->assertOk();

        // A mass delete fires no model events, so the trail is written by hand —
        // and "who erased the history" is exactly what gets asked afterwards.
        $entry = AuditLog::query()->where('action', 'syncrun.cleared')->firstOrFail();

        $this->assertSame(1, $entry->changes['runs_deleted']);
        $this->assertSame(1, $entry->changes['raw_logs_deleted']);
        $this->assertSame($this->device->id, $entry->changes['filter_device_id']);
        $this->assertNotNull($entry->user_id);
    }

    public function test_an_operator_who_may_sync_may_not_erase_the_record_of_it(): void
    {
        $this->newRun($this->device, AttendanceSyncRun::STATUS_SUCCEEDED);

        $this->deleteJson('/api/device-sync-runs', [], $this->auth('operator'))->assertStatus(403);

        $this->assertSame(1, AttendanceSyncRun::query()->count());
    }

    public function test_clearing_requires_authentication(): void
    {
        $this->deleteJson('/api/device-sync-runs')->assertStatus(401);
    }

    private function newDevice(string $name, string $ip): Device
    {
        return Device::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'ip_address' => $ip,
            'port' => 4370,
            'is_active' => true,
        ]);
    }

    private function newRun(Device $device, string $status): AttendanceSyncRun
    {
        return AttendanceSyncRun::create([
            'tenant_id' => $this->tenant->id,
            'device_id' => $device->id,
            'mode' => AttendanceSyncRun::MODE_INCREMENTAL,
            'status' => $status,
            'started_at' => now(),
        ]);
    }

    private function newRawLog(AttendanceSyncRun $run): AttendanceRawLog
    {
        return AttendanceRawLog::create([
            'tenant_id' => $this->tenant->id,
            'device_id' => $run->device_id,
            'attendance_sync_run_id' => $run->id,
            'raw' => '00ff00ff',
            'decoded' => false,
            'imported' => false,
            'skip_reason' => AttendanceRawLog::SKIP_UNDECODABLE,
        ]);
    }

    /** @return array<string,string> */
    private function auth(?string $role = null): array
    {
        $user = User::withoutGlobalScopes()->firstOrFail();

        if ($role !== null) {
            $user = User::withoutGlobalScopes()->create([
                'name' => 'Limited '.$role,
                'email' => $role.'@example.test',
                'password' => bcrypt('secret-password'),
                'tenant_id' => $this->tenant->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $user->syncRoles([$role]);
        }

        return ['Authorization' => 'Bearer '.$user->createToken('clear-test')->plainTextToken];
    }
}
