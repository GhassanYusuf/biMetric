<?php

namespace App\Services\Attendance;

use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Puts the person's CPR into device_user_id on their stored punches (owner, 2026-11-05: "on
 * punches from September onwards replace the values of the device user id to be the CPR,
 * so it shows in the HR system", then "build it as a feature in the attendance page").
 * Used by `attendance:personnel-ids` and by Attendance → Personnel IDs on punches.
 *
 * A punch is changed only when it is LINKED to an employee whose Personnel ID is a CPR (nine
 * digits), it is on or after the chosen office-local day, it was recorded under another
 * number, and it was never changed before. The number the terminal recorded is kept in
 * original_device_user_id, which the sync, the ZKAccess import and PunchAttribution read, so
 * nothing the terminal said is lost and re-reading it imports nothing twice. Terminals are
 * not touched.
 *
 * apply() saves every row it will change to storage/app/private/personnel-ids/ first, changes
 * them in one transaction and reads each one back: one difference rolls the whole change back.
 * Each run is an audit_logs row (rule 13), which is also the list Undo is offered from.
 *
 * Scope: company_id, department_id ('none' = the company's staff with no department),
 * employee_code. At least one. tenant_id limits everything to one tenant (null in the
 * console, where there is one).
 */
class PersonnelIdReplacer
{
    public const APPLIED = 'attendance.personnel_ids_applied';

    public const UNDONE = 'attendance.personnel_ids_undone';

    /**
     * @param  array{company_id?:?int, department_id?:int|string|null, employee_code?:?string}  $scope
     * @return array{people: list<array>, changes: int, taken: int, no_cpr: list<array>, from_utc: string}
     */
    public function preview(array $scope, string $fromDay, ?int $tenantId): array
    {
        $from = $this->fromUtc($fromDay);
        $rows = $this->candidates($scope, $from, $tenantId);
        $taken = $this->takenKeys($rows);
        $changes = array_values(array_filter($rows, fn ($r) => ! isset($taken[(int) $r->id])));

        $byEmployee = [];
        foreach ($changes as $r) {
            $byEmployee[(int) $r->employee_id]['count'] = ($byEmployee[(int) $r->employee_id]['count'] ?? 0) + 1;
            $byEmployee[(int) $r->employee_id]['old'][(string) $r->device_user_id] = true;
        }

        $people = array_map(function ($p) use ($byEmployee) {
            // array_keys() turns "701" into 701; a terminal number is text ("041…" keeps its zero).
            $old = array_map('strval', array_keys($byEmployee[(int) $p->id]['old'] ?? []));
            sort($old);

            return [
                'id' => (int) $p->id,
                'name' => trim($p->name.' '.$p->last_name),
                'employee_code' => (string) $p->employee_code,
                'is_cpr' => self::isCpr($p->employee_code),
                'punches' => (int) $p->punches,
                'already' => (int) $p->already,
                'to_change' => (int) ($byEmployee[(int) $p->id]['count'] ?? 0),
                'recorded_as' => $old,
            ];
        }, $this->people($scope, $from, $tenantId));

        return [
            'people' => $people,
            'changes' => count($changes),
            'taken' => count($taken),
            'no_cpr' => array_values(array_filter($people, fn ($p) => ! $p['is_cpr'] && $p['punches'] > 0)),
            'from_utc' => $from->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array{changed:int, backup:?string, audit_id:?int}
     */
    public function apply(array $scope, string $fromDay, ?int $tenantId, string $label, ?int $userId = null): array
    {
        $from = $this->fromUtc($fromDay);
        $rows = $this->candidates($scope, $from, $tenantId);
        $taken = $this->takenKeys($rows);
        $changes = array_values(array_filter($rows, fn ($r) => ! isset($taken[(int) $r->id])));

        if ($changes === []) {
            return ['changed' => 0, 'backup' => null, 'audit_id' => null];
        }

        $file = 'personnel-ids/'.now()->format('Ymd-His').'-'.trim(preg_replace('/[^A-Za-z0-9]+/', '-', $label), '-').'.json';
        Storage::disk('local')->put($file, json_encode([
            'scope' => $label,
            'tenant_id' => $tenantId,
            'from' => $fromDay,
            'taken_at' => now()->toIso8601String(),
            'rows' => array_map(fn ($r) => [
                'id' => (int) $r->id, 'employee_id' => (int) $r->employee_id,
                'device_user_id' => (string) $r->device_user_id, 'cpr' => (string) $r->employee_code,
            ], $changes),
        ], JSON_PRETTY_PRINT));

        DB::transaction(function () use ($changes) {
            $now = now();
            foreach (collect($changes)->groupBy('employee_code') as $cpr => $group) {
                foreach ($group->pluck('id')->chunk(1000) as $ids) {
                    // Only rows still as they were read: never overwrite a second time.
                    DB::table('attendance_logs')->whereIn('id', $ids->all())->whereNull('original_device_user_id')
                        ->update([
                            'original_device_user_id' => DB::raw('device_user_id'),
                            'device_user_id' => (string) $cpr,
                            // updated_at moves so the attendance live stamp does (rule 18).
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

        $audit = AuditLog::create([
            'tenant_id' => $tenantId ?? DB::table('employees')->where('id', $changes[0]->employee_id)->value('tenant_id'),
            'user_id' => $userId,
            'action' => self::APPLIED,
            'auditable_type' => null,
            'auditable_id' => null,
            'changes' => [
                'scope' => $label, 'from' => $fromDay, 'punches' => count($changes),
                'employees' => collect($changes)->pluck('employee_id')->unique()->count(),
                'backup' => $file,
            ],
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'user_agent' => app()->runningInConsole() ? null : substr((string) request()->userAgent(), 0, 255),
        ]);

        return ['changed' => count($changes), 'backup' => $file, 'audit_id' => (int) $audit->id];
    }

    /**
     * Gives back the number the terminal recorded, for every punch a backup file lists and
     * that still carries the change. A punch changed since is left alone.
     *
     * @return array{restored:int, total:int}
     */
    public function undo(string $file, ?int $tenantId, ?int $userId = null, ?int $appliedAuditId = null): array
    {
        $full = is_file($file) ? $file : Storage::disk('local')->path($file);
        if (! is_file($full)) {
            throw new \RuntimeException('The saved copy of that change is missing, so it cannot be undone.');
        }
        $backup = json_decode((string) file_get_contents($full), true, flags: JSON_THROW_ON_ERROR);
        if ($tenantId !== null && isset($backup['tenant_id']) && (int) $backup['tenant_id'] !== $tenantId) {
            throw new \RuntimeException('That change belongs to another company account.');
        }

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

        AuditLog::create([
            'tenant_id' => $tenantId ?? $backup['tenant_id'] ?? DB::table('tenants')->value('id'),
            'user_id' => $userId,
            'action' => self::UNDONE,
            'auditable_type' => null,
            'auditable_id' => null,
            'changes' => ['scope' => $backup['scope'] ?? null, 'restored' => $restored, 'of' => count($backup['rows']), 'backup' => $file, 'applied_audit_id' => $appliedAuditId],
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'user_agent' => app()->runningInConsole() ? null : substr((string) request()->userAgent(), 0, 255),
        ]);

        return ['restored' => $restored, 'total' => count($backup['rows'])];
    }

    /** The office-local start of a day, in UTC, as the punches are stored (rule 10). */
    public function fromUtc(string $day): Carbon
    {
        return Carbon::parse($day, config('attendance.timezone'))->startOfDay()->utc();
    }

    public static function isCpr(?string $code): bool
    {
        return (bool) preg_match('/^\d{9}$/', (string) $code);
    }

    private function employees(array $scope, ?int $tenantId)
    {
        $department = $scope['department_id'] ?? null;

        return DB::table('employees as e')
            ->when($tenantId !== null, fn ($q) => $q->where('e.tenant_id', $tenantId))
            ->when(! empty($scope['company_id']), fn ($q) => $q->where('e.company_id', (int) $scope['company_id']))
            ->when($department === 'none', fn ($q) => $q->whereNull('e.department_id'))
            ->when($department !== null && $department !== 'none' && $department !== '', fn ($q) => $q->where('e.department_id', (int) $department))
            ->when(! empty($scope['employee_code']), fn ($q) => $q->where('e.employee_code', (string) $scope['employee_code']));
    }

    /** Punches that would change: linked, CPR Personnel ID, recorded under another number, never replaced. */
    private function candidates(array $scope, Carbon $from, ?int $tenantId): array
    {
        return $this->employees($scope, $tenantId)
            ->join('attendance_logs as l', 'l.employee_id', '=', 'e.id')
            ->where('l.punched_at', '>=', $from->format('Y-m-d H:i:s'))
            ->whereNull('l.original_device_user_id')
            ->whereColumn('l.device_user_id', '<>', 'e.employee_code')
            ->orderBy('l.id')
            ->get(['l.id', 'l.employee_id', 'l.device_id', 'l.device_user_id', 'l.punched_at', 'l.verify_method', 'e.employee_code'])
            ->filter(fn ($r) => self::isCpr($r->employee_code))
            ->values()->all();
    }

    private function people(array $scope, Carbon $from, ?int $tenantId): array
    {
        $since = $from->format('Y-m-d H:i:s');

        return $this->employees($scope, $tenantId)
            ->select(['e.id', 'e.name', 'e.last_name', 'e.employee_code'])
            ->selectSub(fn ($q) => $q->from('attendance_logs')->whereColumn('employee_id', 'e.id')->where('punched_at', '>=', $since)->selectRaw('COUNT(*)'), 'punches')
            ->selectSub(fn ($q) => $q->from('attendance_logs')->whereColumn('employee_id', 'e.id')->where('punched_at', '>=', $since)->whereColumn('device_user_id', 'e.employee_code')->selectRaw('COUNT(*)'), 'already')
            ->orderBy('e.name')
            ->get()->all();
    }

    /**
     * Candidates whose CPR key is already a stored punch (the same punch stored under both
     * numbers). Changing them would break the unique index, so they are left and counted.
     *
     * @return array<int,true>
     */
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
}
