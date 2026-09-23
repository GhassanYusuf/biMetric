<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\DeviceRosterEntry;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Services\Devices\DeviceAdoptionService;
use Illuminate\Console\Command;

/**
 * Proposes which unclaimed device users are employees we already have.
 *
 * WHY THIS IS A PROPOSAL AND NOT A MATCH. The estate shares staff: on 2026-09-15,
 * 260 of 293 people appeared on more than one of the six terminals. Importing every
 * roster independently would create a separate employee per terminal for the same
 * human. So the second and subsequent devices need their entries attached to
 * employees that already exist.
 *
 * The only thing to attach them BY is the name the device shows — and that is a weak
 * key. It is 24 bytes, frequently abbreviated or transliterated, and the Entrance
 * panel alone holds 320 records under 259 distinct names, so a name is not even unique
 * within one device. Matching on it automatically is how two humans become one.
 *
 * Hence: this command PROPOSES. It applies only matches that are unambiguous in both
 * directions — exactly one roster entry and exactly one employee — and only when asked
 * with --apply. Everything else is printed for a person to decide, because that is the
 * only honest place to put it.
 *
 * A name is still a guess even when unique. --apply is a convenience for the bulk
 * onboarding case, not a statement that the match is proven; the national id at the
 * Person layer is what eventually proves two records are one human.
 */
class DeviceSuggestClaims extends Command
{
    protected $signature = 'device:suggest-claims
                            {device : Device id or name}
                            {--apply : Claim the unambiguous matches instead of only listing them}';

    protected $description = 'Propose employees for a device\'s unclaimed roster entries (by name; review before applying)';

    public function handle(DeviceAdoptionService $adoption): int
    {
        $arg = $this->argument('device');

        $device = Device::withoutGlobalScopes()
            ->where('id', is_numeric($arg) ? (int) $arg : 0)
            ->orWhere('name', $arg)
            ->first();

        if ($device === null) {
            $this->error('No matching device.');

            return self::FAILURE;
        }

        $entries = DeviceRosterEntry::query()
            ->where('device_id', $device->id)
            ->where('claim_status', DeviceRosterEntry::UNCLAIMED)
            ->get();

        if ($entries->isEmpty()) {
            $this->info("{$device->name} has no unclaimed roster entries.");

            return self::SUCCESS;
        }

        // Employees who already hold a PIN on THIS device are not candidates — one live
        // identity per person per device, or "who is PIN 5" stops having one answer.
        $alreadyHere = EmployeeDeviceMap::query()
            ->where('device_id', $device->id)
            ->live()
            ->pluck('employee_id')
            ->all();

        $candidates = Employee::withoutGlobalScopes()
            ->where('tenant_id', $device->tenant_id)
            ->whereNotIn('id', $alreadyHere ?: [0])
            ->get(['id', 'name', 'employee_code']);

        $byName = [];
        foreach ($candidates as $employee) {
            $byName[$this->normalise($employee->name)][] = $employee;
        }

        // How many roster entries want each name, so a name shared by two device records
        // is never treated as unambiguous.
        $entriesPerName = [];
        foreach ($entries as $entry) {
            $entriesPerName[$this->normalise($entry->device_name ?? '')][] = $entry;
        }

        $unambiguous = [];
        $ambiguous = [];
        $unmatched = [];

        foreach ($entries as $entry) {
            $key = $this->normalise($entry->device_name ?? '');
            $matches = $key === '' ? [] : ($byName[$key] ?? []);

            if ($matches === []) {
                $unmatched[] = $entry;
            } elseif (count($matches) === 1 && count($entriesPerName[$key]) === 1) {
                $unambiguous[$entry->id] = [$entry, $matches[0]];
            } else {
                $ambiguous[] = [$entry, $matches];
            }
        }

        $this->newLine();
        $this->line("<comment>{$device->name}</comment> — {$entries->count()} unclaimed entr"
            .($entries->count() === 1 ? 'y' : 'ies'));
        $this->line(sprintf(
            '  %d unambiguous, %d ambiguous, %d with no candidate',
            count($unambiguous),
            count($ambiguous),
            count($unmatched),
        ));
        $this->newLine();

        if ($unambiguous !== []) {
            $this->line('Unambiguous — one device record, one employee:');
            $this->table(
                ['PIN', 'Name on device', '→ Employee', 'Code'],
                array_map(
                    fn (array $pair) => [
                        $pair[0]->device_user_id,
                        $pair[0]->device_name ?: '—',
                        $pair[1]->name,
                        $pair[1]->employee_code,
                    ],
                    array_slice(array_values($unambiguous), 0, 40),
                ),
            );
            if (count($unambiguous) > 40) {
                $this->line('  … '.(count($unambiguous) - 40).' more');
            }
        }

        if ($ambiguous !== []) {
            $this->newLine();
            $this->warn('Ambiguous — a person must choose. Not applied by --apply:');
            foreach (array_slice($ambiguous, 0, 20) as [$entry, $matches]) {
                $names = implode(', ', array_map(fn (Employee $e) => "{$e->name} (#{$e->employee_code})", $matches));
                $this->line("  PIN {$entry->device_user_id} \"{$entry->device_name}\" → {$names}");
            }
            if (count($ambiguous) > 20) {
                $this->line('  … '.(count($ambiguous) - 20).' more');
            }
        }

        if ($unmatched !== []) {
            $this->newLine();
            $this->line('No candidate — new hires here, leavers, or test accounts:');
            foreach (array_slice($unmatched, 0, 20) as $entry) {
                $this->line("  PIN {$entry->device_user_id} \"{$entry->device_name}\"");
            }
            if (count($unmatched) > 20) {
                $this->line('  … '.(count($unmatched) - 20).' more');
            }
        }

        $this->newLine();

        if (! $this->option('apply')) {
            $this->line('Nothing was changed. Re-run with --apply to claim the unambiguous matches.');
            $this->line('Ambiguous and unmatched entries always need a person either way.');

            return self::SUCCESS;
        }

        $applied = 0;
        foreach ($unambiguous as [$entry, $employee]) {
            try {
                $adoption->claim($entry, $employee);
                $applied++;
            } catch (\RuntimeException $e) {
                $this->warn("  PIN {$entry->device_user_id}: {$e->getMessage()}");
            }
        }

        $this->info("Claimed {$applied} entr".($applied === 1 ? 'y' : 'ies')." on {$device->name}.");
        $this->line('Remaining: '.$adoption->pendingCount($device).' still undecided.');

        return self::SUCCESS;
    }

    /**
     * Strips everything a device name field mangles.
     *
     * Case, spacing, punctuation and digits all vary between what HR typed and what a
     * 24-byte field held — "ALI JAAFAR", "ali-jaafar" and "Ali Jaafar 2" are one person
     * often enough that comparing them raw finds almost nothing. It is still only a
     * hint: normalising harder finds more matches AND more false ones, which is exactly
     * why nothing here is applied without a human or an unambiguous pairing.
     */
    private function normalise(string $name): string
    {
        return preg_replace('/[^a-z]/', '', strtolower(trim($name))) ?? '';
    }
}
