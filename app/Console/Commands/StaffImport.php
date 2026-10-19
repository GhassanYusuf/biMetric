<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imports the staff list from HR as Employments.
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
 *   name           REQUIRED — the first name, or the whole name when there is no last_name
 *   last_name      optional — an empty cell keeps the last name already stored
 *   employee_code  REQUIRED — the payroll number, unique within one company
 *   email          optional
 *   company        optional — top-level node of the department tree (2026-10-11)
 *   department     optional — placed under `company` when both are given
 *   tenant         optional — tenant slug. Defaults to --tenant, else the only tenant.
 *
 * A `national_id` column, if present, is ignored: national ids were removed from the
 * system on 2026-09-27.
 */
class StaffImport extends Command
{
    protected $signature = 'staff:import
                            {file : Path to a CSV file with a header row}
                            {--tenant= : Tenant slug for rows that do not name one}
                            {--dry-run : Report what would happen without writing}';

    protected $description = 'Import the staff list (employments) from a CSV';

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
        $departmentsCreated = 0;
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

            $existing = Employee::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('employee_code', $code)
                ->first();

            $company = trim($row['company'] ?? '') ?: null;
            $departmentName = trim($row['department'] ?? '') ?: null;

            $attributes = [
                'name' => $name,
                'email' => trim($row['email'] ?? '') ?: null,
                // The free-text column keeps the most specific name, as it always held.
                'department' => $departmentName ?? $company,
                'is_active' => true,
            ];

            // Same rule as the tree below: an empty cell is not a request to erase.
            $lastName = trim($row['last_name'] ?? '');
            if ($lastName !== '') {
                $attributes['last_name'] = $lastName;
            }

            /*
             * The department tree. A row that names neither leaves department_id alone:
             * an empty cell is not evidence that somebody left their department, and
             * clearing it would empty the tree for any file that lacks the columns.
             */
            $node = $this->departmentFor($tenant, $company, $departmentName, $departmentsCreated);
            if ($node !== null) {
                $attributes['department_id'] = $node->id;
            }

            if ($existing !== null) {
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
        $this->line(sprintf('  %-28s %d', 'departments created', $departmentsCreated));

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
    /**
     * Find or create the tree node a row belongs to: the company at the top, the
     * department beneath it. A department with no company is a top-level node, which
     * is what every department was before the tree existed. Matched by name,
     * case-insensitively, so "Finance" and "FINANCE" in one file are one department.
     */
    private function departmentFor(Tenant $tenant, ?string $company, ?string $department, int &$createdCount): ?Department
    {
        $parent = $company !== null ? $this->findOrCreateDepartment($tenant, null, $company, $createdCount) : null;

        if ($department === null) {
            return $parent;
        }

        return $this->findOrCreateDepartment($tenant, $parent?->id, $department, $createdCount);
    }

    /** @var array<string, Department> */
    private array $departmentCache = [];

    private function findOrCreateDepartment(Tenant $tenant, ?int $parentId, string $name, int &$createdCount): Department
    {
        $key = $tenant->id.'|'.($parentId ?? '').'|'.mb_strtolower($name);

        if (isset($this->departmentCache[$key])) {
            return $this->departmentCache[$key];
        }

        $existing = Department::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->when($parentId === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parentId))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        // withoutGlobalScopes() also lifts SoftDeletes, so a removed department is
        // found here. Restoring it is the only option: the unique index on
        // (tenant, parent, name) counts deleted rows and would refuse a second one.
        if ($existing?->trashed()) {
            $existing->restore();
        }

        if ($existing === null) {
            $existing = Department::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'parent_id' => $parentId,
                'name' => $name,
            ]);
            $createdCount++;
        }

        return $this->departmentCache[$key] = $existing;
    }

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
