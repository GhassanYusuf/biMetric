<?php

namespace App\Services\Employees;

use App\Contracts\BiometricDeviceDriver;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\BiometricTemplate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Promoseven\Biometric\Exception\UnsupportedCommandException;

/**
 * The system's copy of each employee's fingerprint templates (owner's decision,
 * 2026-09-24): so a Personnel ID change or a new terminal does not depend on the one
 * terminal that happens to hold the enrollment.
 *
 * THE ONLY CLASS THAT HANDLES TEMPLATE BYTES in the app. It receives them from
 * BiometricDeviceDriver::readTemplates(), stores them encrypted in the `template`
 * column biometric_templates always reserved for them (BiometricTemplate), and is the
 * only reader of them. Nothing here logs, audits or returns a byte — the
 * audit row carries counts.
 *
 * WHAT A CAPTURE MEANS. A template table that decoded is the device's full truth for
 * the people mapped to it, so for each live mapping on that terminal:
 *   - a finger present on the device is stored (or refreshed when its digest changed);
 *   - a finger that is no longer on the terminal KEEPS its copy (owner's rule,
 *     2026-10-11). The copy exists for exactly the moment the terminal loses the
 *     finger — a removal, a wipe, a re-add — so dropping it on that moment defeated
 *     it: Akram was removed from IT Room and re-added with nothing to put back. Only
 *     bare slot markers (no template) follow the terminal.
 * A read that failed or is unsupported captures nothing and deletes nothing — "could
 * not read" must never erase a copy. Where a terminal is "on the device" is the
 * mapping's fingerprint_slots, never these rows.
 *
 * Copies from OTHER terminals are never touched by a capture from this one: one
 * finger enrolled on two terminals is two templates, and each belongs to its source.
 */
class FingerprintVault
{
    public function __construct(private readonly BiometricDeviceDriver $driver) {}

    /**
     * "Read fingerprints to the system" for ONE person (owner's request, 2026-09-24):
     * reads the template table of each terminal they are on and stores their copies.
     * READ-ONLY on the terminals. Each terminal answers for itself — one that is
     * unreachable or has no template table does not stop the others.
     *
     * @param list<int>|null $deviceIds limit to these terminals; null = every live one
     * @return list<array{device_id:int, device_name:string, status:string, message:string, slots?:list<int>}>
     */
    public function captureEmployee(Employee $employee, ?array $deviceIds = null): array
    {
        $this->assertReady();

        $maps = EmployeeDeviceMap::query()
            ->with('device')
            ->where('employee_id', $employee->id)
            ->live()
            ->when($deviceIds !== null, fn ($q) => $q->whereIn('device_id', $deviceIds))
            ->get();

        $rows = [];
        foreach ($maps as $map) {
            $device = $map->device;
            if ($device === null) {
                continue;
            }

            $row = ['device_id' => $device->id, 'device_name' => $device->name];

            if (! $device->is_active) {
                $rows[] = $row + ['status' => 'skipped', 'message' => 'the terminal is switched off in this app'];

                continue;
            }

            try {
                $counts = $this->capture($device, $this->driver->readTemplates($device), $employee->id);
                $slots = BiometricTemplate::query()
                    ->where('employee_id', $employee->id)
                    ->where('device_id', $device->id)
                    ->whereNotNull('template_sha1')
                    ->orderBy('finger_slot')
                    ->pluck('finger_slot')
                    ->map(fn ($s) => (int) $s)
                    ->all();

                $rows[] = $row + [
                    'status' => 'read',
                    'slots' => $slots,
                    'message' => $slots === []
                        ? 'no fingerprints on this terminal'
                        : count($slots).' fingerprint(s) stored'
                            .($counts['stored'] + $counts['refreshed'] === 0 ? ' — already up to date' : ''),
                ];
            } catch (UnsupportedCommandException $e) {
                $rows[] = $row + ['status' => 'skipped', 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                // Unreachable or undecodable: nothing captured, nothing deleted.
                $rows[] = $row + ['status' => 'failed', 'message' => $e->getMessage()];
            }
        }

        return $rows;
    }

    /**
     * Refuses clearly, instead of failing on SQL, when the storage migration
     * (2026_09_24_100000) has not been run on this database.
     */
    public function assertReady(): void
    {
        static $ready = null;
        $ready ??= Schema::hasColumn('biometric_templates', 'template_sha1');

        if (! $ready) {
            throw new \DomainException(
                'Fingerprint storage is not set up on this server yet. An administrator must run the database migration first.'
            );
        }
    }

    /**
     * Reads the device's template table and captures it for everyone mapped there.
     *
     * @return array{stored:int, refreshed:int, dropped:int, people:int}
     */
    public function captureDevice(Device $device): array
    {
        $this->assertReady();

        return $this->capture($device, $this->driver->readTemplates($device));
    }

    /**
     * Captures from records already read (callers that just did the read).
     *
     * @param list<array{user_id:?string,slot:int,valid:int,size:int,sha1:string,template:string}> $records
     * @param int|null $onlyEmployeeId limit the capture to one person
     * @return array{stored:int, refreshed:int, dropped:int, people:int}
     */
    public function capture(Device $device, array $records, ?int $onlyEmployeeId = null): array
    {
        $byPin = [];
        foreach ($records as $r) {
            if (($r['user_id'] ?? '') !== '' && $r['user_id'] !== null) {
                // The last record for a slot wins; a device holds one template per slot.
                $byPin[(string) $r['user_id']][(int) $r['slot']] = $r;
            }
        }

        $maps = EmployeeDeviceMap::query()
            ->where('device_id', $device->id)
            ->live()
            ->when($onlyEmployeeId !== null, fn ($q) => $q->where('employee_id', $onlyEmployeeId))
            ->get(['employee_id', 'device_user_id']);

        $totals = ['stored' => 0, 'refreshed' => 0, 'dropped' => 0, 'people' => 0];

        foreach ($maps as $map) {
            $onDevice = $byPin[(string) $map->device_user_id] ?? [];
            $counts = DB::transaction(fn () => $this->syncOne($device, (int) $map->employee_id, $onDevice));

            if ($counts['stored'] + $counts['refreshed'] + $counts['dropped'] > 0) {
                $totals['people']++;
                $this->audit($device, (int) $map->employee_id, $counts);
            }
            foreach (['stored', 'refreshed', 'dropped'] as $k) {
                $totals[$k] += $counts[$k];
            }
        }

        return $totals;
    }

    /**
     * Stores fingers read off a DUPLICATE record on $device as this employee's copies
     * (2026-10-11: "ali-it" on IT ROOM was Ali — his finger 5 lived only there).
     * Add-only: a slot already stored for this employee from this terminal keeps its
     * copy; the duplicate's is still in the removal snapshot. Returns how many were added.
     *
     * @param list<array{slot:int, size?:int, sha1:string, template:string}> $records
     */
    public function adoptCopies(Device $device, int $employeeId, array $records): int
    {
        $this->assertReady();
        $added = 0;

        foreach ($records as $r) {
            $exists = BiometricTemplate::query()
                ->where('device_id', $device->id)
                ->where('employee_id', $employeeId)
                ->where('finger_slot', (int) $r['slot'])
                ->exists();
            if ($exists || (string) $r['template'] === '') {
                continue;
            }

            BiometricTemplate::create([
                'tenant_id' => $device->tenant_id,
                'device_id' => $device->id,
                'employee_id' => $employeeId,
                'finger_slot' => (int) $r['slot'],
                // Base64 before encryption, as syncOne(): the cast is for strings.
                'template' => base64_encode((string) $r['template']),
                'template_size' => (int) ($r['size'] ?? strlen((string) $r['template'])),
                'template_sha1' => (string) $r['sha1'],
                'template_format' => self::format((string) $device->protocol, $device->model),
                'verified_at' => now(),
            ]);
            $added++;
        }

        if ($added > 0) {
            $this->audit($device, $employeeId, ['stored' => $added, 'refreshed' => 0, 'dropped' => 0]);
        }

        return $added;
    }

    /**
     * Fingers with a stored copy, for display. Never bytes.
     *
     * @return list<int>
     */
    public function storedSlots(int $employeeId): array
    {
        return BiometricTemplate::query()
            ->where('employee_id', $employeeId)
            ->whereNotNull('template_sha1')
            ->distinct()
            ->orderBy('finger_slot')
            ->pluck('finger_slot')
            ->map(fn ($s) => (int) $s)
            ->all();
    }

    /**
     * The newest stored template per finger that came from the given protocol and
     * model — the copy an upload to such a terminal would write. Decrypted, so for a
     * device write only.
     *
     * @return list<array{fid:int,template:string,sha1:string,source_device_id:int}>
     */
    public function templatesFor(int $employeeId, string $protocol, ?string $model = null): array
    {
        return BiometricTemplate::query()
            ->where('employee_id', $employeeId)
            ->whereNotNull('template_sha1')
            ->when(
                $model !== null,
                fn ($q) => $q->where('template_format', self::format($protocol, $model)),
                fn ($q) => $q->where('template_format', 'like', $protocol.':%'),
            )
            ->orderByDesc('updated_at')
            ->get()
            ->unique('finger_slot')
            ->sortBy('finger_slot')
            ->map(fn (BiometricTemplate $t) => [
                'fid' => $t->finger_slot,
                'template' => base64_decode((string) $t->template, true) ?: '',
                'sha1' => $t->template_sha1,
                'source_device_id' => $t->device_id,
            ])
            ->values()
            ->all();
    }

    /**
     * The copy to write to $target, per finger: the newest stored ZKFinger v10
     * template for each slot, from ANY terminal, preferring one taken from the same
     * protocol family as the target. Decrypted, so for a device write only.
     *
     * CROSS-FAMILY ON EVIDENCE, NOT ASSUMPTION: four people's templates read from a
     * standalone terminal over UDP were byte-identical to the C3 panels' templatev10
     * rows (2026-09-15) — ZKAccess distributes one enrollment to both families
     * unchanged. That is what makes "enroll on the F18, push to Glass Door" possible.
     * Whether a finger enrolled on an F18 then MATCHES at a panel is confirmed per
     * panel by a real scan; until then the push is EXPERIMENTAL (docs/enrollment-flow.md).
     * The `SS21` signature is checked here and again by the library.
     *
     * @return list<array{fid:int,template:string,sha1:string,source_device_id:int}>
     */
    public function copiesFor(int $employeeId, Device $target): array
    {
        return BiometricTemplate::query()
            ->where('employee_id', $employeeId)
            ->whereNotNull('template_sha1')
            ->whereNotNull('template')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (BiometricTemplate $t) => [
                'fid' => (int) $t->finger_slot,
                'template' => base64_decode((string) $t->template, true) ?: '',
                'sha1' => (string) $t->template_sha1,
                'source_device_id' => (int) $t->device_id,
                'same_family' => str_starts_with((string) $t->template_format, $target->protocol.':'),
            ])
            // ZKFinger v10 marker `SS21` at offset 2, in hex for DeviceModelIsolationTest.
            ->filter(fn (array $c) => substr($c['template'], 2, 4) === "\x53\x53\x32\x31")
            ->sortByDesc('same_family')
            ->unique('fid')
            ->sortBy('fid')
            ->map(fn (array $c) => array_diff_key($c, ['same_family' => true]))
            ->values()
            ->all();
    }

    /** "<protocol>:<model>" — which family a stored template can be written back to. */
    public static function format(string $protocol, ?string $model): string
    {
        return $protocol.':'.($model ?? 'unknown');
    }

    /**
     * @param array<int,array<string,mixed>> $onDevice slot => record
     * @return array{stored:int, refreshed:int, dropped:int}
     */
    private function syncOne(Device $device, int $employeeId, array $onDevice): array
    {
        $counts = ['stored' => 0, 'refreshed' => 0, 'dropped' => 0];

        $existing = BiometricTemplate::query()
            ->where('employee_id', $employeeId)
            ->where('device_id', $device->id)
            ->get()
            ->keyBy('finger_slot');

        foreach ($onDevice as $slot => $r) {
            $row = $existing->get($slot);

            if ($row !== null && $row->template_sha1 === $r['sha1']) {
                continue; // same enrollment, nothing to write
            }

            BiometricTemplate::updateOrCreate(
                ['device_id' => $device->id, 'employee_id' => $employeeId, 'finger_slot' => $slot],
                [
                    'tenant_id' => $device->tenant_id,
                    // Base64 before encryption: the cast is for strings, the blob is binary.
                    'template' => base64_encode((string) $r['template']),
                    'template_size' => (int) $r['size'],
                    'template_sha1' => (string) $r['sha1'],
                    'template_format' => self::format((string) $device->protocol, $device->model),
                    'verified_at' => now(),
                ],
            );

            $row?->template_sha1 === null ? $counts['stored']++ : $counts['refreshed']++;
        }

        $gone = $existing->keys()->diff(array_keys($onDevice));
        if ($gone->isNotEmpty()) {
            // Markers only; a stored template outlives the terminal's copy (see above).
            $counts['dropped'] = BiometricTemplate::query()
                ->where('employee_id', $employeeId)
                ->where('device_id', $device->id)
                ->whereIn('finger_slot', $gone->all())
                ->whereNull('template_sha1')
                ->delete();
        }

        return $counts;
    }

    /** One summary row per person per capture: what changed, never what the bytes are. */
    private function audit(Device $device, int $employeeId, array $counts): void
    {
        AuditLog::create([
            'tenant_id' => $device->tenant_id,
            'user_id' => Auth::id(),
            'action' => 'employee.fingerprints_captured',
            'auditable_type' => \App\Models\Employee::class,
            'auditable_id' => $employeeId,
            'changes' => ['device_id' => $device->id, 'device' => $device->name] + $counts,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'user_agent' => app()->runningInConsole() ? null : substr((string) request()->userAgent(), 0, 255),
        ]);
    }
}
