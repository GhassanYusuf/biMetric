<?php

namespace Tests\Feature\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\AttendanceLog;
use App\Models\AttendanceSyncRun;
use App\Models\Device;
use App\Models\Tenant;
use App\Services\Devices\AttendanceSyncService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Importing ONE WINDOW of days from a terminal, with no hardware present.
 *
 * The window is a client-side filter and can never be anything else: this protocol
 * has no "punches since X", so the device hands over its whole table either way
 * (see AttendanceSyncService). What is under test is therefore not the read but the
 * three things that make a bounded import trustworthy:
 *
 *   1. The window is the DEVICE's calendar day, not the app server's. Punches carry
 *      the terminal's +03:00 offset, so a window built in the app timezone would be
 *      three hours out at both ends and would move an evening's punches into the
 *      wrong day — the offset class of bug rule 10 exists for.
 *   2. Records outside the window are COUNTED, never silently dropped (rule 10).
 *      "read 9,579, imported 640" must be explicable from the run row alone.
 *   3. A range read LEAVES THE WATERMARK ALONE. It is a backfill, not a statement
 *      about where ordinary syncing has got to, and moving the watermark forward
 *      would permanently skip every punch between it and the window's end.
 */
class AttendanceRangeSyncTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    private RecordingAttendanceDriver $driver;

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
            'name' => 'IT Room',
            'ip_address' => '192.0.2.62',
            'port' => 4370,
            'protocol' => Device::PROTOCOL_BINARY,
            'timezone' => '+03:00',
            'is_active' => true,
        ]);

        $this->driver = new RecordingAttendanceDriver;
        $this->app->instance(BiometricDeviceDriver::class, $this->driver);
    }

    /** One punch, stamped in the DEVICE's local time exactly as the codec produces it. */
    private function punch(string $localTime, string $pin = '101'): array
    {
        return [
            'user_id' => $pin,
            'timestamp' => Carbon::parse($localTime, '+03:00'),
            'verify_method' => 1,
            'status' => 0,
            'raw' => "raw:{$localTime}",
        ];
    }

    private function sync(?string $from, ?string $to): array
    {
        return $this->app->make(AttendanceSyncService::class)
            ->sync($this->device, from: $from, to: $to);
    }

    public function test_it_imports_only_the_punches_inside_the_window(): void
    {
        $this->driver->attendance = [
            $this->punch('2026-07-15 08:00:00'),   // before
            $this->punch('2026-08-01 00:05:00'),   // first minutes of the first day
            $this->punch('2026-08-15 12:00:00'),   // squarely inside
            $this->punch('2026-08-31 23:50:00'),   // last minutes of the last day
            $this->punch('2026-09-05 08:00:00'),   // after
        ];

        $result = $this->sync('2026-08-01', '2026-08-31');

        $this->assertSame(3, AttendanceLog::query()->count());
        $this->assertSame(3, $result['raw']['counts']['imported']);

        // Counted, not dropped — and said out loud in the message an operator reads,
        // so "read 5, imported 3" never looks like a partial failure.
        $this->assertSame(2, $result['raw']['counts']['out_of_range']);
        $this->assertStringContainsString('2 outside 2026-08-01…2026-08-31', $result['message']);
    }

    /**
     * Both named days are imported IN FULL, in the terminal's own timezone.
     *
     * The two boundary punches above are the ones that would disappear if the bounds
     * were built in UTC: 00:05 on the 1st is 2026-07-31 21:05 UTC, and 23:50 on the
     * 31st is 2026-08-31 20:50 UTC. A UTC window would drop the first and a naive
     * end-of-day would keep the second only by accident.
     */
    public function test_the_window_is_the_devices_calendar_day_not_the_servers(): void
    {
        $this->driver->attendance = [
            $this->punch('2026-08-01 00:05:00'),
            $this->punch('2026-07-31 23:55:00'),
        ];

        $this->sync('2026-08-01', '2026-08-01');

        $stored = AttendanceLog::query()->pluck('punched_at')->map(
            fn ($at) => Carbon::parse($at)->format('Y-m-d H:i:s'),
        )->all();

        // Exactly one row, stored in UTC as every other punch is.
        $this->assertSame(['2026-07-31 21:05:00'], $stored);
    }

    public function test_a_range_read_does_not_move_the_sync_watermark(): void
    {
        $watermark = Carbon::parse('2026-09-01 00:00:00', 'UTC');
        $this->device->forceFill(['attendance_watermark' => $watermark])->save();

        $this->driver->attendance = [
            $this->punch('2026-08-15 12:00:00'),
            $this->punch('2026-08-20 12:00:00'),
        ];

        $this->sync('2026-08-01', '2026-08-31');

        /*
         * Both punches are BELOW the watermark, so an incremental read would have
         * skipped them — that they landed proves the window replaces the watermark
         * rather than narrowing it. That the watermark is still 2026-09-01 proves the
         * backfill did not rewrite where ordinary syncing is up to; had it been
         * dragged back to 20 August, the next sync would reconsider twelve days, and
         * had the window ended today it would have skipped everything in between
         * forever.
         */
        $this->assertSame(2, AttendanceLog::query()->count());
        $this->assertTrue($watermark->equalTo($this->device->fresh()->attendance_watermark));
    }

    public function test_the_run_records_the_window_it_was_asked_for(): void
    {
        $this->driver->attendance = [$this->punch('2026-08-15 12:00:00')];

        $this->sync('2026-08-01', '2026-08-31');

        $run = AttendanceSyncRun::query()->latest('id')->firstOrFail();

        // Without these the history cannot tell a range read from a full one, and
        // "why did this import 1 of 9,579?" has no answer on the row.
        $this->assertSame(AttendanceSyncRun::MODE_RANGE, $run->mode);
        $this->assertSame('2026-08-01', $run->range_from->format('Y-m-d'));
        $this->assertSame('2026-08-31', $run->range_to->format('Y-m-d'));
        /*
         * 'partial', not 'succeeded': the synthetic PIN belongs to no employee, so
         * the punch is counted as unmatched — existing behaviour, unrelated to the
         * window. Asserted rather than avoided so that a range read is shown to go
         * through the same honesty checks every other run does.
         */
        $this->assertSame(AttendanceSyncRun::STATUS_PARTIAL, $run->status);
    }

    /** An open-ended window is a real request: "everything since we came back up". */
    public function test_one_sided_windows_are_honoured(): void
    {
        $this->driver->attendance = [
            $this->punch('2026-07-15 08:00:00'),
            $this->punch('2026-08-15 08:00:00'),
        ];

        $this->sync('2026-08-01', null);

        $this->assertSame(1, AttendanceLog::query()->count());
    }

    /**
     * The guard on everything above: an ordinary sync is UNCHANGED.
     *
     * A range is an addition, and the watermark path is what every scheduled sync in
     * production takes. If this ever fails, the feature broke the normal case.
     */
    public function test_an_ordinary_sync_still_advances_the_watermark(): void
    {
        $this->driver->attendance = [
            $this->punch('2026-08-15 12:00:00'),
            $this->punch('2026-08-20 12:00:00'),
        ];

        $this->app->make(AttendanceSyncService::class)->sync($this->device);

        $run = AttendanceSyncRun::query()->latest('id')->firstOrFail();

        $this->assertSame(AttendanceSyncRun::MODE_INCREMENTAL, $run->mode);
        $this->assertSame(0, $run->records_out_of_range);
        $this->assertNull($run->range_from);
        $this->assertSame(
            '2026-08-20 09:00:00',
            $this->device->fresh()->attendance_watermark->format('Y-m-d H:i:s'),
        );
    }
}

/** A terminal that hands over whatever the test put in it — the whole table, always. */
class RecordingAttendanceDriver extends FakeBiometricDriver
{
    /** @var list<array<string,mixed>> */
    public array $attendance = [];

    public function readAttendance(Device $device, ?callable $onProgress = null): array
    {
        return $this->attendance;
    }
}
