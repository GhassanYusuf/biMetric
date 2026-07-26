<?php

namespace App\Services\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\AttendanceLog;
use App\Models\AttendanceRawLog;
use App\Models\AttendanceSyncRun;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\EmployeeDeviceMap;
use Zkteco\Protocol\Capability\Capability;
use Zkteco\Protocol\Client\Device as ProtocolDevice;
use Zkteco\Protocol\Exception\DeviceException;

/**
 * Imports attendance from a terminal into attendance_logs.
 *
 * WHAT CHANGED AND WHY
 * --------------------
 * The previous implementation re-read the ENTIRE device table on every run and
 * deduped in PHP against every existing row — O(all logs) per sync, on a device
 * already holding 9,579 records. It recorded nothing about the run, preserved
 * nothing, and returned an integer to whoever triggered it.
 *
 * PROTOCOL LIMIT YOU CANNOT ENGINEER AROUND: the device cannot be asked for
 * "punches since X". ATTLOG_RRQ always returns the whole table — there is no
 * cursor, filter or pagination in this protocol. So "incremental" here means the
 * TRANSFER is still full, but the parse/dedupe/insert work is bounded by a
 * watermark. That saves the database, not the network. Anyone expecting a cheap
 * incremental read should use the realtime listener instead.
 *
 * Three guarantees this makes:
 *   1. Raw bytes are archived BEFORE parsing, so a decoder fix can be applied
 *      retroactively to punches a device has since rolled over.
 *   2. Undecodable records are counted and stored, never silently dropped.
 *   3. The unique-punch index remains the final dedupe authority, so a watermark
 *      bug can cause extra work but never duplicate attendance.
 */
class AttendanceSyncService
{
    public function __construct(private readonly BiometricDeviceDriver $driver)
    {
    }

    /**
     * Runs one sync.
     *
     * @param bool $full ignore the watermark and reconsider every record. Slower,
     *                   and the way to recover after a decoder fix.
     * @return array{message:string, raw:array<string,mixed>}
     */
    public function sync(Device $device, bool $full = false, ?DeviceCommand $command = null): array
    {
        $this->driver->capabilities($device)->require(Capability::ATTENDANCE_READ);

        $watermarkBefore = $full ? null : $device->attendance_watermark;

        $run = AttendanceSyncRun::create([
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'device_command_id' => $command?->id,
            'mode' => $full ? AttendanceSyncRun::MODE_FULL : AttendanceSyncRun::MODE_INCREMENTAL,
            'status' => AttendanceSyncRun::STATUS_RUNNING,
            'started_at' => now(),
            'watermark_before' => $watermarkBefore,
        ]);

        $startedAt = microtime(true);

        try {
            // Read raw first so the payload is preserved even if parsing explodes.
            // ONE session for the transfer; decoding is pure and needs no connection,
            // so it happens after the session closes rather than holding it open.
            $raw = $this->driver->run($device, fn (ProtocolDevice $d) => $d->attendance()->raw());

            $records = \Zkteco\Protocol\Codec\AttendanceCodec::decode(
                $raw,
                $device->timezone ?: config('attendance.device_timezone', '+03:00'),
            );

            $result = $this->ingest($device, $run, $records, $watermarkBefore);

            $newWatermark = $result['newest'] ?? $watermarkBefore;

            $device->forceFill([
                /*
                 * UTC, explicitly. Laravel does NOT convert timezones on save — it
                 * formats whatever wall-clock the value carries and assumes it is
                 * already in the app timezone.
                 *
                 * The watermark comes from a device timestamp carrying +03:00, so
                 * storing it unconverted put it 3 hours in the future. The next
                 * incremental sync would then skip three hours of real punches,
                 * silently, because everything below the watermark is considered
                 * already seen. Same class of bug as the bulk insert above.
                 */
                'attendance_watermark' => $newWatermark === null
                    ? null
                    : \Illuminate\Support\Carbon::instance($newWatermark)->utc(),
                'attendance_imported_total' => $device->attendance_imported_total + $result['imported'],
                'last_synced_at' => now(),
                'last_sync_status' => 'success',
                'last_sync_error' => null,
                'last_seen_at' => now(),
            ])->save();

            $run->forceFill([
                'status' => $result['undecodable'] > 0 || $result['unmatched'] > 0
                    ? AttendanceSyncRun::STATUS_PARTIAL
                    : AttendanceSyncRun::STATUS_SUCCEEDED,
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'records_read' => $result['read'],
                'records_imported' => $result['imported'],
                'records_duplicate' => $result['duplicate'],
                'records_undecodable' => $result['undecodable'],
                'records_unmatched' => $result['unmatched'],
                'watermark_after' => $newWatermark === null
                    ? null
                    : \Illuminate\Support\Carbon::instance($newWatermark)->utc(),
                'message' => $this->summarise($result),
            ])->save();

            return [
                'message' => $this->summarise($result),
                'raw' => [
                    'run_id' => $run->id,
                    'mode' => $run->mode,
                    'counts' => $result,
                    'watermark_before' => $watermarkBefore?->format('c'),
                    'watermark_after' => $newWatermark?->format('c'),
                ],
            ];
        } catch (DeviceException $e) {
            $run->forceFill([
                'status' => AttendanceSyncRun::STATUS_FAILED,
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'message' => $e->getMessage(),
                'error' => ['exception' => $e::class, 'context' => $e->context()],
            ])->save();

            $device->forceFill([
                'last_sync_status' => 'error',
                'last_sync_error' => $e->getMessage(),
            ])->save();

            throw $e;
        }
    }

    /**
     * Stores raw records, then imports the ones that qualify.
     *
     * @param list<array<string,mixed>> $records
     * @return array{read:int,imported:int,duplicate:int,undecodable:int,unmatched:int,newest:?\Illuminate\Support\Carbon}
     */
    private function ingest(Device $device, AttendanceSyncRun $run, array $records, ?\DateTimeInterface $watermark): array
    {
        $counts = ['read' => count($records), 'imported' => 0, 'duplicate' => 0, 'undecodable' => 0, 'unmatched' => 0];
        $newest = null;

        // One query for the device's mappings instead of one per record.
        $employeeByDeviceUserId = EmployeeDeviceMap::query()
            ->where('device_id', $device->id)
            ->pluck('employee_id', 'device_user_id')
            ->all();

        $rawRows = [];
        $candidates = [];

        foreach ($records as $record) {
            // --- Undecodable: archive it, count it, never drop it silently.
            if (! isset($record['timestamp'])) {
                $counts['undecodable']++;
                $rawRows[] = $this->rawRow(
                    $device,
                    $run,
                    $record['raw'] ?? '',
                    candidate: null,
                    decoded: false,
                    imported: false,
                    skipReason: AttendanceRawLog::SKIP_UNDECODABLE,
                );

                continue;
            }

            $punchedAt = $record['timestamp'];
            $deviceUserId = (string) ($record['user_id'] ?? '');

            if ($newest === null || $punchedAt > $newest) {
                $newest = $punchedAt;
            }

            // --- Below the watermark: already considered on a previous run.
            if ($watermark !== null && $punchedAt <= $watermark) {
                continue; // not archived again — it was archived when first seen
            }

            $employeeId = $employeeByDeviceUserId[$deviceUserId] ?? null;

            if ($employeeId === null) {
                $counts['unmatched']++;
            }

            $candidates[] = [
                'device_user_id' => $deviceUserId,
                'employee_id' => $employeeId,
                'punched_at' => $punchedAt,
                'verify_method' => (int) ($record['verify_method'] ?? 0),
                'status' => (int) ($record['status'] ?? 0),
                'raw' => $record['raw'] ?? null,
            ];
        }

        /*
         * --- Bulk insert, letting the unique index be the dedupe authority.
         *
         * insertOrIgnore in chunks, NOT one transaction per record. The first version
         * wrapped every punch in its own DB::transaction, which on a device holding
         * 9,579 records meant thousands of transactions and a sync that outlived its
         * own worker timeout. One statement per 500 rows does the same job.
         *
         * The unique index (device_id, device_user_id, punched_at, verify_method) is
         * what makes this safe: it cannot be raced by two concurrent syncs the way a
         * SELECT-then-INSERT can, and a watermark bug can therefore cause extra work
         * but never duplicate attendance.
         */
        $before = AttendanceLog::query()->where('device_id', $device->id)->count();

        foreach (array_chunk($candidates, 500) as $chunk) {
            AttendanceLog::insertOrIgnore(array_map(fn (array $c) => [
                'tenant_id' => $device->tenant_id,
                'device_id' => $device->id,
                'device_user_id' => $c['device_user_id'],
                'employee_id' => $c['employee_id'],
                /*
                 * CONVERTED TO UTC EXPLICITLY — do not remove.
                 *
                 * insertOrIgnore goes through the query builder, which BYPASSES
                 * Eloquent's datetime cast. The decoded value carries the device's
                 * local offset (+03:00), and without this conversion the builder
                 * writes that local wall-clock string into a column the rest of the
                 * app reads as UTC.
                 *
                 * CONFIRMED: the first version of this bulk insert stored every punch
                 * 3 hours ahead, producing 6,432 rows that duplicated existing ones at
                 * a constant offset. A save() through the model would have converted
                 * for us; a bulk insert must do it here.
                 */
                'punched_at' => \Illuminate\Support\Carbon::instance($c['punched_at'])
                    ->utc()
                    ->format('Y-m-d H:i:s'),
                'verify_method' => $c['verify_method'],
                'status' => $c['status'],
                'created_at' => now(),
                'updated_at' => now(),
            ], $chunk));
        }

        $counts['imported'] = AttendanceLog::query()->where('device_id', $device->id)->count() - $before;
        $counts['duplicate'] = count($candidates) - $counts['imported'];

        /*
         * Archive every candidate. imported/skip_reason is decided per row from the
         * aggregate outcome rather than per insert, because insertOrIgnore does not
         * report which rows it skipped — the alternative would be re-querying each
         * one, which is the per-record cost this rewrite exists to remove.
         */
        $allDuplicates = $counts['imported'] === 0;

        foreach ($candidates as $candidate) {
            $rawRows[] = $this->rawRow(
                $device,
                $run,
                $candidate['raw'],
                candidate: $candidate,
                decoded: true,
                imported: ! $allDuplicates,
                skipReason: $allDuplicates ? AttendanceRawLog::SKIP_DUPLICATE : null,
            );
        }

        /*
         * NO per-punch event here, deliberately.
         *
         * PunchRecorded exists to push a LIVE punch to the UI. Firing it for a
         * historical backfill would flood MQTT with thousands of "someone just
         * clocked in" messages for punches that happened days ago. Immediacy is the
         * realtime listener's job; this is an importer.
         */

        // Chunked so a 9,000-record device does not build one enormous insert.
        foreach (array_chunk($rawRows, 500) as $chunk) {
            AttendanceRawLog::insert($chunk);
        }

        return $counts + ['newest' => $newest];
    }

    /**
     * @param array<string,mixed>|null $candidate
     * @return array<string,mixed>
     */
    private function rawRow(
        Device $device,
        AttendanceSyncRun $run,
        ?string $raw,
        ?array $candidate,
        bool $decoded,
        bool $imported,
        ?string $skipReason,
    ): array {
        return [
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'attendance_sync_run_id' => $run->id,
            'raw' => $raw ?? '',
            'device_user_id' => $candidate['device_user_id'] ?? null,
            // Same UTC conversion as the punch insert: insert() bypasses casts too.
            'punched_at' => isset($candidate['punched_at'])
                ? \Illuminate\Support\Carbon::instance($candidate['punched_at'])->utc()->format('Y-m-d H:i:s')
                : null,
            'verify_method' => $candidate['verify_method'] ?? null,
            'status' => $candidate['status'] ?? null,
            'decoded' => $decoded,
            'imported' => $imported,
            'skip_reason' => $skipReason,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @param array<string,mixed> $counts */
    private function summarise(array $counts): string
    {
        $parts = ["read {$counts['read']}", "imported {$counts['imported']}"];

        if ($counts['duplicate'] > 0) {
            $parts[] = "{$counts['duplicate']} already known";
        }
        if ($counts['unmatched'] > 0) {
            $parts[] = "{$counts['unmatched']} not matched to an employee";
        }
        if ($counts['undecodable'] > 0) {
            $parts[] = "{$counts['undecodable']} could not be decoded and were archived";
        }

        return 'Sync complete: ' . implode(', ', $parts) . '.';
    }
}
