<?php

namespace App\Console\Commands;

use App\Services\Attendance\PersonnelIdReplacer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The console form of Attendance → Personnel IDs on punches: the CPR into device_user_id on
 * stored punches, the terminal's number kept (owner, 2026-11-05). The rules are in
 * PersonnelIdReplacer; this resolves names to ids and prints.
 *
 *   attendance:personnel-ids --company=PROMOSEVEN --department=Catering        (shows, writes nothing)
 *   attendance:personnel-ids --company=PROMOSEVEN --department=none --apply    (the company's staff with no department)
 *   attendance:personnel-ids --undo=storage/app/private/personnel-ids/<file>.json
 */
class AttendancePersonnelIds extends Command
{
    protected $signature = 'attendance:personnel-ids
                            {--company= : Company name or id}
                            {--department= : Department name or id (within --company when both are given); "none" = no department}
                            {--employee= : One employee, by Personnel ID}
                            {--from=2026-09-01 : First office-local day whose punches change}
                            {--apply : Write the change (without it, only shows what would change)}
                            {--undo= : Put back the numbers saved in this backup file}';

    protected $description = 'Replace device_user_id on stored punches with the employee\'s CPR (the terminal\'s number is kept)';

    public function handle(PersonnelIdReplacer $replacer): int
    {
        if ($this->option('undo')) {
            try {
                $r = $replacer->undo((string) $this->option('undo'), null);
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->info("{$r['restored']} of {$r['total']} punch(es) given back the number the terminal recorded.");

            return self::SUCCESS;
        }

        if (! $this->option('company') && ! $this->option('department') && ! $this->option('employee')) {
            $this->error('Name a --company, a --department or an --employee: this runs one group at a time.');

            return self::FAILURE;
        }

        $scope = $this->scope();
        if ($scope === null) {
            return self::FAILURE;
        }
        $from = (string) $this->option('from');

        $preview = $replacer->preview($scope, $from, null);
        $this->line("Scope: {$scope['label']}; punches from {$from} (office time, ".substr($preview['from_utc'], 0, 16).' UTC).');
        $this->table(
            ['Employee', 'Personnel ID', 'CPR?', 'Punches', 'Already CPR', 'To change', 'Recorded as'],
            array_map(fn ($p) => [
                $p['name'], $p['employee_code'], $p['is_cpr'] ? 'yes' : 'NO', $p['punches'], $p['already'],
                $p['to_change'], implode(', ', $p['recorded_as']) ?: '-',
            ], $preview['people']),
        );
        if ($preview['no_cpr'] !== []) {
            $this->warn(count($preview['no_cpr']).' employee(s) with punches have no CPR as Personnel ID; their punches are not changed until it is entered.');
        }
        if ($preview['taken'] > 0) {
            $this->warn("{$preview['taken']} punch(es) left as they are: the same punch is already stored under the CPR.");
        }
        $this->line("{$preview['changes']} punch(es) to change.");

        if (! $this->option('apply')) {
            $this->line('Nothing written. Add --apply to make this change.');

            return self::SUCCESS;
        }
        if ($preview['changes'] === 0) {
            return self::SUCCESS;
        }

        $result = $replacer->apply($scope, $from, null, $scope['label']);
        $path = Storage::disk('local')->path((string) $result['backup']);
        $this->line("Saved first: {$path}");
        $this->info("{$result['changed']} punch(es) changed and checked. To undo: php artisan attendance:personnel-ids --undo={$path}");

        return self::SUCCESS;
    }

    /** @return array{label:string, company_id:?int, department_id:int|string|null, employee_code:?string}|null */
    private function scope(): ?array
    {
        $company = null;
        $department = null;
        $label = [];

        if ($c = $this->option('company')) {
            $company = DB::table('companies')->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('name', $c)->orWhere('id', ctype_digit($c) ? (int) $c : 0))->value('id');
            if (! $company) {
                $this->error("No company \"{$c}\".");

                return null;
            }
            $label[] = DB::table('companies')->where('id', $company)->value('name');
        }
        if ($d = $this->option('department')) {
            if (strtolower($d) === 'none') {
                $department = 'none';
                $label[] = 'no department';
            } else {
                $department = DB::table('departments')->whereNull('deleted_at')
                    ->when($company, fn ($q) => $q->where('company_id', $company))
                    ->where(fn ($q) => $q->where('name', $d)->orWhere('id', ctype_digit($d) ? (int) $d : 0))->value('id');
                if (! $department) {
                    $this->error("No department \"{$d}\"".($company ? ' in that company' : '').'.');

                    return null;
                }
                $label[] = DB::table('departments')->where('id', $department)->value('name');
            }
        }
        $employee = $this->option('employee') ? (string) $this->option('employee') : null;
        if ($employee !== null) {
            $label[] = $employee;
        }

        return ['label' => implode(' - ', $label), 'company_id' => $company ? (int) $company : null, 'department_id' => $department === 'none' ? 'none' : ($department ? (int) $department : null), 'employee_code' => $employee];
    }
}
