<?php

namespace App\Services\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\AccessCard;
use App\Models\BiometricTemplate;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\EnrollmentSession;
use App\Services\Realtime\MqttPublisher;
use App\Services\Realtime\TopicBuilder;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Client\Device as ProtocolDevice;
use Promoseven\Biometric\Value\EnrollmentResult;

/**
 * Runs a credential capture and keeps its state durable.
 *
 * WHY A SESSION EXISTS: a capture blocks on a human walking to a terminal for up to
 * 30 seconds. Previously it ran inline in a request, so closing the browser lost all
 * record that it had been attempted, and there was no way to answer "did the device
 * ever beep". The session row makes the state machine survive the worker.
 *
 * Progress is pushed over the EXISTING MQTT channel so the UI follows along live
 * without polling — no second real-time mechanism was introduced.
 */
class EnrollmentOrchestratorService
{
    public function __construct(
        private readonly BiometricDeviceDriver $driver,
        private readonly DeviceUserSyncService $users,
        private readonly DeviceIdentityResolver $identity,
        private readonly MqttPublisher $mqtt,
    ) {}

    /**
     * Captures a fingerprint into a slot.
     *
     * @return array{message:string, raw:array<string,mixed>}
     */
    public function captureFingerprint(
        Device $device,
        Employee $employee,
        int $fingerSlot,
        ?DeviceCommand $command = null,
    ): array {
        $device->assertWritable();

        $this->driver->capabilities($device)->require(Capability::FINGERPRINT_ENROLL);

        if ($fingerSlot < 0 || $fingerSlot > 9) {
            throw new \InvalidArgumentException("Finger slot must be 0-9, got {$fingerSlot}.");
        }

        $session = $this->openSession($device, $employee, EnrollmentSession::TYPE_FINGERPRINT, $command, $fingerSlot);

        return $this->runCapture(
            $session,
            $device,
            $employee,
            fn (ProtocolDevice $d) => $d->enrollment()->captureFingerprint(
                $this->identity->pinFor($device, $employee),
                $fingerSlot,
                $this->progressReporter($session),
            ),
        );
    }

    /**
     * Captures the next card tapped.
     *
     * @return array{message:string, raw:array<string,mixed>}
     */
    public function captureCard(Device $device, Employee $employee, ?DeviceCommand $command = null): array
    {
        $device->assertWritable();

        $this->driver->capabilities($device)->require(Capability::CARD_ENROLL);

        $session = $this->openSession($device, $employee, EnrollmentSession::TYPE_CARD, $command);

        return $this->runCapture(
            $session,
            $device,
            $employee,
            fn (ProtocolDevice $d) => $d->enrollment()->captureCardForUser(
                $this->identity->pinFor($device, $employee),
                $this->progressReporter($session),
            ),
        );
    }

    /**
     * Deletes one fingerprint from the terminal.
     *
     * @return array{message:string, raw:array<string,mixed>}
     */
    public function deleteFingerprint(Device $device, Employee $employee, int $fingerSlot): array
    {
        $device->assertWritable();

        $this->driver->capabilities($device)->require(Capability::TEMPLATE_DELETE);

        if ($fingerSlot < 0 || $fingerSlot > 9) {
            throw new \InvalidArgumentException("Finger slot must be 0-9, got {$fingerSlot}.");
        }

        // The library reads the table before and after, and refuses when it cannot
        // verify — a silent no-op on biometric data is worse than an error.
        $remaining = $this->driver->run(
            $device,
            fn (ProtocolDevice $d) => $d->templates()->deleteSlot($this->identity->pinFor($device, $employee), $fingerSlot),
        );

        BiometricTemplate::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employee->id)
            ->where('finger_slot', $fingerSlot)
            ->delete();

        $this->syncMapSlots($device, $employee, $remaining);

        return [
            'message' => count($remaining) === 0
                ? "Deleted slot {$fingerSlot}. {$employee->name} now has no fingerprints on {$device->name}."
                : "Deleted slot {$fingerSlot}. ".count($remaining).' fingerprint(s) remain.',
            'raw' => ['remaining_slots' => $remaining],
        ];
    }

    /**
     * Clears the card on the terminal.
     *
     * There is no delete-card command: the card is one field on the user record, so
     * this rewrites that record with card 0, reusing the uid so templates survive.
     *
     * @return array{message:string, raw:array<string,mixed>}
     */
    public function clearCard(Device $device, Employee $employee): array
    {
        $this->driver->capabilities($device)->require(Capability::CARD_WRITE);

        $before = $this->driver->run(
            $device,
            fn (ProtocolDevice $d) => $d->users()->find($this->identity->pinFor($device, $employee)),
        );

        if ($before === null) {
            throw new \RuntimeException("{$employee->name} is not on {$device->name}.");
        }

        if (! $before->hasCard()) {
            return [
                'message' => "{$employee->name} has no card on {$device->name}, so there was nothing to remove.",
                'raw' => ['previous_card' => null],
            ];
        }

        $after = $this->driver->run(
            $device,
            fn (ProtocolDevice $d) => $d->users()->setCard($this->identity->pinFor($device, $employee), null),
        );

        if ($after->hasCard()) {
            throw new \RuntimeException(
                "The device still reports card #{$after->cardNumber} after the clear.",
            );
        }

        AccessCard::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employee->id)
            ->where('status', AccessCard::ACTIVE)
            ->update([
                'status' => AccessCard::REVOKED,
                'revoked_at' => now(),
                'revoke_reason' => 'Cleared from the terminal',
            ]);

        EmployeeDeviceMap::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employee->id)
            ->update(['card_number' => null, 'synced_at' => now()]);

        return [
            'message' => "Removed card #{$before->cardNumber} from {$employee->name} on {$device->name}.",
            'raw' => ['previous_card' => $before->cardNumber],
        ];
    }

    /**
     * Abandons an active session.
     *
     * Also tells the device to stop capturing — otherwise the terminal sits in
     * enrollment mode refusing normal punches until it times out on its own.
     */
    public function cancel(EnrollmentSession $session): void
    {
        if ($session->isFinished()) {
            return;
        }

        try {
            $this->driver->run($session->device, fn (ProtocolDevice $d) => $d->enrollment()->cancel());
        } catch (\Throwable) {
            // The terminal will time out by itself; the session state matters more.
        }

        $session->transitionTo(EnrollmentSession::CANCELLED, 'Cancelled by an operator.');
        $this->publish($session);
    }

    /**
     * Reaps sessions whose worker died mid-capture.
     *
     * Without this, a killed worker leaves a session "waiting" forever and the
     * employee can never start a fresh attempt.
     */
    public function expireStale(): int
    {
        $expired = 0;

        $candidates = EnrollmentSession::query()
            ->whereNotIn('status', EnrollmentSession::TERMINAL)
            ->get();

        foreach ($candidates as $session) {
            if (! $session->hasExpired()) {
                continue;
            }

            $session->transitionTo(
                EnrollmentSession::TIMEOUT,
                'Abandoned: no result was recorded before the session expired.',
            );
            $this->publish($session);
            $expired++;
        }

        return $expired;
    }

    // ------------------------------------------------------------------ internals

    private function openSession(
        Device $device,
        Employee $employee,
        string $type,
        ?DeviceCommand $command,
        ?int $fingerSlot = null,
    ): EnrollmentSession {
        $timeout = $type === EnrollmentSession::TYPE_FINGERPRINT ? 30 : 20;

        $session = EnrollmentSession::create([
            'tenant_id' => $device->tenant_id,
            'employee_id' => $employee->id,
            'device_id' => $device->id,
            'device_command_id' => $command?->id,
            'type' => $type,
            'finger_slot' => $fingerSlot,
            'status' => EnrollmentSession::PENDING,
            'requested_at' => now(),
            'timeout_seconds' => $timeout,
            'events' => [],
            'requested_by' => $command?->requested_by,
        ]);

        $this->publish($session);

        return $session;
    }

    /**
     * Runs the capture, mapping the library's result onto session state.
     *
     * @param  \Closure(ProtocolDevice):EnrollmentResult  $capture
     * @return array{message:string, raw:array<string,mixed>}
     */
    private function runCapture(
        EnrollmentSession $session,
        Device $device,
        Employee $employee,
        \Closure $capture,
    ): array {
        /*
         * The device attaches a credential to an EXISTING user record. Rather than
         * failing with "push them first" — which made adding a new person a two-step
         * dance with an error in the middle — ensure the record exists first.
         *
         * THIS IS A WRITE, and it used to address the slot by $employee->employee_code.
         * On a terminal that already had somebody at that number, ensureExists() found
         * the stranger's record and quietly enrolled a fingerprint onto it. So it takes
         * the same three guards as an explicit push: the device must be adopted, the
         * PIN comes from the stored mapping, and the slot is read before it is touched.
         */
        $device->assertWritable();

        $pin = $this->identity->pinFor($device, $employee);

        $this->driver->saveUser(
            $device,
            $pin,
            $employee->name,
            $this->identity->slotGuard($device, $employee, $pin),
            onlyIfMissing: true,
        );

        $session->transitionTo(EnrollmentSession::SENT);
        $this->publish($session);

        /** @var EnrollmentResult $result */
        $result = $this->driver->run($device, $capture);

        /*
         * Do NOT append $result->events here.
         *
         * progressReporter() already persisted each event as it arrived — that is the
         * whole point, so a worker dying mid-capture still leaves the trail. The result
         * carries the same list for callers that did not pass a reporter, and appending
         * it again produced a visibly doubled history:
         *   beeped -> waiting_for_finger -> timeout -> beeped -> waiting_for_finger -> timeout
         */

        return match (true) {
            $result->succeeded() => $this->finishSuccess($session, $device, $employee, $result),
            $result->didTimeOut() => $this->finishTimeout($session, $device, $employee),
            default => $this->finishRejected($session, $result),
        };
    }

    /** @return array{message:string, raw:array<string,mixed>} */
    private function finishSuccess(
        EnrollmentSession $session,
        Device $device,
        Employee $employee,
        EnrollmentResult $result,
    ): array {
        if ($result->cardNumber !== null) {
            $session->forceFill(['captured_card_number' => $result->cardNumber])->save();
            $this->recordCapturedCard($device, $employee, $result->cardNumber);
            $message = "Card #{$result->cardNumber} enrolled for {$employee->name} on {$device->name}.";
        } else {
            $message = "Fingerprint captured for {$employee->name} in slot {$result->fingerIndex} on {$device->name}.";
        }

        $session->transitionTo(EnrollmentSession::SUCCESS, $message);
        $this->publish($session);

        // Re-read so stored slots and card reflect what the device actually holds now,
        // rather than what we assume the capture did.
        $this->users->syncEnrollmentState($device);

        return ['message' => $message, 'raw' => $result->toArray()];
    }

    /** @return array{message:string, raw:array<string,mixed>} */
    private function finishTimeout(EnrollmentSession $session, Device $device, Employee $employee): array
    {
        $what = $session->type === EnrollmentSession::TYPE_CARD ? 'card' : 'finger';

        $message = "No {$what} was presented at {$device->name} within {$session->timeout_seconds} seconds "
            ."for {$employee->name}. The device beeped — check somebody was there to respond.";

        $session->transitionTo(EnrollmentSession::TIMEOUT, $message);
        $this->publish($session);

        return ['message' => $message, 'raw' => ['outcome' => EnrollmentResult::TIMEOUT]];
    }

    /** @return array{message:string, raw:array<string,mixed>} */
    private function finishRejected(EnrollmentSession $session, EnrollmentResult $result): array
    {
        $message = "The device rejected the capture (error code {$result->errorCode}). "
            .'Ask them to present it again — a poor read or an already-enrolled finger both report this.';

        $session->forceFill(['device_error_code' => $result->errorCode])->save();
        $session->transitionTo(EnrollmentSession::FAILED, $message);
        $this->publish($session);

        return ['message' => $message, 'raw' => $result->toArray()];
    }

    /**
     * Progress callback that persists device events as they arrive.
     *
     * Written through immediately rather than buffered: the whole point is that an
     * operator watching the UI sees "the device beeped" before the capture finishes,
     * and that the trail survives a worker that dies mid-capture.
     */
    private function progressReporter(EnrollmentSession $session): \Closure
    {
        return function (string $event) use ($session): void {
            $session->appendEvents([$event]);

            // The first device event proves the trigger landed.
            if ($event === 'beeped' && $session->status === EnrollmentSession::SENT) {
                $session->transitionTo(EnrollmentSession::WAITING);
            }

            $this->publish($session, $event);
        };
    }

    private function recordCapturedCard(Device $device, Employee $employee, int $cardNumber): void
    {
        AccessCard::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employee->id)
            ->where('status', AccessCard::ACTIVE)
            ->update([
                'status' => AccessCard::REPLACED,
                'revoked_at' => now(),
                'revoke_reason' => 'Replaced by a newly enrolled card',
            ]);

        AccessCard::create([
            'tenant_id' => $device->tenant_id,
            'employee_id' => $employee->id,
            'device_id' => $device->id,
            'card_number' => $cardNumber,
            'status' => AccessCard::ACTIVE,
            'assigned_at' => now(),
            'is_suspect' => AccessCard::looksLikeSentinel($cardNumber),
        ]);
    }

    /** @param list<int> $slots */
    private function syncMapSlots(Device $device, Employee $employee, array $slots): void
    {
        EmployeeDeviceMap::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employee->id)
            ->update(['fingerprint_slots' => $slots, 'synced_at' => now()]);
    }

    private function publish(EnrollmentSession $session, ?string $event = null): void
    {
        if ($session->tenant_id === null) {
            return;
        }

        try {
            $this->mqtt->publish(
                TopicBuilder::module($session->tenant_id, 'enrollment', $session->id),
                [
                    'session_id' => $session->id,
                    'employee_id' => $session->employee_id,
                    'device_id' => $session->device_id,
                    'type' => $session->type,
                    'finger_slot' => $session->finger_slot,
                    'status' => $session->status,
                    'event' => $event,
                    'message' => $session->message,
                    'at' => now()->toIso8601String(),
                ],
            );
        } catch (\Throwable) {
            // MQTT only enhances: a broker outage must never fail an enrollment or
            // stop the session state being recorded.
        }
    }
}
