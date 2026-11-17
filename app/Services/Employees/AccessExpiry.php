<?php

namespace App\Services\Employees;

use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Integration\EmployeeOffboarding;
use App\Services\Notifications\NotificationService;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Log;

/**
 * Ends terminal access on the day it is due (owner, 2026-11-17: "if I keep a person as resigned on
 * 29 Nov, will the access be removed on 29 Nov?" — "yes build both"). Run nightly just after
 * midnight office time (routes/console.php, `employees:end-access`).
 *
 *   Resignation  resigned_at has been reached (resigned_at <= today): made inactive and taken off
 *                every terminal. A resignation dated in the future changes nothing until that day.
 *   Valid period valid_period_enabled and access_valid_to has passed (access_valid_to < today —
 *                "valid to 29 Nov" means valid through the 29th): taken off every terminal; the
 *                record is otherwise unchanged (they have not resigned).
 *
 * Removal is the batch removal (EmployeeOffboarding::takeManyOffTerminals → REMOVE_USERS): card and
 * fingerprints saved into the system first, every finger confirmed saved or that person refused
 * (DC-048), each delete read back. Nothing is deleted from the system. Whoever is still linked to a
 * terminal next night — terminal offline, a refusal — is simply taken again: the job is idempotent.
 * People already in a queued or running removal are left to it.
 *
 * Who could not be taken off (a terminal this app cannot write) is notified to the users who may
 * edit employees, so it is never silent.
 */
class AccessExpiry
{
    public function __construct(
        private readonly EmployeeOffboarding $offboarding,
        private readonly NotificationService $notifications,
    ) {}

    /** @return array{resigned:int, expired:int, jobs:int, skipped:array<string,string>} totals over every company */
    public function run(): array
    {
        $totals = ['resigned' => 0, 'expired' => 0, 'jobs' => 0, 'skipped' => []];

        foreach (Tenant::query()->get() as $tenant) {
            Tenancy::set($tenant->id);
            try {
                $r = $this->runForTenant();
                $totals['resigned'] += $r['resigned'];
                $totals['expired'] += $r['expired'];
                $totals['jobs'] += $r['jobs'];
                $totals['skipped'] += $r['skipped'];
            } finally {
                Tenancy::clear();
            }
        }

        return $totals;
    }

    /** @return array{resigned:int, expired:int, jobs:int, skipped:array<string,string>} */
    public function runForTenant(): array
    {
        $today = Employee::officeToday();
        $live = fn ($q) => $q->whereNull('valid_to');

        // Resignations that have arrived: inactive (the saving hook does it) and off the terminals.
        $resigned = Employee::query()->resignedNow()
            ->where(fn ($q) => $q->where('is_active', true)->orWhereHas('deviceMaps', $live))
            ->get();
        foreach ($resigned->where('is_active', true) as $e) {
            $e->forceFill(['is_active' => false])->save();
        }

        // Valid periods that have ended, for people still on a terminal.
        $expired = Employee::query()
            ->where('valid_period_enabled', true)
            ->whereNotNull('access_valid_to')
            ->whereDate('access_valid_to', '<', $today)
            ->whereHas('deviceMaps', $live)
            ->get();

        $people = $resigned->merge($expired)->unique('id')
            ->filter(fn (Employee $e) => $e->deviceMaps()->whereNull('valid_to')->exists())
            ->reject(fn (Employee $e) => $this->removalPending($e))
            ->values();

        $queued = $people->isEmpty() ? ['commands' => [], 'skipped' => [], 'queued_for' => []]
            : $this->offboarding->takeManyOffTerminals($people->all());

        Log::info('Access ended for the day.', [
            'tenant_id' => Tenancy::id(), 'date' => $today,
            'resigned' => $resigned->pluck('id')->all(), 'expired' => $expired->pluck('id')->all(),
            'jobs' => count($queued['commands']), 'skipped' => $queued['skipped'],
        ]);

        if ($queued['skipped'] !== []) {
            $this->tell($people->reject(fn (Employee $e) => in_array($e->id, $queued['queued_for'], true))->all(), $queued['skipped']);
        }

        return [
            'resigned' => $resigned->count(),
            'expired' => $expired->count(),
            'jobs' => count($queued['commands']),
            'skipped' => $queued['skipped'],
        ];
    }

    /** Already in a queued or running removal (single or batch): leave it to that job. */
    private function removalPending(Employee $employee): bool
    {
        return DeviceCommand::query()
            ->whereIn('command', [DeviceCommand::REMOVE_USER, DeviceCommand::REMOVE_USERS])
            ->whereIn('status', [DeviceCommand::STATUS_QUEUED, DeviceCommand::STATUS_RUNNING])
            ->get(['payload'])
            ->contains(fn (DeviceCommand $c) => (int) ($c->payload['employee_id'] ?? 0) === $employee->id
                || collect($c->payload['employees'] ?? [])->contains(fn ($row) => (int) ($row['id'] ?? 0) === $employee->id));
    }

    /**
     * @param  list<Employee>  $left  people still on a terminal that could not be written
     * @param  array<string,string>  $skipped  terminal name => why
     */
    private function tell(array $left, array $skipped): void
    {
        $who = collect($left)->pluck('name')->take(10)->implode(', ').(count($left) > 10 ? ' and '.(count($left) - 10).' more' : '');
        $where = collect($skipped)->map(fn ($why, $name) => "{$name} ({$why})")->implode('; ');
        $recipients = User::query()->where('is_active', true)->get()->filter(fn (User $u) => $u->can('employees.update'));

        $this->notifications->notifyMany($recipients, [
            'type' => 'employees.access_not_ended',
            'level' => 'urgent',
            'title' => 'Access could not be ended on some terminals',
            'body' => trim(($who !== '' ? "Still on a terminal: {$who}. " : '')."Not writable from here: {$where}."),
        ]);
    }
}
