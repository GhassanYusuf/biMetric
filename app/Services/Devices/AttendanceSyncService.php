<?php

namespace App\Services\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Events\PunchRecorded;
use App\Models\AttendanceLog;
use App\Models\AttendanceRawLog;
use App\Models\AttendanceSyncRun;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\EmployeeDeviceMap;
use Illuminate\Support\Carbon;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Exception\DeviceException;

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
 * The same limit is why a DATE RANGE is a client-side window and not a device
 * query: asking for August still transfers every record the terminal holds. What a
 * range buys is not a faster read but a bounded IMPORT — a month re-imported after
 * a decoder fix, or a backfill of the gap a dead worker left, without reconsidering
 * 90,000 rows and without disturbing where ordinary syncing is up to.
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
    public function __construct(private readonly BiometricDeviceDriver $driver) {}

    /**
     * Runs one sync.
     *
     * @param  bool  $full  ignore the watermark and reconsider every record. Slower,
     *                      and the way to recover after a decoder fix.
     * @param  ?string  $from  first device-local DATE to import (Y-m-d), inclusive.
     * @param  ?string  $to  last device-local DATE to import (Y-m-d), inclusive.
     * @return array{message:string, raw:array<string,mixed>}
     */
    public function sync(
        Device $device,
        bool $full = false,
        ?DeviceCommand $command = null,
        ?string $from = null,
        ?string $to = null,
    ): array {
        $this->driver->capabilities($device)->require(Capability::ATTENDANCE_READ);

        [$rangeFrom, $rangeTo] = $this->resolveRange($device, $from, $to);
        $ranged = $rangeFrom !== null || $rangeTo !== null;

        /*
         * A range REPLACES the watermark rather than narrowing it.
         *
         * Keeping both would make the commonest request impossible: the window an
         * operator asks for is almost always one the watermark has already passed —
         * a month that imported wrongly, or a gap left while the worker was down —
         * and a range read that still refused everything below the watermark would
         * report "read 9,579, imported 0" and look broken.
         */
        $watermarkBefore = ($full || $ranged) ? null : $device->attendance_watermark;

        $run = AttendanceSyncRun::create([
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'device_command_id' => $command?->id,
            'mode' => match (true) {
                $ranged => AttendanceSyncRun::MODE_RANGE,
                $full => AttendanceSyncRun::MODE_FULL,
                default => AttendanceSyncRun::MODE_INCREMENTAL,
            },
            'status' => AttendanceSyncRun::STATUS_RUNNING,
            'started_at' => now(),
            'watermark_before' => $watermarkBefore,
            'range_from' => $rangeFrom,
            'range_to' => $rangeTo,
        ]);

        $startedAt = microtime(true);

        try {
            /*
             * Transfer and decode belong to the driver, because both are
             * vendor-specific: a ZKTeco terminal streams a binary table over UDP, a
             * CSL terminal returns tab-separated text from a web form. Everything
             * below this line — watermarks, dedupe, archiving, counting — is
             * identical for both, which is exactly why the seam is here.
             *
             * Each record carries its own raw payload, so the archive still holds
             * what the device said even when parsing produced nothing usable.
             */
            /*
             * The progress recorder is created per run and passed BOTH to the
             * driver (which reports the transfer) and to ingest() (which reports
             * the writes). One object so the throttle is shared: two independent
             * throttles would let the phase change and the first import tick land
             * as two writes in the same millisecond.
             */
            $progress = new SyncProgressRecorder($run);

            $records = $this->driver->readAttendance($device, $progress);

            $result = $this->ingest($device, $run, $records, $watermarkBefore, $progress, $rangeFrom, $rangeTo);

            // The final counters on the row are the authority from here on; a stale
            // in-flight reading would make a finished run look like it is still going.
            $progress->finish();

            /*
             * A RANGE READ MUST NOT MOVE THE WATERMARK.
             *
             * The watermark means "everything up to here has been considered by
             * ordinary syncing". A backfill of last March says nothing about that,
             * and writing March's newest punch into it would tell the next
             * incremental sync to reconsider six months — while a window ending
             * today would push it past punches that were never looked at, and those
             * punches would then be skipped forever. Leaving it alone is the only
             * option that can cost extra work but never loses a punch.
             */
            $newWatermark = $ranged
                ? $device->attendance_watermark
                : ($result['newest'] ?? $watermarkBefore);

            $updates = [
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
                    : Carbon::instance($newWatermark)->utc(),
                'attendance_imported_total' => $device->attendance_imported_total + $result['imported'],
                'last_synced_at' => now(),
                'last_sync_status' => 'success',
                'last_sync_error' => null,
                'last_seen_at' => now(),
            ];

            if ($ranged) {
                unset($updates['attendance_watermark']);
            }

            $device->forceFill($updates)->save();

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
                'records_out_of_range' => $result['out_of_range'],
                'watermark_after' => $newWatermark === null
                    ? null
                    : Carbon::instance($newWatermark)->utc(),
                'message' => $this->summarise($result, $rangeFrom, $rangeTo),
            ])->save();

            return [
                'message' => $this->summarise($result, $rangeFrom, $rangeTo),
                'raw' => [
                    'run_id' => $run->id,
                    'mode' => $run->mode,
                    'counts' => $result,
                    'watermark_before' => $watermarkBefore?->format('c'),
                    'watermark_after' => $newWatermark?->format('c'),
                    'range_from' => $rangeFrom?->format('Y-m-d'),
                    'range_to' => $rangeTo?->format('Y-m-d'),
                ],
            ];
        } catch (DeviceException $e) {
            // Clear the in-flight view: a failed run that still reads "6,200 of
            // 10,302" looks like it is mid-transfer forever.
            (new SyncProgressRecorder($run))->finish();

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
     * Stores ONE punch that arrived on the device's realtime event stream.
     *
     * The counterpart to ingest(): same dedupe authority, same employee mapping,
     * same UTC rule — but one record at a time, and it DOES fire PunchRecorded,
     * because here the punch genuinely just happened. That event is the whole point
     * of the listener; the bulk importer deliberately stays silent (see ingest()).
     *
     * Returns null when the push could not be decoded, and the already-stored log
     * when the punch was a duplicate — a later bulk sync re-reading the same record
     * must not produce a second row. Callers can tell the two apart with
     * wasRecentlyCreated.
     *
     * @param  array<string,mixed>  $record  as decoded by AttendanceCodec::decodeRealtime
     */
    public function recordRealtimePunch(Device $device, array $record): ?AttendanceLog
    {
        if (! isset($record['timestamp'])) {
            return null; // undecodable push — never guess at a punch time
        }

        $deviceUserId = (string) ($record['user_id'] ?? '');

        if ($deviceUserId === '') {
            return null;
        }

        /*
         * Employee resolution goes through EmployeeDeviceMap, NOT employee_code.
         *
         * The device's user id is only equal to the payroll code when nobody has
         * ever been re-enrolled under a different slot. The map is the authority
         * everywhere else in this service, and a realtime punch that attributed
         * itself differently from the bulk import of the SAME record would produce
         * two versions of one person's day.
         */
        $employeeId = EmployeeDeviceMap::query()
            ->inForceAt($device->id, $deviceUserId, $record['timestamp'])
            ->value('employee_id');

        /*
         * Converted to UTC explicitly, for the same reason the bulk insert does it:
         * the decoded value carries the device's local offset. firstOrCreate binds
         * this value into a WHERE clause, and a local-time binding would fail to
         * match the UTC row a bulk sync already wrote — producing the duplicate at a
         * constant offset that rule 10 exists to prevent.
         */
        $punchedAtUtc = Carbon::instance($record['timestamp'])
            ->utc()
            ->format('Y-m-d H:i:s');

        $log = AttendanceLog::query()->firstOrCreate(
            [
                'device_id' => $device->id,
                'device_user_id' => $deviceUserId,
                'punched_at' => $punchedAtUtc,
                'verify_method' => (int) ($record['verify_method'] ?? 0),
            ],
            [
                'tenant_id' => $device->tenant_id,
                'employee_id' => $employeeId,
                'status' => (int) ($record['status'] ?? 0),
            ],
        );

        // Raw bytes preserved for a live punch too, so a decoder fix can be replayed
        // over them exactly as it can over a bulk import. No run id: there is no run.
        AttendanceRawLog::insert($this->rawRow(
            $device,
            run: null,
            raw: $record['raw'] ?? null,
            candidate: [
                'device_user_id' => $deviceUserId,
                'punched_at' => $record['timestamp'],
                'verify_method' => (int) ($record['verify_method'] ?? 0),
                'status' => (int) ($record['status'] ?? 0),
            ],
            decoded: true,
            imported: $log->wasRecentlyCreated,
            skipReason: $log->wasRecentlyCreated ? null : AttendanceRawLog::SKIP_DUPLICATE,
        ));

        if ($log->wasRecentlyCreated) {
            $log->load(['employee', 'device']);
            event(new PunchRecorded($log));
        }

        return $log;
    }

    /**
     * Stores raw records, then imports the ones that qualify.
     *
     * @param  list<array<string,mixed>>  $records
     * @param  ?\DateTimeInterface  $rangeFrom  earliest instant to import, or null for no lower bound
     * @param  ?\DateTimeInterface  $rangeTo  latest instant to import, or null for no upper bound
     * @return array{read:int,imported:int,duplicate:int,undecodable:int,unmatched:int,out_of_range:int,newest:?Carbon}
     */
    private function ingest(
        Device $device,
        AttendanceSyncRun $run,
        array $records,
        ?\DateTimeInterface $watermark,
        ?SyncProgressRecorder $progress = null,
        ?\DateTimeInterface $rangeFrom = null,
        ?\DateTimeInterface $rangeTo = null,
    ): array {
        $counts = ['read' => count($records), 'imported' => 0, 'duplicate' => 0, 'undecodable' => 0, 'unmatched' => 0, 'out_of_range' => 0];
        $newest = null;

        /*
         * One query for the device's mappings, INCLUDING closed ones.
         *
         * A punch resolves to whoever held that PIN AT THE TIME IT WAS MADE, not to
         * whoever holds it now. Terminals reassign a departed employee's number to a
         * new hire, and taking only the live mapping would silently re-credit the
         * leaver's entire history to the joiner — a payroll error with no symptom.
         *
         * Resolved in PHP rather than per-record SQL: a 90,000-record table would
         * otherwise mean 90,000 queries.
         */
        $mappingsByPin = $this->mappingsByPin($device);

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

            /*
             * --- Outside the requested window: counted, not imported.
             *
             * The comparison is between two instants, so the device's +03:00 offset
             * and the bound's device-local midnight line up without either being
             * normalised first — which is the whole reason the bounds are built in
             * the device's timezone rather than the app's.
             *
             * Deliberately NOT archived to attendance_raw_logs. A range read is a
             * targeted import, and archiving the rest would write the terminal's
             * entire table as raw rows on every run; a full re-read is the thing
             * that archives everything.
             */
            if (($rangeFrom !== null && $punchedAt < $rangeFrom) || ($rangeTo !== null && $punchedAt > $rangeTo)) {
                $counts['out_of_range']++;

                continue;
            }

            // --- Below the watermark: already considered on a previous run.
            if ($watermark !== null && $punchedAt <= $watermark) {
                continue; // not archived again — it was archived when first seen
            }

            $employeeId = $this->employeeAt($mappingsByPin, $deviceUserId, $punchedAt);

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

        // The read is only half the wait on a large table. Without this the bar
        // would hit 100% and then sit there for the whole insert, which reads as a
        // hang rather than as work.
        $written = 0;

        /*
         * SQL SERVER (since 2026-09-21). Two things the SQLite-era code relied on do
         * not exist there, and every sync failed on the first of them until
         * 2026-09-23:
         *
         *   - insertOrIgnore: Laravel has no SQL Server grammar for it ("This database
         *     engine does not support inserting while ignoring errors"). Replaced by
         *     upsert() on the SAME unique index, whose only "update" is a key column
         *     set to itself — so an existing punch is matched and left exactly as it
         *     was (employee attribution included), and a new one is inserted. Still
         *     the index, not a SELECT, that decides what is a duplicate.
         *   - 2,100 parameters per statement: 500 rows x 9 columns is 4,500. Chunks
         *     are sized from the column count instead (see paramSafeChunk()).
         *
         * MERGE also refuses a source holding the same key twice, which
         * insertOrIgnore used to absorb silently — so the rows are de-duplicated on
         * that key first. The archive below still records EVERY candidate.
         */
        $rows = [];
        foreach ($candidates as $c) {
            $row = [
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
                'punched_at' => Carbon::instance($c['punched_at'])
                    ->utc()
                    ->format('Y-m-d H:i:s'),
                'verify_method' => $c['verify_method'],
                'status' => $c['status'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $rows[$row['device_user_id'].'|'.$row['punched_at'].'|'.$row['verify_method']] ??= $row;
        }
        $rows = array_values($rows);

        // Counted after de-duplication, so the bar reaches 100% on the last chunk.
        $progress?->beginImport(count($rows));

        foreach (array_chunk($rows, self::paramSafeChunk($rows)) as $chunk) {
            AttendanceLog::query()->toBase()->upsert(
                $chunk,
                ['device_id', 'device_user_id', 'punched_at', 'verify_method'],
                ['device_user_id'], // a key column set to itself: matched rows are untouched
            );

            $written += count($chunk);
            $progress?->importedSoFar($written, count($rows));
        }

        $counts['imported'] = AttendanceLog::query()->where('device_id', $device->id)->count() - $before;
        $counts['duplicate'] = count($candidates) - $counts['imported'];

        /*
         * Archive every candidate. imported/skip_reason is decided per row from the
         * aggregate outcome rather than per insert, because the bulk upsert does not
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
        foreach (array_chunk($rawRows, self::paramSafeChunk($rawRows)) as $chunk) {
            AttendanceRawLog::insert($chunk);
        }

        return $counts + ['newest' => $newest];
    }

    /**
     * @param  array<string,mixed>|null  $candidate
     * @return array<string,mixed>
     */
    /**
     * Every mapping this device has ever had, grouped by PIN, newest window first.
     *
     * @return array<string,list<array{employee_id:int,from:?int,to:?int}>>
     */
    private function mappingsByPin(Device $device): array
    {
        $grouped = [];

        $rows = EmployeeDeviceMap::query()
            ->where('device_id', $device->id)
            ->get(['employee_id', 'device_user_id', 'valid_from', 'valid_to']);

        foreach ($rows as $row) {
            $grouped[(string) $row->device_user_id][] = [
                'employee_id' => $row->employee_id,
                // Null bounds mean "unbounded on that side". A mapping adopted from a
                // terminal that predates this system has no known start — the person
                // was already enrolled — so it must match punches older than the row.
                'from' => $row->valid_from?->getTimestamp(),
                'to' => $row->valid_to?->getTimestamp(),
            ];
        }

        return $grouped;
    }

    /**
     * Who held this PIN when the punch was made.
     *
     * Returns null when nobody did — a PIN deleted from the device before we ever read
     * it, or one still awaiting a claim. Null is honest: the punch is stored with its
     * raw device_user_id and counted as unmatched, so it can be attributed later
     * without re-reading the terminal (which by then may have rolled the record off).
     *
     * @param  array<string,list<array{employee_id:int,from:?int,to:?int}>>  $mappingsByPin
     */
    private function employeeAt(array $mappingsByPin, string $devicePin, \DateTimeInterface $punchedAt): ?int
    {
        $at = $punchedAt->getTimestamp();

        foreach ($mappingsByPin[$devicePin] ?? [] as $window) {
            if ($window['from'] !== null && $at < $window['from']) {
                continue;
            }

            if ($window['to'] !== null && $at >= $window['to']) {
                continue;
            }

            return $window['employee_id'];
        }

        return null;
    }

    /**
     * Rows per statement that stay under SQL Server's 2,100-parameter limit, with
     * headroom. Derived from the row's width so a column added later cannot quietly
     * push a chunk over it. Capped at 500, the size used before SQL Server.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function paramSafeChunk(array $rows): int
    {
        $columns = max(1, count($rows[0] ?? []));

        return max(1, min(500, intdiv(2000, $columns)));
    }

    private function rawRow(
        Device $device,
        ?AttendanceSyncRun $run,
        ?string $raw,
        ?array $candidate,
        bool $decoded,
        bool $imported,
        ?string $skipReason,
    ): array {
        return [
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            // Null for a realtime punch: it belongs to no sync run. The column is
            // nullable precisely so the live path can archive raw bytes too.
            'attendance_sync_run_id' => $run?->id,
            'raw' => $raw ?? '',
            'device_user_id' => $candidate['device_user_id'] ?? null,
            // Same UTC conversion as the punch insert: insert() bypasses casts too.
            'punched_at' => isset($candidate['punched_at'])
                ? Carbon::instance($candidate['punched_at'])->utc()->format('Y-m-d H:i:s')
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
    private function summarise(array $counts, ?\DateTimeInterface $rangeFrom = null, ?\DateTimeInterface $rangeTo = null): string
    {
        $parts = ["read {$counts['read']}", "imported {$counts['imported']}"];

        /*
         * The window is named in the message, not only in the columns. "read 9,579,
         * imported 640" on its own reads as a failure; "read 9,579, 8,912 outside
         * 2026-08-01…2026-08-31" is the same run explaining itself to whoever finds
         * it in the history a month later.
         */
        if (($counts['out_of_range'] ?? 0) > 0) {
            $parts[] = "{$counts['out_of_range']} outside ".$this->describeRange($rangeFrom, $rangeTo);
        }
        if ($counts['duplicate'] > 0) {
            $parts[] = "{$counts['duplicate']} already known";
        }
        if ($counts['unmatched'] > 0) {
            $parts[] = "{$counts['unmatched']} not matched to an employee";
        }
        if ($counts['undecodable'] > 0) {
            $parts[] = "{$counts['undecodable']} could not be decoded and were archived";
        }

        return 'Sync complete: '.implode(', ', $parts).'.';
    }

    /**
     * Turns two operator-supplied dates into instants in the DEVICE's day.
     *
     * The dates mean what the terminal's own clock means by them. Its punches are
     * stamped in local time (+03:00 here), so a window built in the app timezone
     * would be three hours out at both ends and would quietly move one evening's
     * punches into the wrong day — the same offset class of bug rule 10 exists for.
     *
     * Either bound may be null: "everything from 1 August onwards" and "everything
     * up to 31 March" are both legitimate requests, and only both being null means
     * "no window at all".
     *
     * @return array{0:?Carbon,1:?Carbon}
     */
    private function resolveRange(Device $device, ?string $from, ?string $to): array
    {
        if ($from === null && $to === null) {
            return [null, null];
        }

        $timezone = $device->timezone ?: config('attendance.device_timezone', '+03:00');

        return [
            // Inclusive at both ends: a day named is a day imported, all 24 hours
            // of it. An operator asking for "the 31st" means the punches made ON
            // the 31st, not the ones made before 00:00 on it.
            $from === null ? null : Carbon::parse($from, $timezone)->startOfDay(),
            $to === null ? null : Carbon::parse($to, $timezone)->endOfDay(),
        ];
    }

    /** "2026-08-01…2026-08-31", or an open-ended half of one. */
    private function describeRange(?\DateTimeInterface $from, ?\DateTimeInterface $to): string
    {
        if ($from !== null && $to !== null) {
            return $from->format('Y-m-d').'…'.$to->format('Y-m-d');
        }

        return $from !== null
            ? 'dates from '.$from->format('Y-m-d')
            : 'dates up to '.($to?->format('Y-m-d') ?? '');
    }
}
