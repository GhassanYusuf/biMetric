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
     * Every column a fold is allowed to fill in on the survivor.
     *
     * THE POINT OF THIS LIST: nothing is lost when two records become one. The
     * keeper keeps whatever it already holds and everything the folded records
     * knew that it did not comes across with them. Before this list existed only
     * six fields were inherited, so a merge silently discarded the phone number,
     * address, job title and everything else on the losing row — and since the
     * losing row is deleted, discarded permanently.
     *
     * Deliberately EXCLUDED:
     *  - id, tenant_id: identity, never inherited.
     *  - is_active, employee_code, name: not nullable, so there is no gap to fill.
     *  - device_password: a credential for the terminals, not profile data.
     *  - fingerprint_count, fingerprint_slots, enrollment_synced_at, card_number's
     *    live twin in employee_device_maps: these are a snapshot of whichever
     *    terminal was read last and the per-device mapping is the honest answer.
     *    card_number IS filled, matching the six fields this replaced — the roster
     *    column shows it and a fold that dropped it would lose a visible value.
     */
    public const MERGEABLE_FIELDS = [
        'person_id',
        'email',
        'department',
        'department_id',
        'photo_path',
        'card_number',
        'last_name',
        'gender',
        'mobile_phone',
        'hired_date',
        'birthday',
        'job_title',
        'office',
        'education',
        'country',
        'city',
        'postal_code',
        'home_phone',
        'political_status',
        'race',
        'home_address',
        'work_address',
        'multi_card_group_id',
        'access_valid_from',
        'access_valid_to',
    ];

    /** Column => how it reads on screen, for the preview and the audit entry. */
    public const FIELD_LABELS = [
        'person_id' => 'Person ID',
        'email' => 'Email',
        'department' => 'Department',
        'department_id' => 'Department',
        'photo_path' => 'Photo',
        'card_number' => 'Card number',
        'last_name' => 'Last name',
        'gender' => 'Gender',
        'mobile_phone' => 'Mobile phone',
        'hired_date' => 'Hired date',
        'birthday' => 'Birthday',
        'job_title' => 'Job title',
        'office' => 'Office',
        'education' => 'Education',
        'country' => 'Country',
        'city' => 'City',
        'postal_code' => 'Postal code',
        'home_phone' => 'Home phone',
        'political_status' => 'Political status',
        'race' => 'Race',
        'home_address' => 'Home address',
        'work_address' => 'Work address',
        'multi_card_group_id' => 'Multi-card group',
        'access_valid_from' => 'Access valid from',
        'access_valid_to' => 'Access valid to',
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
                    "Refusing to merge #{$f->id} into #{$keeper->id}: they belong to different companies."
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

            $plan = $this->plan($keeper, $folded);
            $this->applyPlan($keeper, $plan);

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
                    // What came across, and what did not because the survivor
                    // already held something different. The second list is the one
                    // that matters: without it a differing phone number on the
                    // folded record would vanish with no trace of ever existing.
                    'fields_filled' => $plan['filled'],
                    'field_conflicts' => $plan['conflicts'],
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
     * What a fold would change on the survivor, without changing anything.
     *
     * Used by the scan screen to show the operator what a merge brings across
     * BEFORE they commit to something that cannot be undone from inside the app.
     *
     * @param  list<int>  $mergeIds
     * @return array{fill: array<string,mixed>, filled: list<array>, conflicts: list<array>}
     */
    public function preview(int $keepId, array $mergeIds): array
    {
        $mergeIds = array_values(array_filter($mergeIds, fn ($id) => $id !== $keepId));

        if ($mergeIds === []) {
            return ['fill' => [], 'filled' => [], 'conflicts' => []];
        }

        // Scoped lookups, so a foreign tenant's employee id cannot be named here
        // and another company's data cannot be previewed for a merge.
        $keeper = Employee::findOrFail($keepId);
        $folded = Employee::whereIn('id', $mergeIds)->get();

        return $this->plan($keeper, $folded);
    }

    /**
     * Splits every mergeable column into what would be filled and what would be
     * left alone because the survivor already holds a DIFFERENT value.
     *
     * @param  \Illuminate\Support\Collection<int, Employee>  $folded
     * @return array{fill: array<string,mixed>, filled: list<array>, conflicts: list<array>}
     */
    private function plan(Employee $keeper, $folded): array
    {
        $fill = [];
        $filled = [];
        $conflicts = [];

        foreach (self::MERGEABLE_FIELDS as $field) {
            $label = self::FIELD_LABELS[$field] ?? $field;

            if (! $this->missing($keeper->{$field})) {
                /*
                 * The survivor already has a value. Anything the folded records
                 * hold for this column that differs is a LOSS when the rows are
                 * deleted, so it is named rather than dropped silently. The
                 * operator chose which record to keep, and this is the price of
                 * that choice, stated up front.
                 */
                foreach ($folded as $e) {
                    if ($this->missing($e->{$field}) || (string) $e->{$field} === (string) $keeper->{$field}) {
                        continue;
                    }

                    $conflicts[] = [
                        'field' => $field,
                        'label' => $label,
                        'keep' => $this->presentable($keeper->{$field}),
                        'other' => $this->presentable($e->{$field}),
                        'other_id' => $e->id,
                        'other_name' => $e->name,
                    ];
                }

                continue;
            }

            $donor = $folded->first(fn (Employee $e) => ! $this->missing($e->{$field}));

            if ($donor === null) {
                continue;
            }

            $fill[$field] = $donor->{$field};
            $filled[] = [
                'field' => $field,
                'label' => $label,
                'value' => $this->presentable($donor->{$field}),
                'from_id' => $donor->id,
                'from_name' => $donor->name,
            ];
        }

        return ['fill' => $fill, 'filled' => $filled, 'conflicts' => $conflicts];
    }

    /** Nothing to inherit where there is nothing there — an empty string counts. */
    private function missing(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * Dates come back from the model as objects; the scan screen and the audit
     * log both want the string an operator would recognise.
     */
    private function presentable(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return $value;
    }

    /** @param  \Illuminate\Support\Collection<int, Employee>  $folded */
    private function applyPlan(Employee $keeper, array $plan): void
    {
        if ($plan['fill'] !== []) {
            $keeper->forceFill($plan['fill'])->save();
        }
    }
}
