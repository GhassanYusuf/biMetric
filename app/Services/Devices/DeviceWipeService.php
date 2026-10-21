<?php

namespace App\Services\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Support\Facades\Log;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Exception\ProtocolException;
use Promoseven\Biometric\Exception\VerificationException;

/**
 * "Wipe data from terminal" (owner, 2026-10-21). Leaves the terminal holding no users
 * and no punches. CLAUDE.md rule 22 records it as the one exception to rule 19, for
 * this feature only, in the owner's words: unlinked users are deleted with nothing
 * kept, and punches are erased unsaved when the operator says not to save them.
 *
 * In order — every irreversible step comes after every check that can refuse:
 *   1. DeviceUserSyncService::wipeUsers() saves and CHECKS the linked employees' cards
 *      and fingers in the system, or refuses with nothing changed.
 *   2. Punches, inside wipeUsers()' $beforeDelete:
 *        save   → AttendanceClearService::clear(): every punch read and stored, then
 *                 cleared — or it refuses, and nothing is wiped;
 *        erase  → cleared WITHOUT reading them into the system (owner's choice).
 *   3. Every user deleted, the empty table read back (deleteAllUsers).
 *   4. The terminal read back once more: 0 users and 0 punches, or VerificationException.
 *
 * Queued as WIPE_DEVICE, one attempt (rule 11): a refusal is a decision for a person.
 * F18 only (device.wipe IMPLEMENTED there, UNKNOWN on the F22, NOT_IMPLEMENTED on C3
 * and CSL); first run on the test F18 "IT-Office".
 */
class DeviceWipeService
{
    private const ERASE_ATTEMPTS = 3;

    public function __construct(
        private readonly BiometricDeviceDriver $driver,
        private readonly DeviceUserSyncService $users,
        private readonly AttendanceClearService $clear,
    ) {}

    /**
     * @return array{message:string, raw:array<string,mixed>}
     */
    public function wipe(Device $device, bool $savePunches, ?DeviceCommand $command = null): array
    {
        $capabilities = $this->driver->capabilities($device);
        $capabilities->require(Capability::DEVICE_WIPE);
        $capabilities->require(Capability::ATTENDANCE_CLEAR);

        $punches = null;
        $users = $this->users->wipeUsers($device, function () use ($device, $savePunches, $command, &$punches) {
            $punches = $savePunches
                ? $this->clear->clear($device, $command)['raw']
                : $this->eraseWithoutSaving($device);
        });

        $usersLeft = count($this->driver->readUsers($device, false));
        $punchesLeft = $this->driver->attendanceRecordCount($device);
        if ($usersLeft !== 0 || $punchesLeft !== 0) {
            throw new VerificationException(sprintf(
                '%s accepted the wipe but still holds %d user(s) and %s punch(es) when read back.',
                $device->name,
                $usersLeft,
                $punchesLeft === null ? 'an unknown number of' : number_format($punchesLeft),
            ));
        }

        $device->forceFill(['attendance_watermark' => null])->save();

        $raw = ['users' => $users, 'punches' => $punches, 'save_punches' => $savePunches];
        AuditLog::create([
            'tenant_id' => $device->tenant_id,
            'user_id' => $command?->requested_by,
            'action' => 'device.wiped',
            'auditable_type' => Device::class,
            'auditable_id' => $device->id,
            'changes' => ['device' => $device->name] + $raw,
            'ip_address' => null,
            'user_agent' => null,
        ]);
        Log::channel('device')->info('Device wiped', ['device_id' => $device->id, 'command_id' => $command?->id] + $raw);

        $cleared = (int) ($punches['cleared'] ?? 0);

        return [
            'message' => sprintf(
                '%s wiped: %d user(s) removed (%d linked to employees, their cards and fingerprints kept in the system; %d unlinked, not kept), and %s punch(es) %s. The terminal now holds nobody and no punches.',
                $device->name,
                $users['removed'],
                $users['linked'],
                $users['unlinked'],
                number_format($cleared),
                $savePunches ? 'saved to the system and cleared' : 'erased without saving',
            ),
            'raw' => $raw,
        ];
    }

    /**
     * Clears the punch log WITHOUT reading it into the system — the operator chose not
     * to save (rule 22). The driver still re-checks the count in the same session as
     * the clear, so a punch landing in between makes it go round again.
     *
     * @return array{held:int, cleared:int, saved:false}
     */
    private function eraseWithoutSaving(Device $device): array
    {
        for ($attempt = 1; $attempt <= self::ERASE_ATTEMPTS; $attempt++) {
            $held = $this->driver->attendanceRecordCount($device);

            if ($held === null) {
                throw new ProtocolException("{$device->name} did not say how many punches it holds, so they could not be erased. Nothing was wiped.");
            }
            if ($held === 0) {
                return ['held' => 0, 'cleared' => 0, 'saved' => false];
            }

            try {
                return ['held' => $held, 'cleared' => $this->driver->clearAttendance($device, $held), 'saved' => false];
            } catch (ProtocolException $countChanged) {
                if ($attempt === self::ERASE_ATTEMPTS) {
                    throw $countChanged;
                }
            }
        }

        throw new ProtocolException("{$device->name} kept receiving punches, so they could not be erased. Nothing was wiped.");
    }
}
