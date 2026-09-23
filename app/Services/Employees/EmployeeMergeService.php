<?php

namespace App\Services\Employees;

use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Folds several employment records into one, without touching a single device.
 *
 * THE QUESTION THIS ANSWERS: "how do you merge people who are scattered across
 * different terminals?" The answer is that they are not scattered — the DEVICE records
 * stay exactly where they are. Each terminal keeps its own user at its own PIN, with
 * its own fingerprints and its own card, untouched and unaware. What changes is which
 * employee row those per-device mappings point at.
 *
 *   before                                        after
 *   #1403 ALI JAAFAR -> IT Room PIN 654           #1403 ALI JAAFAR -> IT Room     PIN 654
 *   #1289 ALI JAAFAR -> Glass Door PIN 654                          -> Glass Door  PIN 654
 *   #969  ALI JAAFAR -> Entrance PIN 654                            -> Entrance    PIN 654
 *   #1376 ALI JAAFAR -> On The Road PIN 654                         -> On The Road PIN 654
 *                                                 #1289 #969 #1376 deleted
 *
 * employee_device_maps was built for exactly this shape: one employee, one live mapping
 * PER DEVICE. Being on four terminals is the normal case, not the problem.
 *
 * WHEN BOTH RECORDS ARE ON THE SAME TERMINAL, under different PINs, only one of those
 * can be the person's current identity there — the index says so and it is right. The
 * loser is CLOSED rather than dropped, so punches made under it still resolve to the
 * merged human. See closeCompetingMappings().
 *
 * NO DEVICE IS CONTACTED. That is what makes this safe to run against terminals that
 * are read-only or unadopted, and it is why a wrong merge destroys no enrollment — the
 * fingerprints never move, because they never leave the hardware in the first place.
 *
 * It is still not undoable from inside the app: the folded rows are gone and their
 * attendance now points elsewhere. Take a copy of the database first.
 */
class EmployeeMergeService
{
    /**
     * Tables carrying an employee_id that must follow the person.
     *
     * attendance_logs is the one that matters: a punch belongs to the human, and its
     * raw device_user_id is untouched, so attendance:reattribute can always recompute
     * attribution from the mappings afterwards.
     */
    private const OWNED_BY_EMPLOYEE = [
        'employee_device_maps',
        'attendance_logs',
        'biometric_templates',
        'access_cards',
        'enrollment_sessions',
        'attendance_waivers',
    ];

    /**
     * @param  list<int>  $mergeIds
     * @return array{keep:int, merged:int, rows:array<string,int>}
     */
    public function merge(int $keepId, array $mergeIds, ?int $actorId = null): array
    {
        $mergeIds = array_values(array_filter($mergeIds, fn ($id) => $id !== $keepId));

        if ($mergeIds === []) {
            return ['keep' => $keepId, 'merged' => 0, 'rows' => []];
        }

        $keeper = Employee::withoutGlobalScopes()->findOrFail($keepId);
        $folded = Employee::withoutGlobalScopes()->whereIn('id', $mergeIds)->get();

        if ($folded->count() !== count($mergeIds)) {
            throw new \RuntimeException('One of the records to merge no longer exists.');
        }

        foreach ($folded as $f) {
            if ($f->tenant_id !== $keeper->tenant_id) {
                // Two companies employing one human is the Person layer's job, not this
                // one. Merging the EMPLOYMENTS would erase the fact that they hold two.
                throw new \RuntimeException(
                    "Refusing to merge #{$f->id} into #{$keeper->id}: they belong to different companies. "
                    .'Link them through a shared national ID instead.'
                );
            }
        }

        $rows = [];

        DB::transaction(function () use ($keeper, $folded, $mergeIds, $actorId, &$rows) {
            $rows['mappings_closed'] = $this->closeCompetingMappings($keeper->id, $mergeIds);

            foreach (self::OWNED_BY_EMPLOYEE as $table) {
                $rows[$table] = DB::table($table)
                    ->whereIn('employee_id', $mergeIds)
                    ->update(['employee_id' => $keeper->id]);
            }

            // The roster entry records who an operator said a device user is. It has its
            // own column name and must follow too, or the adoption screen would show the
            // slot claimed by a record that no longer exists.
            $rows['device_roster_entries'] = DB::table('device_roster_entries')
                ->whereIn('claimed_employee_id', $mergeIds)
                ->update(['claimed_employee_id' => $keeper->id]);

            $this->inheritMissingFields($keeper, $folded);

            AuditLog::create([
                'tenant_id' => $keeper->tenant_id,
                'user_id' => $actorId,
                'action' => 'employee.merged',
                'auditable_type' => Employee::class,
                'auditable_id' => $keeper->id,
                'changes' => [
                    'kept' => ['id' => $keeper->id, 'name' => $keeper->name, 'code' => $keeper->employee_code],
                    'merged' => $folded->map(fn (Employee $e) => [
                        'id' => $e->id, 'name' => $e->name, 'code' => $e->employee_code,
                    ])->all(),
                    'rows_moved' => $rows,
                ],
            ]);

            Employee::withoutGlobalScopes()->whereIn('id', $mergeIds)->delete();
        });

        return ['keep' => $keeper->id, 'merged' => count($mergeIds), 'rows' => $rows];
    }

    /**
     * Leaves exactly ONE live mapping per device before the rows are re-pointed.
     *
     * `employee_device_maps` carries a partial unique index — one live mapping per
     * (device, employee) — and two duplicate records enrolled on the SAME terminal
     * under different PINs both satisfy it separately and violate it the instant they
     * become one employee. Without this the merge died on a constraint error, which
     * is how it was found: a 500 with SQL in it, in the middle of a destructive
     * operation.
     *
     * The index is right and the fix is not to relax it. A person holds ONE current
     * PIN on one terminal; when two records claim that terminal, the hardware really
     * does hold two user records for one human, and the app has to name one as
     * current.
     *
     * WHICH SURVIVES: the keeper's own, when it has one — its device identity is the
     * one the merge is keeping. Otherwise the most recently confirmed by the device,
     * because that is the one the terminal last told us about.
     *
     * The losers are CLOSED, never deleted (see EmployeeDeviceMap). After the merge
     * every one of these rows points at the keeper, so the old punches made under the
     * closed PIN still resolve to the same human — which is the whole point of the
     * merge, and would be lost if the row were dropped.
     *
     * @param  list<int>  $mergeIds
     */
    private function closeCompetingMappings(int $keepId, array $mergeIds): int
    {
        $live = DB::table('employee_device_maps')
            ->whereIn('employee_id', array_merge([$keepId], $mergeIds))
            ->whereNull('valid_to')
            ->orderByDesc('synced_at')
            ->orderByDesc('id')
            ->get(['id', 'device_id', 'employee_id']);

        $closed = 0;

        foreach ($live->groupBy('device_id') as $onDevice) {
            if ($onDevice->count() < 2) {
                continue;
            }

            $survivor = $onDevice->firstWhere('employee_id', $keepId) ?? $onDevice->first();

            $losers = $onDevice->where('id', '!=', $survivor->id)->pluck('id');

            $closed += DB::table('employee_device_maps')
                ->whereIn('id', $losers)
                ->update([
                    'valid_to' => now(),
                    'closed_reason' => "superseded by the merge into employee #{$keepId}",
                    'updated_at' => now(),
                ]);
        }

        return $closed;
    }

    /**
     * Fills gaps on the keeper from the records being folded in.
     *
     * Only ever fills what is NULL. A keeper that already holds a value keeps it —
     * overwriting would mean choosing between two device snapshots with no basis, and
     * the per-device mappings are the honest answer either way.
     */
    private function inheritMissingFields(Employee $keeper, $folded): void
    {
        $fill = [];

        foreach (['person_id', 'email', 'department', 'department_id', 'photo_path', 'card_number'] as $field) {
            if ($keeper->{$field} !== null) {
                continue;
            }

            $donor = $folded->first(fn (Employee $e) => $e->{$field} !== null);

            if ($donor !== null) {
                $fill[$field] = $donor->{$field};
            }
        }

        if ($fill !== []) {
            $keeper->forceFill($fill)->save();
        }
    }
}
