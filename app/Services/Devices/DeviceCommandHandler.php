<?php

namespace App\Services\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\DeviceCommand;
use App\Models\Employee;
use Promoseven\Biometric\Client\Device as ProtocolDevice;
use Promoseven\Biometric\Exception\ProtocolException;

/**
 * Routes a queued command to the service that performs it.
 *
 * Deliberately thin: this is a dispatch table, not a place for logic. Each arm hands
 * off to a service that owns the actual behaviour, so the same operation is callable
 * synchronously (from a console command or a UI probe) and asynchronously (through
 * the queue) without duplication.
 *
 * Every arm returns a message an operator can read plus optional raw device output
 * for troubleshooting — that shape is what DeviceCommand stores.
 */
class DeviceCommandHandler
{
    public function __construct(
        private readonly BiometricDeviceDriver $driver,
        private readonly AttendanceSyncService $attendance,
        private readonly DeviceUserSyncService $users,
        private readonly EnrollmentOrchestratorService $enrollment,
        private readonly DeviceHealthService $health,
    ) {}

    /**
     * @return array{message:string, raw?:array<string,mixed>}
     */
    public function handle(DeviceCommand $command): array
    {
        $device = $command->device;
        $payload = $command->payload ?? [];

        return match ($command->command) {
            DeviceCommand::HEALTH_CHECK => $this->health->check($device),

            DeviceCommand::READ_INFO => $this->health->readInfo($device),

            DeviceCommand::PROBE_CAPABILITIES => (function () use ($device) {
                $caps = $this->driver->probeCapabilities($device);
                $confirmed = count(array_filter($caps->toArray(), fn ($l) => $l === 'confirmed'));

                return [
                    'message' => "Probed {$device->name}: {$confirmed} capabilities confirmed.",
                    'raw' => ['capabilities' => $caps->toArray()],
                ];
            })(),

            DeviceCommand::SYNC_ATTENDANCE => $this->attendance->sync(
                $device,
                full: (bool) ($payload['full'] ?? false),
                command: $command,
                // Device-local dates, or null for the ordinary watermark read. Kept
                // as strings all the way from the request: the service owns turning
                // them into instants, because only it knows the device's timezone.
                from: $payload['from'] ?? null,
                to: $payload['to'] ?? null,
            ),

            DeviceCommand::CLEAR_ATTENDANCE => app(AttendanceClearService::class)->clear($device, $command),

            DeviceCommand::WIPE_DEVICE => app(DeviceWipeService::class)->wipe($device, (bool) ($payload['save_punches'] ?? true), $command),

            DeviceCommand::SYNC_ENROLLMENT => $this->users->syncEnrollmentState($device),

            DeviceCommand::IMPORT_EMPLOYEES => $this->users->importFromDevice($device),

            DeviceCommand::RECONCILE_USERS => $this->users->reconcile($device),

            DeviceCommand::REBUILD_DEVICE => app(DeviceRebuildService::class)->backupAndFanOut($device, $payload),

            DeviceCommand::REBUILD_PERSON => app(DeviceRebuildService::class)->rebuildPerson($device, $this->employee($payload)),

            DeviceCommand::REBUILD_REMOVE => app(DeviceRebuildService::class)->removeUnlinked($device, (string) ($payload['device_user_id'] ?? '')),

            DeviceCommand::PUSH_USER => $this->users->pushEmployee(
                $device,
                $this->employee($payload),
            ),

            DeviceCommand::REMOVE_USER => $this->users->removeEmployee(
                $device,
                $this->employee($payload),
            ),

            DeviceCommand::ENROLL_FINGERPRINT => $this->enrollment->captureFingerprint(
                $device,
                $this->employee($payload),
                (int) ($payload['finger_slot'] ?? 0),
                $command,
            ),

            DeviceCommand::ENROLL_CARD => $this->enrollment->captureCard(
                $device,
                $this->employee($payload),
                $command,
            ),

            DeviceCommand::DELETE_FINGERPRINT => $this->enrollment->deleteFingerprint(
                $device,
                $this->employee($payload),
                (int) ($payload['finger_slot'] ?? -1),
            ),

            DeviceCommand::CLEAR_CARD => $this->enrollment->clearCard(
                $device,
                $this->employee($payload),
            ),

            DeviceCommand::SYNC_TIME => (function () use ($device) {
                // Each family sets its clock its own way (contract setClock, 2026-10-11:
                // the C3 door panels via DATETIME); this used to speak ZKTeco directly.
                ['before' => $before, 'after' => $after] = $this->driver->setClock($device);

                // Read back rather than trusting the write: the device stamps every
                // future punch with this clock, so a silently rejected write would
                // corrupt attendance from here on.
                $zone = \App\Support\Timezone::forDevice($device);
                $now = new \DateTimeImmutable('now', new \DateTimeZone($zone));
                $off = $after === null ? null : abs(strtotime($after->format('Y-m-d H:i:s')) - strtotime($now->format('Y-m-d H:i:s')));
                if ($off === null || $off > 60) {
                    throw new \RuntimeException(
                        "{$device->name} accepted the clock but reads back "
                        .($after?->format('Y-m-d H:i:s') ?? 'nothing')." instead of {$now->format('Y-m-d H:i:s')} ({$zone})."
                    );
                }

                return [
                    'message' => sprintf(
                        'Device clock set. Was %s, now %s.',
                        $before?->format('Y-m-d H:i:s') ?? 'unreadable',
                        $after?->format('Y-m-d H:i:s') ?? 'unreadable',
                    ),
                    'raw' => [
                        'before' => $before?->format('c'),
                        'after' => $after?->format('c'),
                    ],
                ];
            })(),

            default => throw new ProtocolException(
                "Unknown device command '{$command->command}'.",
                ['command' => $command->command],
            ),
        };
    }

    /**
     * Resolves the employee a command targets.
     *
     * withoutGlobalScopes is safe here because ProcessDeviceCommand has already
     * restored the tenant, and the id came from a command row inside that tenant.
     *
     * @param  array<string,mixed>  $payload
     */
    private function employee(array $payload): Employee
    {
        $id = $payload['employee_id'] ?? null;

        if ($id === null) {
            throw new ProtocolException('This command requires an employee_id in its payload.');
        }

        $employee = Employee::query()->find($id);

        if ($employee === null) {
            throw new ProtocolException("Employee {$id} no longer exists.", ['employee_id' => $id]);
        }

        return $employee;
    }
}
