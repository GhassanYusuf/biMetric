<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Puts the person's CPR into device_user_id on their stored punches (owner, 2026-11-05: "on
 * punches from September onwards replace the values of the device user id to be the CPR,
 * so it shows in the HR system"). Run company by company, or department by department.
 *
 * A punch is changed only when it is linked to an employee whose Personnel ID is a CPR (nine
 * digits) and it was recorded under another number. The number the terminal recorded is
 * kept in original_device_user_id, which the sync, the ZKAccess import and PunchAttribution
 * read, so nothing the terminal said is lost and re-reading it imports nothing twice.
 * The terminals themselves are not touched.
 *
 *   attendance:personnel-ids --company=PROMOSEVEN --department=Catering        (shows, writes nothing)
 *   attendance:personnel-ids --company=PROMOSEVEN --department=Catering --apply
 *   attendance:personnel-ids --undo=storage/app/personnel-ids/<file>.json
 *
 * --apply saves every row it will change to storage/app/personnel-ids/ first, changes them in
 * one transaction, and checks each one after; any difference rolls the whole change back.
 */
class AttendancePersonnelIds extends Command
{
    protected $signature = 'attendance:personnel-ids
                            {--company= : Company name or id}
                            {--department= : Department name or id (within --company when both are given)}
                            {--employee= : One employee, by Personnel ID}
                            {--from=2026-09-01 : First office-local day whose punches change}
                            {--apply : Write the change (without it, only shows what would change)}
                            {--undo= : Put back the numbers saved in this backup file}';

    protected $description = 'Replace device_user_id on stored punches with the employee\'s CPR (the terminal\'s number is kept)';

    public function handle(): int
    {
        if ($this->option('undo')) {
            return $this->undo((string) $this->option('undo'));
        }

        if (! $this->option('company') && ! $this->option('department') && ! $this->option('employee')) {
            $this->error('Name a --company, a --department or an --employee: this runs one group at a time.');

            return self::FAILURE;
        }

        $scope = $this->scope();
        if ($scope === null) {
            return self::FAILURE;
        }

        $from = Carbon::parse((string) $this->option('from'), config('attendance.timezone'))->startOfDay()->utc();
        $this->line("Scope: {$scope['label']}; punches from {$this->option('from')} (office time, {$from->format('Y-m-d H:i')} UTC).");

        $rows = $this->candidates($scope, $from);
        $people = $this->people($scope, $from);

        // A row whose new key is already taken (the same punch stored twice, once under each
        // number) is left as it is and reported: changing it would break the unique index.
        $taken = $this->takenKeys($rows);
        $changes = array_values(array_filter($rows, fn ($r) => ! isset($taken[$r->id])));

        $this->table(
            ['Employee', 'Personnel ID', 'CPR?', 'Punches', 'Already CPR', 'To change', 'Recorded as'],
            array_map(function ($p) use ($changes) {
                $mine = array_filter($changes, fn ($r) => (int) $r->employee_id === (int) $p->id);
                $old = array_unique(array_map(fn ($r) => $r->device_user_id, $mine));
                sort($old);

                return [
                    trim($p->name.' '.$p->last_name), $p->employee_code, self::isCpr($p->employee_code) ? 'yes' : 'NO',
                    $p->punches, $p->already, count($mine), implode(', ', $old) ?: '-',
                ];
            }, $people),
        );

        $noCpr = array_filter($people, fn ($p) => ! self::isCpr($p->employee_code) && $p->punches > 0);
        if ($noCpr !== []) {
            $this->warn(count($noCpr).' employee(s) with punches have no CPR as Personnel ID; their punches are not changed until it is entered.');
        }
        if ($taken !== []) {
            $this->warn(count($taken).' punch(es) left as they are: the same punch is already stored under the CPR.');
        }
        $this->line(count($changes).' punch(es) to change.');

        if (! $this->option('apply')) {
            $this->line('Nothing written. Add --apply to make this change.');

            return self::SUCCESS;
        }
        if ($changes === []) {
            return self::SUCCESS;
        }

        $file = 'personnel-ids/'.now()->format('Ymd-His').'-'.preg_replace('/[^A-Za-z0-9]+/', '-', $scope['label']).'.json';
        Storage::disk('local')->put($file, json_encode([
            'scope' => $scope['label'],
            'from' => (string) $this->option('from'),
            'taken_at' => now()->toIso8601String(),
            'rows' => array_map(fn ($r) => [
                'id' => (int) $r->id, 'employee_id' => (int) $r->employee_id,
                'device_user_id' => (string) $r->device_user_id, 'cpr' => (string) $r->employee_code,
            ], $changes),
        ], JSON_PRETTY_PRINT));
        $this->line('Saved first: '.Storage::disk('local')->path($file));

        DB::transaction(function () use ($changes) {
            $now = now();
            foreach (collect($changes)->groupBy('employee_code') as $cpr => $group) {
                foreach ($group->pluck('id')->chunk(1000) as $ids) {
                    // Only rows still as they were read: never overwrite a second time.
                    DB::table('attendance_logs')->whereIn('id', $ids->all())->whereNull('original_device_user_id')
                        ->update([
                            'original_device_user_id' => DB::raw('device_user_id'),
                            'device_user_id' => (string) $cpr,
                            'updated_at' => $now,
                        ]);
                }
            }

            $wrong = 0;
            foreach (collect($changes)->chunk(1000) as $chunk) {
                $now = DB::table('attendance_logs')->whereIn('id', $chunk->pluck('id')->all())
                    ->get(['id', 'device_user_id', 'original_device_user_id'])->keyBy('id');
                foreach ($chunk as $r) {
                    $row = $now->get($r->id);
                    if (! $row || (string) $row->device_user_id !== (string) $r->employee_code || (string) $row->original_device_user_id !== (string) $r->device_user_id) {
                        $wrong++;
                    }
                }
            }
            if ($wrong > 0) {
                throw new \RuntimeException("{$wrong} punch(es) did not read back as changed; nothing was changed.");
            }
        });

        $this->info(count($changes).' punch(es) changed and checked. To undo: php artisan attendance:personnel-ids --undo='.Storage::disk('local')->path($file));

        return self::SUCCESS;
    }

    private function undo(string $path): int
    {
        $full = is_file($path) ? $path : Storage::disk('local')->path($path);
        if (! is_file($full)) {
            $this->error("No backup file at {$path}.");

            return self::FAILURE;
        }
        $backup = json_decode((string) file_get_contents($full), true, flags: JSON_THROW_ON_ERROR);

        $restored = 0;
        DB::transaction(function () use ($backup, &$restored) {
            $now = now();
            foreach (collect($backup['rows'])->groupBy('device_user_id') as $original => $group) {
                foreach ($group->pluck('id')->chunk(1000) as $ids) {
                    $restored += DB::table('attendance_logs')->whereIn('id', $ids->all())
                        ->where('original_device_user_id', (string) $original)
                        ->update(['device_user_id' => (string) $original, 'original_device_user_id' => null, 'updated_at' => $now]);
                }
            }
        });

        $this->info("{$restored} of ".count($backup['rows']).' punch(es) given back the number the terminal recorded.');

        return self::SUCCESS;
    }

    /** @return array{label:string, company:?int, department:?int, employee:?string}|null */
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
            $department = DB::table('departments')->whereNull('deleted_at')
                ->when($company, fn ($q) => $q->where('company_id', $company))
                ->where(fn ($q) => $q->where('name', $d)->orWhere('id', ctype_digit($d) ? (int) $d : 0))->value('id');
            if (! $department) {
                $this->error("No department \"{$d}\"".($company ? ' in that company' : '').'.');

                return null;
            }
            $label[] = DB::table('departments')->where('id', $department)->value('name');
        }
        $employee = $this->option('employee') ? (string) $this->option('employee') : null;
        if ($employee !== null) {
            $label[] = $employee;
        }

        return ['label' => implode(' - ', $label), 'company' => $company, 'department' => $department, 'employee' => $employee];
    }

    private function employees(array $scope)
    {
        return DB::table('employees as e')
            ->when($scope['company'], fn ($q) => $q->where('e.company_id', $scope['company']))
            ->when($scope['department'], fn ($q) => $q->where('e.department_id', $scope['department']))
            ->when($scope['employee'] !== null, fn ($q) => $q->where('e.employee_code', $scope['employee']));
    }

    /** Punches that would change: linked, CPR Personnel ID, recorded under another number, never replaced. */
    private function candidates(array $scope, Carbon $from): array
    {
        return $this->employees($scope)
            ->join('attendance_logs as l', 'l.employee_id', '=', 'e.id')
            ->where('l.punched_at', '>=', $from->format('Y-m-d H:i:s'))
            ->whereNull('l.original_device_user_id')
            ->whereColumn('l.device_user_id', '<>', 'e.employee_code')
            ->orderBy('l.id')
            ->get(['l.id', 'l.employee_id', 'l.device_id', 'l.device_user_id', 'l.punched_at', 'l.verify_method', 'e.employee_code'])
            ->filter(fn ($r) => self::isCpr($r->employee_code))
            ->values()->all();
    }

    private function people(array $scope, Carbon $from): array
    {
        $since = $from->format('Y-m-d H:i:s');

        return $this->employees($scope)
            ->select(['e.id', 'e.name', 'e.last_name', 'e.employee_code'])
            ->selectSub(fn ($q) => $q->from('attendance_logs')->whereColumn('employee_id', 'e.id')->where('punched_at', '>=', $since)->selectRaw('COUNT(*)'), 'punches')
            ->selectSub(fn ($q) => $q->from('attendance_logs')->whereColumn('employee_id', 'e.id')->where('punched_at', '>=', $since)->whereColumn('device_user_id', 'e.employee_code')->selectRaw('COUNT(*)'), 'already')
            ->orderBy('e.name')
            ->get()->all();
    }

    /** @return array<int,true> ids of candidates whose CPR key is already a stored punch */
    private function takenKeys(array $rows): array
    {
        $taken = [];
        foreach (collect($rows)->groupBy(fn ($r) => $r->device_id.'|'.$r->employee_code) as $key => $group) {
            [$device, $cpr] = explode('|', $key, 2);
            $existing = DB::table('attendance_logs')->where('device_id', (int) $device)->where('device_user_id', $cpr)
                ->where('punched_at', '>=', $group->min('punched_at'))->where('punched_at', '<=', $group->max('punched_at'))
                ->get(['punched_at', 'verify_method'])
                ->mapWithKeys(fn ($e) => [substr((string) $e->punched_at, 0, 19).'|'.$e->verify_method => true]);
            foreach ($group as $r) {
                if (isset($existing[substr((string) $r->punched_at, 0, 19).'|'.$r->verify_method])) {
                    $taken[(int) $r->id] = true;
                }
            }
        }

        return $taken;
    }

    private static function isCpr(?string $code): bool
    {
        return (bool) preg_match('/^\d{9}$/', (string) $code);
    }
}
