<?php

namespace App\Services\Employees;

use App\Contracts\BiometricDeviceDriver;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Employee;
use App\Services\Devices\DeviceIdentityResolver;
use App\Services\Devices\DeviceUserSyncService;
use Illuminate\Support\Facades\Auth;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Exception\UnsupportedCommandException;

/**
 * Pushes an employee's stored fingerprints (FingerprintVault) to terminals they are
 * on but where those fingers are missing — the owner's "push the fingerprint to the
 * device if the person does not have one there" (2026-09-24).
 *
 * WHY IT EXISTS: the C3 panels (Glass Door, Entrance) cannot capture a fingerprint.
 * PullSDK has no enroll command; ZKAccess enrolls on a USB reader or a standalone
 * terminal and writes the template to the panels. This app does the same with the
 * terminal it already enrolls on: capture on the F18, the copy lands in the vault,
 * this writes it to the panels.
 *
 * ADD-ONLY. A finger the terminal already holds is never overwritten — the driver
 * skips it — so a push can never replace an enrollment that works with a copy taken
 * somewhere else.
 *
 * IMMEDIATE, not queued, for the reason EmployeeDevicePropagator gives (owner's
 * decision, 2026-09-15): the operator presses Push to find out whether the terminal
 * took it. One terminal failing never stops the others.
 */
class FingerprintPusher
{
    public function __construct(
        private readonly BiometricDeviceDriver $driver,
        private readonly FingerprintVault $vault,
        private readonly DeviceIdentityResolver $identity,
        private readonly DeviceUserSyncService $users,
    ) {}

    /**
     * @param  iterable<Device>  $devices
     * @return list<array{device_id:int, device_name:string, status:string, message:string, slots?:list<int>}>
     */
    public function push(Employee $employee, iterable $devices): array
    {
        $this->vault->assertReady();

        $rows = [];
        foreach ($devices as $device) {
            $row = ['device_id' => $device->id, 'device_name' => $device->name];

            try {
                $rows[] = $row + $this->pushOne($employee, $device);
            } catch (UnsupportedCommandException $e) {
                $rows[] = $row + ['status' => 'skipped', 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                $rows[] = $row + ['status' => 'failed', 'message' => $e->getMessage()];
            }
        }

        return $rows;
    }

    /**
     * The FIRST fingerprint write on a terminal whose model never did one from this
     * app (`template.upload` unknown — the F22, 2026-10-11). The operator's button on
     * a red "cannot take fingerprints yet" push row; pressing it is the owner's
     * go-ahead for the write probe (rule 16).
     *
     * Chosen to be unable to lose anything: it ADDS one finger the system already
     * holds to a person on this terminal who is missing it there. Nobody is deleted,
     * no finger is replaced; the library verifies the person's existing fingers
     * byte-for-byte afterwards and writes the original record back if any changed.
     * The result is read back from the terminal (rule 10).
     *
     * On success the level is raised for THIS terminal only (capabilities_cache,
     * `implemented`: written and read back, a real scan not yet seen) — never for the
     * model, which needs its file changed on evidence (rule 21).
     *
     * @return array{ok:bool, message:string, employee_id?:int, slot?:int}
     */
    public function trialWrite(Device $device): array
    {
        $this->vault->assertReady();
        $device->assertWritable();

        $capabilities = $this->driver->capabilities($device);
        if ($capabilities->supports(Capability::TEMPLATE_UPLOAD)) {
            return ['ok' => true, 'message' => "{$device->name} already takes fingerprints from this app; no test needed."];
        }
        if ($capabilities->level(Capability::TEMPLATE_UPLOAD) !== Capability::UNKNOWN) {
            return ['ok' => false, 'message' => "{$device->name} cannot take fingerprints from this app ({$capabilities->level(Capability::TEMPLATE_UPLOAD)})."];
        }
        $capabilities->require(Capability::TEMPLATE_BLOB_READ);

        // What every person on the terminal holds NOW, from the terminal itself.
        $slotsByPin = [];
        foreach ($this->driver->readUsers($device, true) as $u) {
            $slotsByPin[$u->userId] = $u->fingerSlots;
        }

        $candidate = null;
        $maps = \App\Models\EmployeeDeviceMap::query()->where('device_id', $device->id)->live()->orderBy('employee_id')->get();
        foreach ($maps as $map) {
            $have = $slotsByPin[(string) $map->device_user_id] ?? null;
            if ($have === null) {
                continue; // not on the terminal, or its fingers would not decode
            }
            foreach ($this->vault->copiesFor((int) $map->employee_id, $device) as $copy) {
                if (! in_array($copy['fid'], $have, true)) {
                    $candidate = [$map, $copy];
                    break 2;
                }
            }
        }

        if ($candidate === null) {
            return $this->trialOnTemporaryUser($device, $maps);
        }

        [$map, $copy] = $candidate;
        $employee = Employee::query()->findOrFail($map->employee_id);

        try {
            $written = $this->driver->uploadTemplates($device, (string) $map->device_user_id, [['slot' => $copy['fid'], 'template' => $copy['template']]]);
        } catch (\Throwable $e) {
            $this->auditTrial($device, $employee, $copy['fid'], false, $e->getMessage());

            return ['ok' => false, 'message' => "The test write on {$device->name} failed: {$e->getMessage()} {$employee->name}'s existing fingers were checked and left as they were.", 'employee_id' => $employee->id, 'slot' => $copy['fid']];
        }

        if (! in_array($copy['fid'], $written, true)) {
            $this->auditTrial($device, $employee, $copy['fid'], false, 'not kept');

            return ['ok' => false, 'message' => "{$device->name} accepted the test fingerprint for {$employee->name} but did not keep it.", 'employee_id' => $employee->id, 'slot' => $copy['fid']];
        }

        $device->forceFill([
            'capabilities_cache' => array_merge($device->capabilities_cache ?? [], [Capability::TEMPLATE_UPLOAD => Capability::IMPLEMENTED]),
        ])->save();
        $this->users->readEnrollment($device, $employee);
        $this->auditTrial($device, $employee, $copy['fid'], true, null);

        return [
            'ok' => true,
            'message' => "Fingerprint writing works on {$device->name}: {$employee->name}'s finger {$copy['fid']} was added from the system copy and read back unchanged. Their other fingers were not touched.",
            'employee_id' => $employee->id,
            'slot' => $copy['fid'],
        ];
    }

    /**
     * The test when nobody on the terminal is missing a finger the system holds (On The
     * Road, 2026-10-11 — everyone already had every stored finger). It touches no real
     * person: a temporary user is written, ONE stored template is added to it and read
     * back, and the temporary user is deleted again (DeviceUserSyncService::
     * withTemporaryUser). The template is a real stored copy, so the test proves the
     * real format; the temporary record exists for seconds.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\EmployeeDeviceMap>  $maps
     * @return array{ok:bool, message:string, employee_id?:int, slot?:int}
     */
    private function trialOnTemporaryUser(Device $device, $maps): array
    {
        $copy = null;
        $employee = null;
        foreach ($maps as $map) {
            $copies = $this->vault->copiesFor((int) $map->employee_id, $device);
            if ($copies !== []) {
                $copy = $copies[0];
                $employee = Employee::query()->find($map->employee_id);
                break;
            }
        }
        if ($copy === null || $employee === null) {
            return ['ok' => false, 'message' => "No fingerprint is stored in the system for anyone on {$device->name}, so there is nothing to test with yet."];
        }

        try {
            [$pin, $written] = $this->users->withTemporaryUser(
                $device,
                fn (string $pin) => [$pin, $this->driver->uploadTemplates($device, $pin, [['slot' => $copy['fid'], 'template' => $copy['template']]])],
            );
        } catch (\Throwable $e) {
            $this->auditTrial($device, $employee, $copy['fid'], false, 'temporary user: '.$e->getMessage());

            return ['ok' => false, 'message' => "The test write on {$device->name} failed: {$e->getMessage()} No real person's record was touched."];
        }

        if (! in_array($copy['fid'], $written, true)) {
            $this->auditTrial($device, $employee, $copy['fid'], false, 'temporary user: not kept');

            return ['ok' => false, 'message' => "{$device->name} accepted the test fingerprint on a temporary user but did not keep it. No real person's record was touched."];
        }

        $device->forceFill([
            'capabilities_cache' => array_merge($device->capabilities_cache ?? [], [Capability::TEMPLATE_UPLOAD => Capability::IMPLEMENTED]),
        ])->save();
        $this->auditTrial($device, $employee, $copy['fid'], true, "temporary user {$pin}, deleted after");

        return [
            'ok' => true,
            'message' => "Fingerprint writing works on {$device->name}: a stored fingerprint was written to a temporary user (ID {$pin}), read back unchanged, and the temporary user was deleted. No real person's record was touched.",
            'slot' => $copy['fid'],
        ];
    }

    private function auditTrial(Device $device, Employee $employee, int $slot, bool $ok, ?string $error): void
    {
        AuditLog::create([
            'tenant_id' => $device->tenant_id,
            'user_id' => Auth::id(),
            'action' => 'device.fingerprint_write_tested',
            'auditable_type' => Employee::class,
            'auditable_id' => $employee->id,
            'changes' => ['device_id' => $device->id, 'device' => $device->name, 'model' => $device->model, 'slot' => $slot, 'ok' => $ok, 'error' => $error],
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'user_agent' => app()->runningInConsole() ? null : substr((string) request()->userAgent(), 0, 255),
        ]);
    }

    /**
     * The stored fingers written onto a person just added to $device, as one sentence
     * for the push result — or null when there is nothing to say (no copies, or the
     * terminal already holds them all). Called by DeviceUserSyncService::pushEmployee()
     * so that adding someone to a terminal brings their fingerprints with them
     * (2026-10-11). Never throws: the person is on the terminal either way.
     */
    public function restoreOnto(Employee $employee, Device $device): ?string
    {
        try {
            $this->vault->assertReady();
            if ($this->vault->copiesFor($employee->id, $device) === []) {
                return null;
            }

            $result = $this->pushOne($employee, $device);
        } catch (UnsupportedCommandException) {
            return "Their stored fingerprints were not written: {$device->name} cannot take a fingerprint from this app, so they must enrol there.";
        } catch (\Throwable $e) {
            return "Their stored fingerprints were not written: {$e->getMessage()}";
        }

        return match ($result['status']) {
            'written' => 'Fingerprint(s) '.implode(', ', $result['slots']).' written from the system copy and read back.',
            'unchanged' => null,
            default => "Their stored fingerprints were not written: {$result['message']}.",
        };
    }

    /** @return array{status:string, message:string, slots?:list<int>} */
    private function pushOne(Employee $employee, Device $device): array
    {
        if (! $device->is_active) {
            return ['status' => 'skipped', 'message' => 'the terminal is switched off in this app'];
        }

        $device->assertWritable();
        $this->driver->capabilities($device)->require(Capability::TEMPLATE_UPLOAD);

        if ($this->identity->activeMap($device, $employee) === null) {
            return ['status' => 'skipped', 'message' => "{$employee->name} is not on this terminal; push the person first"];
        }
        $pin = $this->identity->pinFor($device, $employee);

        $copies = $this->vault->copiesFor($employee->id, $device);
        if ($copies === []) {
            return ['status' => 'skipped', 'message' => 'no fingerprint stored in the system for them — enroll or read one first'];
        }

        $written = $this->driver->uploadTemplates($device, $pin, array_map(fn (array $c) => [
            'slot' => $c['fid'],
            'template' => $c['template'],
        ], $copies));

        // Re-read the terminal so the per-terminal slots and the vault copies are what
        // the device now says, not what we sent (rule 10).
        $this->users->readEnrollment($device, $employee);

        AuditLog::create([
            'tenant_id' => $device->tenant_id,
            'user_id' => Auth::id(),
            'action' => 'employee.fingerprints_pushed',
            'auditable_type' => Employee::class,
            'auditable_id' => $employee->id,
            // Slots and source terminals only — never bytes, never digests of them.
            'changes' => [
                'device_id' => $device->id,
                'device' => $device->name,
                'slots_written' => $written,
                'sources' => array_values(array_unique(array_column($copies, 'source_device_id'))),
            ],
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'user_agent' => app()->runningInConsole() ? null : substr((string) request()->userAgent(), 0, 255),
        ]);

        if ($written === []) {
            return ['status' => 'unchanged', 'slots' => [], 'message' => 'already has every stored finger'];
        }

        return [
            'status' => 'written',
            'slots' => $written,
            'message' => count($written).' finger(s) written and read back',
        ];
    }
}
