<?php

namespace App\Services\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Exceptions\PinInUseException;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Services\Employees\EmployeeDevicePropagator;
use App\Services\Employees\FingerprintVault;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Promoseven\Biometric\Capability\Capability;

/**
 * "Rebuild from system" (owner, 2026-10-11: "wipe a biometric device and push the
 * stored data from the web app"). Makes ONE terminal hold exactly what the system
 * says: every linked person under their employee code, with the system's name, their
 * card, every stored finger and their privilege.
 *
 * NOT A LITERAL WIPE, on purpose, and the end state is the same:
 *   - a door panel emptied and refilled one person at a time locks everybody out for
 *     the duration (316 people on Glass Door), and an interruption leaves it half
 *     empty. Here a person already correct is never touched;
 *   - a wipe destroys what only the terminal holds. Here, first: punches downloaded,
 *     every record and template saved to an encrypted backup, everyone's fingers and
 *     cards stored in their accounts (rule 19) — or nothing changes.
 *
 * Records linked to NO employee are kept unless the operator ticks their removal: the
 * door panels hold people nobody has matched yet, and removing them locks them out.
 *
 * QUEUED AS A CHAIN (rule 11): sync_attendance → rebuild_device (backup, plan, fan-out)
 * → one rebuild_person per person needing a change → rebuild_remove per unlinked record
 * (if chosen) → sync_enrollment (read-back). One command at a time per terminal, each
 * audited and failing on its own; a 5-minute job limit cannot cut a 316-person panel
 * short. Each person goes through EmployeeDevicePropagator, so every model uses its own
 * method (rule 21). Casebook DC-017.
 */
class DeviceRebuildService
{
    public function __construct(
        private readonly BiometricDeviceDriver $driver,
        private readonly DeviceIdentityResolver $identity,
        private readonly DeviceUserSyncService $users,
        private readonly FingerprintVault $vault,
        private readonly DeviceCommandBus $bus,
    ) {}

    /**
     * What a rebuild would do, read-only. One read of the terminal's users and their
     * finger slots; everything else from the system.
     *
     * @return array{device_id:int, device_name:string, blockers:list<string>, people:list<array<string,mixed>>, unlinked:list<array<string,mixed>>, counts:array<string,int>}
     */
    public function plan(Device $device): array
    {
        $caps = $this->driver->capabilities($device);
        $blockers = [];

        foreach ([Capability::USER_READ => 'read its users', Capability::USER_WRITE => 'write users', Capability::USER_DELETE => 'delete users'] as $cap => $what) {
            if (! $caps->supports($cap)) {
                $blockers[] = "{$device->name} cannot {$what} from this app.";
            }
        }
        if (! $device->isWritable()) {
            $blockers[] = "{$device->name} holds people nobody has matched yet and is read-only until they are decided.";
        }
        if ($blockers !== []) {
            return $this->result($device, $blockers, [], []);
        }

        $onTerminal = [];
        foreach ($this->driver->readUsers($device, $caps->supports(Capability::TEMPLATE_TABLE_READ)) as $u) {
            $onTerminal[$u->userId] = $u;
        }
        if ($onTerminal !== [] && collect($onTerminal)->every(fn ($u) => $u->fingerSlots === null)) {
            $blockers[] = "{$device->name}'s fingerprint table would not decode, so its fingerprints could not be saved first.";
        }

        $maps = EmployeeDeviceMap::query()->with('employee')->where('device_id', $device->id)->live()->get();
        $linkedPins = [];
        $people = [];

        foreach ($maps as $map) {
            $employee = $map->employee;
            if ($employee === null) {
                continue;
            }
            $pin = (string) $map->device_user_id;
            $linkedPins[$pin] = true;
            $user = $onTerminal[$pin] ?? null;
            $code = (string) $employee->employee_code;
            $target = preg_match('/^\d{1,9}$/', $code) === 1 ? $code : $pin;

            $changes = [];
            $problems = [];

            if ($user === null) {
                $changes[] = 'add to the terminal';
            } else {
                if ($target !== $pin && ! $this->samePin($device, $target, $pin)) {
                    try {
                        $this->identity->assertPinFreeFor($device, $employee, $target);
                        $changes[] = "user ID {$pin} → {$target}";
                        if (! $device->canRenumberUsers() && ! $device->canMoveUsersByReadding()) {
                            $problems[] = "{$device->name} cannot change a user ID";
                        }
                    } catch (PinInUseException $e) {
                        $problems[] = $e->getMessage();
                    }
                }
                // Terminals keep 24 characters of a name (UserCodec); compare what fits.
                if ($device->canWriteUsers() && trim($user->name) !== trim(mb_substr(trim($employee->name), 0, 24))) {
                    $changes[] = "name \"{$user->name}\" → \"{$employee->name}\"";
                }
                $card = $this->users->cardToRestore($device, $employee);
                if ($card !== null && ! $user->hasCard()) {
                    $changes[] = "card #{$card} put back";
                }
                $stored = array_column($this->vault->copiesFor($employee->id, $device), 'fid');
                $missing = array_values(array_diff($stored, $user->fingerSlots ?? $stored));
                if ($missing !== []) {
                    $changes[] = 'finger(s) '.implode(', ', $missing).' put back';
                    if (! $caps->supports(Capability::TEMPLATE_UPLOAD)) {
                        $problems[] = "{$device->name} cannot take fingerprints from this app yet";
                    }
                }
            }

            $people[] = [
                'employee_id' => $employee->id,
                'name' => $employee->name,
                'from' => $pin,
                'to' => $target,
                'changes' => $changes,
                'problems' => $problems,
            ];
        }

        $unlinked = [];
        foreach ($onTerminal as $pin => $u) {
            if (! isset($linkedPins[(string) $pin])) {
                $unlinked[] = [
                    'device_user_id' => (string) $pin,
                    'name' => $u->name,
                    'card' => $u->hasCard() ? $u->cardNumber : null,
                    'fingers' => $u->fingerSlots === null ? null : count($u->fingerSlots),
                ];
            }
        }

        return $this->result($device, $blockers, $people, $unlinked);
    }

    /**
     * Queues the chain. The attendance read goes first so a punch only on the terminal
     * is in the system before anything is changed.
     *
     * @return list<DeviceCommand>
     */
    public function start(Device $device, bool $removeUnlinked): array
    {
        $plan = $this->plan($device);
        if ($plan['blockers'] !== []) {
            throw new \DomainException(implode(' ', $plan['blockers']));
        }

        $requestedAt = now()->toIso8601String();

        return [
            $this->bus->dispatch($device, DeviceCommand::SYNC_ATTENDANCE),
            $this->bus->dispatch($device, DeviceCommand::REBUILD_DEVICE, [
                'remove_unlinked' => $removeUnlinked,
                'requested_at' => $requestedAt,
            ]),
        ];
    }

    /**
     * The rebuild_device command: refuses unless the punches were downloaded after the
     * request, backs the whole terminal up, stores everyone's fingers and cards, and
     * queues one command per change.
     *
     * @return array{message:string, raw:array<string,mixed>}
     */
    public function backupAndFanOut(Device $device, array $payload): array
    {
        $synced = DeviceCommand::query()
            ->where('device_id', $device->id)
            ->where('command', DeviceCommand::SYNC_ATTENDANCE)
            ->where('status', DeviceCommand::STATUS_SUCCEEDED)
            // Parsed, not compared as text: an ISO string against the stored format
            // ("…T…+00:00" vs "… …") compares wrong on every driver.
            ->where('finished_at', '>=', isset($payload['requested_at']) ? \Illuminate\Support\Carbon::parse($payload['requested_at']) : now()->subMinutes(30))
            ->exists();
        if (! $synced) {
            // Retried by the command row: the attendance read queued just before may
            // still be running.
            throw new \RuntimeException("{$device->name}'s punches have not been downloaded since the rebuild was requested; nothing was changed yet.");
        }

        $plan = $this->plan($device);
        if ($plan['blockers'] !== []) {
            throw new \DomainException(implode(' ', $plan['blockers']));
        }

        // 1. The whole terminal, as it is now, encrypted (rule 19).
        $templates = $this->driver->capabilities($device)->supports(Capability::TEMPLATE_BLOB_READ)
            ? $this->driver->readTemplates($device)
            : [];
        $records = array_map(fn ($u) => $u->toArray(), $this->driver->readUsers($device, false));
        $backup = sprintf('device-backups/device-%d-%s.json.enc', $device->id, now()->format('Ymd-His'));
        Storage::disk('local')->put($backup, Crypt::encryptString(json_encode([
            'device_id' => $device->id,
            'device' => $device->name,
            'model' => $device->model,
            'users' => $records,
            'templates' => array_map(fn (array $t) => [
                'user_id' => $t['user_id'] ?? null, 'slot' => (int) $t['slot'], 'sha1' => (string) $t['sha1'],
                'template' => base64_encode((string) $t['template']),
            ], $templates),
            'taken_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR)));

        // 2. Everyone linked: fingers into their accounts, cards into the history.
        $this->users->syncEnrollmentState($device);

        // 3. One command per change, then the read-back.
        $queued = 0;
        foreach ($plan['people'] as $person) {
            if ($person['changes'] !== [] && $person['problems'] === []) {
                $this->bus->dispatch($device, DeviceCommand::REBUILD_PERSON, ['employee_id' => $person['employee_id']], 1);
                $queued++;
            }
        }
        $removals = 0;
        if (! empty($payload['remove_unlinked'])) {
            foreach ($plan['unlinked'] as $record) {
                $this->bus->dispatch($device, DeviceCommand::REBUILD_REMOVE, ['device_user_id' => $record['device_user_id']], 1);
                $removals++;
            }
        }
        $this->bus->dispatch($device, DeviceCommand::SYNC_ENROLLMENT);

        $skipped = count(array_filter($plan['people'], fn ($p) => $p['problems'] !== []));

        return [
            'message' => "{$device->name} backed up ({$backup}). Queued {$queued} person update(s)"
                .($removals ? " and {$removals} removal(s) of records linked to nobody" : '')
                .($skipped ? "; {$skipped} person(s) need attention first and were left as they are" : '')
                .'. A read-back follows.',
            'raw' => ['backup' => $backup, 'queued' => $queued, 'removals' => $removals, 'skipped' => $skipped],
        ];
    }

    /** @return array{message:string, raw:array<string,mixed>} */
    public function rebuildPerson(Device $device, Employee $employee): array
    {
        $result = app(EmployeeDevicePropagator::class)->push($employee, [$device], true);

        if ($result['failed'] !== []) {
            throw new \RuntimeException((string) ($result['failed'][0]['error'] ?? 'failed'));
        }
        $row = $result['written'][0] ?? null;

        return [
            'message' => $row['message'] ?? ($result['skipped'][0]['reason'] ?? "{$employee->name}: nothing to do."),
            'raw' => ['employee_id' => $employee->id, 'pin' => $row['pin'] ?? null, 'restored' => $row['restored'] ?? []],
        ];
    }

    /** @return array{message:string, raw:array<string,mixed>} */
    public function removeUnlinked(Device $device, string $pin): array
    {
        $result = $this->users->releaseUnlinkedNumber($device, $pin, null);

        return ['message' => $result['message'], 'raw' => ['snapshot' => $result['snapshot']]];
    }

    private function samePin(Device $device, string $a, string $b): bool
    {
        // C3 panels keep user IDs as numbers (casebook DC-009).
        return $device->protocol === Device::PROTOCOL_C3 && ctype_digit($a) && ctype_digit($b) && (int) $a === (int) $b;
    }

    private function result(Device $device, array $blockers, array $people, array $unlinked): array
    {
        return [
            'device_id' => $device->id,
            'device_name' => $device->name,
            'blockers' => $blockers,
            'people' => $people,
            'unlinked' => $unlinked,
            'counts' => [
                'linked' => count($people),
                'to_change' => count(array_filter($people, fn ($p) => $p['changes'] !== [] && $p['problems'] === [])),
                'unchanged' => count(array_filter($people, fn ($p) => $p['changes'] === [] && $p['problems'] === [])),
                'need_attention' => count(array_filter($people, fn ($p) => $p['problems'] !== [])),
                'unlinked' => count($unlinked),
            ],
        ];
    }
}
