<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Person;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imports the staff list from HR as People and Employments.
 *
 * WHY A COMMAND AND NOT A SCREEN: the Employees screen creates one person at a time,
 * which is right for a new hire and useless for onboarding an estate. There is no bulk
 * path otherwise, and hand-entering three hundred people is how transcription errors
 * get into payroll.
 *
 * WHAT IT DOES NOT DO: it never touches a device, never creates a device identity, and
 * never claims a roster entry. Importing the staff list and deciding which device user
 * is which human are separate steps on purpose — the second one is a judgement about
 * hardware and belongs to the adoption flow.
 *
 * COLUMNS (header row required; order irrelevant; unknown columns ignored):
 *
 *   name           REQUIRED
 *   employee_code  REQUIRED — the payroll number, unique within one company
 *   national_id    optional — the HRMS join key. Without it the person is `unverified`
 *                  and their attendance cannot be exported to payroll.
 *   email          optional
 *   department     optional
 *   tenant         optional — tenant slug. Defaults to --tenant, else the only tenant.
 *
 * The same national_id appearing twice is NOT an error: it is the same human employed
 * by two sister companies, which is exactly what the Person layer exists to express.
 * They become one Person with two Employments.
 */
class StaffImport extends Command
{
    protected $signature = 'staff:import
                            {file : Path to a CSV file with a header row}
                            {--tenant= : Tenant slug for rows that do not name one}
                            {--dry-run : Report what would happen without writing}';

    protected $description = 'Import the staff list (people + employments) from a CSV';

    public function handle(): int
    {
        $path = $this->argument('file');

        if (! is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $rows = $this->readCsv($path);

        if ($rows === []) {
            $this->error('No data rows found. The first line must be a header.');

            return self::FAILURE;
        }

        /*
         * Only INSIST on a default when a row actually needs one. A file whose every row
         * names its own tenant is complete on its own, and refusing it because the
         * installation happens to have two tenants would reject the multi-company case
         * this import exists to serve.
         */
        $needsDefault = false;
        foreach ($rows as $row) {
            if (trim($row['tenant'] ?? '') === '') {
                $needsDefault = true;
                break;
            }
        }

        $defaultTenant = $this->resolveDefaultTenant($needsDefault);

        if ($needsDefault && $defaultTenant === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $created = 0;
        $updated = 0;
        $peopleCreated = 0;
        $peopleMatched = 0;
        $noNationalId = 0;
        $errors = [];

        DB::beginTransaction();

        foreach ($rows as $lineNo => $row) {
            $name = trim($row['name'] ?? '');
            $code = trim($row['employee_code'] ?? '');

            if ($name === '' || $code === '') {
                $errors[] = "line {$lineNo}: name and employee_code are both required";

                continue;
            }

            $tenant = $this->tenantFor($row, $defaultTenant);

            if ($tenant === null) {
                $errors[] = "line {$lineNo}: unknown tenant '".trim($row['tenant'] ?? '')."'";

                continue;
            }

            $nationalId = trim($row['national_id'] ?? '');
            $person = null;

            if ($nationalId !== '') {
                // The same document already in the system means the SAME HUMAN — very
                // possibly employed by a sister company. That is a match, not a clash.
                $person = Person::findByNationalId($nationalId);

                if ($person === null) {
                    $person = Person::create(['full_name' => $name, 'status' => Person::UNVERIFIED]);
                    $person->setNationalId($nationalId);
                    $peopleCreated++;
                } else {
                    $peopleMatched++;
                }
            } else {
                // No document yet. The employment is still created and attendance still
                // collects; only the payroll export is blocked (Person::isExportable()).
                $noNationalId++;
            }

            $existing = Employee::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('employee_code', $code)
                ->first();

            $attributes = [
                'name' => $name,
                'person_id' => $person?->id,
                'email' => trim($row['email'] ?? '') ?: null,
                'department' => trim($row['department'] ?? '') ?: null,
                'is_active' => true,
            ];

            if ($existing !== null) {
                // person_id is never CLEARED by a re-import. A row that omits the
                // national id this time must not detach a human we have already
                // identified — that would silently un-export their payroll.
                if ($person === null) {
                    unset($attributes['person_id']);
                }

                $existing->forceFill($attributes)->save();
                $updated++;
            } else {
                Employee::withoutGlobalScopes()->create($attributes + [
                    'tenant_id' => $tenant->id,
                    'employee_code' => $code,
                ]);
                $created++;
            }
        }

        if ($dryRun) {
            DB::rollBack();
        } else {
            DB::commit();
        }

        $this->newLine();
        $this->line(sprintf('  %-28s %d', 'rows read', count($rows)));
        $this->line(sprintf('  %-28s %d', 'employments created', $created));
        $this->line(sprintf('  %-28s %d', 'employments updated', $updated));
        $this->line(sprintf('  %-28s %d', 'people created', $peopleCreated));
        $this->line(sprintf('  %-28s %d  (same human, another company)', 'people matched by national id', $peopleMatched));
        $this->line(sprintf('  %-28s %d  (not exportable to payroll yet)', 'rows with no national id', $noNationalId));

        if ($errors !== []) {
            $this->newLine();
            $this->warn(count($errors).' row(s) skipped:');
            foreach (array_slice($errors, 0, 20) as $error) {
                $this->line("  {$error}");
            }
            if (count($errors) > 20) {
                $this->line('  … '.(count($errors) - 20).' more');
            }
        }

        $this->newLine();

        if ($dryRun) {
            $this->line('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        $this->info('Imported. Next: claim the device rosters against these employees.');
        $this->line('  php artisan device:suggest-claims {device}          review');
        $this->line('  php artisan device:suggest-claims {device} --apply  apply the unambiguous ones');
        $this->line('  php artisan device:adopt {device}                   once nothing is undecided');
        $this->line('  php artisan attendance:reattribute                  re-attach existing punches');

        return self::SUCCESS;
    }

    /**
     * @return array<int,array<string,string>> keyed by 1-based line number
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = null;
        $rows = [];
        $line = 0;

        while (($fields = fgetcsv($handle)) !== false) {
            $line++;

            if ($fields === [null] || $fields === []) {
                continue; // blank line
            }

            if ($header === null) {
                // Tolerant of case, spaces and a UTF-8 BOM, all of which survive Excel.
                $header = array_map(
                    fn ($h) => str_replace(' ', '_', strtolower(trim((string) $h, " \t\n\r\0\x0B\u{FEFF}"))),
                    $fields,
                );

                continue;
            }

            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = (string) ($fields[$i] ?? '');
            }

            $rows[$line] = $row;
        }

        fclose($handle);

        return $rows;
    }

    private function resolveDefaultTenant(bool $required): ?Tenant
    {
        if ($slug = $this->option('tenant')) {
            $tenant = Tenant::where('slug', $slug)->first();

            if ($tenant === null) {
                $this->error("No tenant with slug '{$slug}'. Known: ".Tenant::pluck('slug')->implode(', '));
            }

            return $tenant;
        }

        $tenants = Tenant::all();

        if ($tenants->count() === 1) {
            return $tenants->first();
        }

        if ($required) {
            $this->error(
                'Some rows name no tenant and more than one exists. Add a `tenant` column or pass --tenant. Known: '
                .$tenants->pluck('slug')->implode(', ')
            );
        }

        return null;
    }

    /** @param array<string,string> $row */
    private function tenantFor(array $row, ?Tenant $default): ?Tenant
    {
        $slug = trim($row['tenant'] ?? '');

        return $slug === '' ? $default : Tenant::where('slug', $slug)->first();
    }
}
