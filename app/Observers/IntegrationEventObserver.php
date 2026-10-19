<?php

namespace App\Observers;

use App\Http\Resources\EmployeeResource;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EnrollmentSession;
use App\Services\Integration\EmployeeOffboarding;
use App\Services\Integration\WebhookDispatcher;
use Illuminate\Database\Eloquent\Model;

/**
 * Raises outbound webhook events from model changes.
 *
 * Observing models, rather than editing the services that change them, keeps the device
 * paths — which are calibrated against hardware — untouched (rule 15), and catches a
 * change however it was made: the SPA, the v1 API, a queued job or an import.
 *
 * Registered in AppServiceProvider. Emitting is cheap when a tenant has no endpoint
 * (one cached lookup), so a bulk import is not slowed by it.
 */
class IntegrationEventObserver
{
    /**
     * Columns a terminal read rewrites constantly. A save that changes ONLY these is
     * `employee.enrollment_changed`, not `employee.updated`, so an integration that
     * mirrors profile data is not woken by every sync.
     */
    private const ENROLLMENT_FIELDS = [
        'fingerprint_count', 'fingerprint_slots', 'card_number', 'enrollment_synced_at',
    ];

    public function __construct(
        private readonly WebhookDispatcher $webhooks,
        private readonly EmployeeOffboarding $offboarding,
    ) {}

    public function created(Model $model): void
    {
        if ($model instanceof Employee) {
            $this->employee($model, 'employee.created');
        }
    }

    public function updated(Model $model): void
    {
        match (true) {
            $model instanceof Employee => $this->employeeUpdated($model),
            $model instanceof DeviceCommand => $this->commandUpdated($model),
            $model instanceof EnrollmentSession => $this->sessionUpdated($model),
            $model instanceof Device => $this->deviceUpdated($model),
            default => null,
        };
    }

    public function deleted(Model $model): void
    {
        if ($model instanceof Employee) {
            $this->employee($model, 'employee.deleted');
        }
    }

    private function employeeUpdated(Employee $employee): void
    {
        $changed = array_values(array_diff(array_keys($employee->getChanges()), ['updated_at']));

        if ($changed === []) {
            return;
        }

        $enrollmentOnly = array_diff($changed, self::ENROLLMENT_FIELDS) === [];

        $this->employee($employee, $enrollmentOnly ? 'employee.enrollment_changed' : 'employee.updated', $changed);
    }

    /** @param list<string> $changed */
    private function employee(Employee $employee, string $event, array $changed = []): void
    {
        $this->webhooks->emit($employee->tenant_id, $event, [
            'employee' => (new EmployeeResource($employee))->resolve(),
            'changed' => $changed,
        ]);
    }

    private function commandUpdated(DeviceCommand $command): void
    {
        if (! $command->wasChanged('status') || ! $this->isFinal($command)) {
            return;
        }

        // The employee-removal flow (DELETE ?remove_from_devices=1) finishes here.
        $this->offboarding->commandFinished($command);

        $this->webhooks->emit($command->tenant_id, 'device_command.finished', [
            'command' => [
                'id' => $command->id,
                'device_id' => $command->device_id,
                'command' => $command->command,
                'status' => $command->status,
                'attempts' => $command->attempts,
                'message' => $command->message,
                'employee_id' => $command->payload['employee_id'] ?? null,
                'finished_at' => $command->finished_at?->utc()->toIso8601String(),
            ],
        ]);
    }

    /**
     * A command that failed but will be re-queued is NOT finished — announcing it would
     * tell the receiver "failed" for something that then succeeds. DeviceCommand::markFailed
     * leaves status `failed` for an instant before the job flips it back to `queued`, so
     * the retryable flag and the attempt budget decide.
     */
    private function isFinal(DeviceCommand $command): bool
    {
        return match ($command->status) {
            DeviceCommand::STATUS_SUCCEEDED, DeviceCommand::STATUS_CANCELLED => true,
            DeviceCommand::STATUS_FAILED => $command->attempts >= $command->max_attempts
                || ! ($command->error['retryable'] ?? false),
            default => false,
        };
    }

    private function sessionUpdated(EnrollmentSession $session): void
    {
        if (! $session->wasChanged('status') || ! in_array($session->status, EnrollmentSession::TERMINAL, true)) {
            return;
        }

        $this->webhooks->emit($session->tenant_id, 'enrollment.finished', [
            'session' => [
                'id' => $session->id,
                'employee_id' => $session->employee_id,
                'device_id' => $session->device_id,
                'type' => $session->type,
                'finger_slot' => $session->finger_slot,
                'status' => $session->status,
                'message' => $session->message,
                'completed_at' => $session->completed_at?->utc()->toIso8601String(),
            ],
        ]);
    }

    private function deviceUpdated(Device $device): void
    {
        if (! $device->wasChanged('health_status')) {
            return;
        }

        $this->webhooks->emit($device->tenant_id, 'device.status_changed', [
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'status' => $device->health_status,
                'previous_status' => $device->getOriginal('health_status'),
            ],
        ]);
    }
}
