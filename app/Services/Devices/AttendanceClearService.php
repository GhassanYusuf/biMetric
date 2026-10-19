<?php

namespace App\Services\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Support\Facades\Log;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Exception\ProtocolException;

/**
 * Erases a terminal's punch log once every punch on it is safely in the system
 * (owner, 2026-10-18: "trigger a wipe of the punch data stored in the device, but
 * don't delete the employees — only their punch data"). Users, cards and fingerprints
 * on the terminal are not touched.
 *
 * WHY: ZKTeco terminals cannot be asked for "punches since X" — every read transfers
 * the whole log. Camelot (F18, over the internet) holds 42,352 records, takes ~5
 * minutes, and the larger the transfer the more of it arrives misaligned (DC-002,
 * DC-020). A short log is a fast, clean read.
 *
 * SAVE BEFORE YOU DESTROY, OR REFUSE (rule 19, applied to punches). In order:
 *   1. The terminal's own count (GET_FREE_SIZES) — unknown means refuse.
 *   2. A full read through AttendanceSyncService, which stores every record (the
 *      unique index dedupes). Refused unless it read EXACTLY the terminal's count, and
 *      every record decoded and was stored. Count alone is not enough: Camelot's
 *      2026-10-18 read matched its count and still had 17,203 misaligned records.
 *   3. The clear, in one session with a re-check of the count (the driver refuses
 *      if a punch arrived since step 2), then the count again, which must be 0.
 * A punch that arrives mid-way makes the counts differ, and the whole thing is run
 * again from step 1 — up to three times.
 *
 * The watermark is reset afterwards: every record on the terminal is new from then
 * on, so the next read considers all of them rather than trusting a high-water mark
 * a wrongly-set clock could put punches below.
 *
 * IMPLEMENTED 2026-10-18, never run on hardware; the first run on each model is a
 * write probe the owner authorises (rule 16). Only ZKTeco standalone terminals offer
 * it — attendance.clear is NOT_IMPLEMENTED on C3 and CSL.
 */
class AttendanceClearService
{
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly BiometricDeviceDriver $driver,
        private readonly AttendanceSyncService $attendance,
    ) {}

    /**
     * @return array{message:string, raw:array<string,mixed>}
     */
    public function clear(Device $device, ?DeviceCommand $command = null): array
    {
        $this->driver->capabilities($device)->require(Capability::ATTENDANCE_CLEAR);

        $reads = [];

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $held = $this->driver->attendanceRecordCount($device);

            if ($held === null) {
                throw new ProtocolException(
                    "{$device->name} did not say how many punches it holds, so it cannot be shown that all of them were saved. Nothing was cleared."
                );
            }

            if ($held === 0) {
                return [
                    'message' => "{$device->name} holds no punches. Nothing to clear.",
                    'raw' => ['held' => 0, 'cleared' => 0, 'reads' => $reads],
                ];
            }

            // Full on the first pass: everything, regardless of the watermark. A
            // re-pass only has to pick up what arrived since.
            $sync = $this->attendance->sync($device, full: $attempt === 1, command: $command);
            $counts = $sync['raw']['counts'];
            $reads[] = ['held' => $held] + array_intersect_key($counts, array_flip(['read', 'imported', 'duplicate', 'undecodable', 'future']));

            if ($counts['read'] !== $held) {
                // A punch landed during the transfer, or the transfer lost records.
                // Either way, read again before deciding anything.
                continue;
            }

            $unusable = $counts['undecodable'] + $counts['future'];
            if ($unusable > 0) {
                throw new ProtocolException(sprintf(
                    '%s holds %s punches but %s of them could not be read correctly, so they are not all saved. Nothing was cleared. '
                    .'They are still on the terminal; try again later, or ask for a slower read.',
                    $device->name,
                    number_format($held),
                    number_format($unusable),
                ));
            }

            if ($counts['imported'] + $counts['duplicate'] !== $held) {
                throw new ProtocolException(sprintf(
                    '%s holds %s punches but only %s are stored in the system. Nothing was cleared.',
                    $device->name,
                    number_format($held),
                    number_format($counts['imported'] + $counts['duplicate']),
                ));
            }

            // A punch made since the read: read again. The driver re-checks this in the
            // same session as the clear, and refuses outright if one lands in between.
            if ($this->driver->attendanceRecordCount($device) !== $held) {
                continue;
            }

            $cleared = $this->driver->clearAttendance($device, $held);

            $device->forceFill(['attendance_watermark' => null])->save();

            Log::channel('device')->info('Attendance log cleared', [
                'device_id' => $device->id,
                'device_name' => $device->name,
                'cleared' => $cleared,
                'command_id' => $command?->id,
                'reads' => $reads,
            ]);

            return [
                'message' => sprintf(
                    'Cleared %s punches from %s after saving every one of them (%s new). Employees, cards and fingerprints were not touched.',
                    number_format($cleared),
                    $device->name,
                    number_format($counts['imported']),
                ),
                'raw' => ['held' => $held, 'cleared' => $cleared, 'reads' => $reads],
            ];
        }

        throw new ProtocolException(
            "{$device->name} kept receiving punches while it was being read, so the count never settled. Nothing was cleared; try again at a quieter time."
        );
    }
}
