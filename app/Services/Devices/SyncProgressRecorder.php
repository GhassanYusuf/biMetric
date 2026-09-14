<?php

namespace App\Services\Devices;

use App\Models\AttendanceSyncRun;
use App\Support\DeviceProgress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Publishes a running sync's progress somewhere a poller can read it.
 *
 * ===========================================================================
 *  READ THIS BEFORE MAKING IT REPORT MORE OFTEN
 * ===========================================================================
 *
 * MEASURED on a ZKTeco F18, 412,084-byte / 10,302-record attendance table, the
 * same read repeated with only this class's writes toggled:
 *
 *   listener attached, writes disabled ... 6 of 6 transfers complete, ~1.5s each
 *   listener attached, writes enabled .... 3 of 6 complete, 20-47s each
 *
 * The write itself is not slow — instrumenting it showed 443ms of listener time
 * across a 46s read, worst single call 17ms. The damage is indirect: this protocol
 * has no flow control, so one badly-timed 10ms block during a burst overflows the
 * socket receive buffer, the reassembler then waits out its 8s silence timeout, and
 * BulkReader burns all five whole-request retries. A tenth of a second of writing
 * turns into a forty-second failure.
 *
 * Two consequences are baked into this class and must not be "optimised" away:
 *
 *  1. WRITES GO TO THE FILE CACHE, NOT THE DATABASE, while a read is in flight.
 *     SQLite has one writer and this app's queue, cache and sessions all live in
 *     it; a progress UPDATE can block on a lock held by an unrelated request. The
 *     run row is still the permanent record — it is written at phase boundaries,
 *     where no transfer is in flight and blocking is harmless.
 *
 *  2. WRITES ARE THROTTLED — roughly one per 0.75s, or on a 5% jump.
 *
 *     This was once 2s/20%, when a write could genuinely cost a sync. The ROOT
 *     CAUSE has since been fixed: the socket receive buffer was the OS default
 *     64KB against a 412KB table, so the kernel dropped the overflow whenever the
 *     reader paused. UdpTransport now requests 4MB and the whole transfer fits.
 *     Re-measured after that change, 25 of 26 reads completed — against roughly
 *     one failure in three before it.
 *
 *     With the danger gone the throttle exists only to keep the write rate sane,
 *     so it was loosened to something an operator can actually watch move. It is
 *     still a throttle: BulkReader emits per chunk when there is headroom, which
 *     is well over a thousand events on a full table.
 *
 * BulkReader adapts to the same fact: with buffer headroom it reports every chunk,
 * and without it (no ext-sockets) it falls back to reporting only after three
 * consecutive silent polls. See BulkReader::emit().
 */
class SyncProgressRecorder
{
    /** Never write more often than this, however many events arrive. */
    private const MIN_INTERVAL_SECONDS = 0.75;

    /** …unless the bar would jump by this much, which is worth a write of its own. */
    private const MIN_PERCENT_STEP = 5;

    /** How long a published live reading stays readable if a sync dies mid-read. */
    private const TTL_SECONDS = 900;

    private float $lastWriteAt = 0.0;

    private ?int $lastPercent = null;

    private int $lastAttempt = 1;

    public function __construct(private readonly AttendanceSyncRun $run) {}

    /**
     * Records one progress event, subject to the throttle.
     *
     * Safe to hand straight to BiometricDeviceDriver::readAttendance() as the
     * $onProgress callback.
     */
    public function __invoke(DeviceProgress $progress): void
    {
        if (! $this->shouldWrite($progress)) {
            return;
        }

        $this->write(
            phase: AttendanceSyncRun::PHASE_READING,
            read: $progress->read,
            total: $progress->total > 0 ? $progress->total : null,
            unit: $progress->unit,
            attempt: $progress->attempt,
        );

        $this->lastPercent = $progress->percent();
        $this->lastAttempt = $progress->attempt;
    }

    /**
     * Switches to the import phase.
     *
     * Called once the transfer is done and the records are being written. Without
     * it the bar reaches 100% and then sits there for the length of the insert,
     * which reads as a hang — the read is only half the wait on a large table.
     *
     * Forced through the throttle, because a phase change is exactly the kind of
     * event a human is waiting to see.
     */
    public function beginImport(int $totalRecords): void
    {
        $this->lastPercent = null;
        $this->lastWriteAt = 0.0;

        // durable: the transfer is over, so a database write cannot cost datagrams.
        $this->write(
            phase: AttendanceSyncRun::PHASE_IMPORTING,
            read: 0,
            total: $totalRecords,
            unit: DeviceProgress::UNIT_RECORDS,
            attempt: $this->lastAttempt,
            durable: true,
        );
    }

    /**
     * Import progress.
     *
     * Safe to write freely: there is no device transfer in flight by now, only
     * inserts, so a few extra UPDATEs cost nothing but time.
     */
    public function importedSoFar(int $records, int $totalRecords): void
    {
        $percent = $totalRecords > 0 ? (int) round($records / $totalRecords * 100) : null;

        if (! $this->intervalElapsed() && ! $this->percentJumped($percent)) {
            return;
        }

        $this->write(
            phase: AttendanceSyncRun::PHASE_IMPORTING,
            read: $records,
            total: $totalRecords,
            unit: DeviceProgress::UNIT_RECORDS,
            attempt: $this->lastAttempt,
        );

        $this->lastPercent = $percent;
    }

    /**
     * Clears the in-flight view once the run has finished.
     *
     * The final counters on the row are the authority from here on. Leaving a stale
     * "7,400 of 10,302" behind would make a finished run look like it is still going
     * to anything that reads the row later.
     */
    public function finish(): void
    {
        // Clear the row's in-flight columns first, then drop the published reading.
        // Order matters: write() republishes to the cache, so forgetting before it
        // would leave a phase-less entry behind that a poller reads as "running,
        // 0 of unknown".
        $this->write(phase: null, read: 0, total: null, unit: null, attempt: $this->lastAttempt, durable: true);

        Cache::store('file')->forget(self::cacheKey($this->run->device_id));
    }

    private function shouldWrite(DeviceProgress $progress): bool
    {
        // A retry restarts the byte count. Always record it: the bar is about to run
        // backwards and the operator deserves to know why rather than assume a bug.
        if ($progress->attempt !== $this->lastAttempt) {
            return true;
        }

        return $this->intervalElapsed() || $this->percentJumped($progress->percent());
    }

    private function intervalElapsed(): bool
    {
        return (microtime(true) - $this->lastWriteAt) >= self::MIN_INTERVAL_SECONDS;
    }

    private function percentJumped(?int $percent): bool
    {
        if ($percent === null) {
            return false;
        }

        return $this->lastPercent === null
            || abs($percent - $this->lastPercent) >= self::MIN_PERCENT_STEP;
    }

    /**
     * Publishes one reading.
     *
     * $durable = true also writes the run row. Only pass it at a phase boundary —
     * before the transfer, or once it is over — never from inside a read.
     */
    private function write(?string $phase, int $read, ?int $total, ?string $unit, int $attempt, bool $durable = false): void
    {
        $this->lastWriteAt = microtime(true);

        $payload = [
            'phase' => $phase,
            'read' => $read,
            'total' => $total,
            'unit' => $unit,
            'attempt' => $attempt,
            'updated_at' => now()->toIso8601String(),
        ];

        // The file store explicitly, not the default: cache.default is `database`
        // here, which would put this straight back into the SQLite file the class
        // docblock exists to keep it out of.
        Cache::store('file')->put(self::cacheKey($this->run->device_id), $payload + [
            'run_id' => $this->run->id,
        ], self::TTL_SECONDS);

        if (! $durable) {
            return;
        }

        // Query builder, not $run->update(): no model events, no observers, no audit
        // rows, and updated_at keeps meaning "the run changed".
        DB::table('attendance_sync_runs')
            ->where('id', $this->run->id)
            ->update([
                'progress_phase' => $phase,
                'progress_read' => $read,
                'progress_total' => $total,
                'progress_unit' => $unit,
                'progress_attempt' => $attempt,
                'progress_updated_at' => now(),
            ]);
    }

    /**
     * Where the live reading for a device is published.
     *
     * Keyed by DEVICE, not by run, because the polling endpoint knows the device
     * but cannot know the run id — the inline sync endpoint does not answer until
     * the work it would name is already finished.
     */
    public static function cacheKey(int $deviceId): string
    {
        return "device:{$deviceId}:sync-progress";
    }

    /**
     * The published reading for a device, or null if there is none.
     *
     * @return array<string,mixed>|null
     */
    public static function published(int $deviceId): ?array
    {
        $reading = Cache::store('file')->get(self::cacheKey($deviceId));

        // A reading with no phase is a finished run, not an in-flight one. Belt and
        // braces against a stale entry outliving the sync that wrote it.
        return ($reading['phase'] ?? null) === null ? null : $reading;
    }
}
