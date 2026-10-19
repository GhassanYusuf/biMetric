<?php

namespace App\Services\Employees;

use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Duplicate candidates that the device-evidence finder cannot see, because they
 * never touched a terminal together.
 *
 * DuplicateEmployeeFinder groups records by "this name at this PIN on two or more
 * devices" — the import artefact. It is deliberately narrow: a name is a 24-byte
 * device field and matching on it fuses unrelated people. But that leaves an
 * entire class invisible here: two records for one person that are IDENTICAL in
 * your own records — the same code, the same badge, the same email — and were
 * entered by hand rather than read off a terminal.
 *
 * WHAT IS USED, and how strong each one is:
 *
 *   strong    employee_code, person_id, card_number   identifiers, not resemblances
 *   possible  email, mobile_phone, home_phone         shared by families and offices
 *   possible  name                                    NEVER enough on its own to merge
 *
 * Name matches are handled last and are never linked together: five people called
 * Mohammed Ahmed would otherwise chain into one suggestion of twenty records. A
 * name group is only formed from members that no identifier has already claimed,
 * so the result stays something an operator can actually look at.
 *
 * A card held by more than one PIN is discarded as evidence, exactly as the device
 * finder discards it — such a card identifies nobody (four exist on this estate).
 */
class RecordDuplicateFinder
{
    /** Every identifier we are willing to group on, and how much it proves. */
    private const IDENTIFIERS = [
        'employee_code' => ['strength' => 'strong', 'label' => 'Same employee code'],
        'person_id' => ['strength' => 'strong', 'label' => 'Same person ID'],
        'card_number' => ['strength' => 'strong', 'label' => 'Same card'],
        'email' => ['strength' => 'possible', 'label' => 'Same email'],
        'mobile_phone' => ['strength' => 'possible', 'label' => 'Same mobile number'],
        'home_phone' => ['strength' => 'possible', 'label' => 'Same home number'],
    ];

    /**
     * @param  Collection<int, Employee>  $employees  already loaded, tenant-scoped
     * @return array{
     *   identifier: list<array{signal:string,label:string,strength:string,value:string,ids:list<int>}>,
     *   name: list<array{value:string,ids:list<int>}>,
     * }
     */
    public function find(Collection $employees): array
    {
        $identifier = [];

        foreach (self::IDENTIFIERS as $column => $meta) {
            foreach ($this->groupBy($employees, $column) as $value => $ids) {
                if ($column === 'card_number' && $this->cardIsShared($value, $employees)) {
                    continue;
                }

                $identifier[] = [
                    'signal' => $column,
                    'label' => $meta['label'],
                    'strength' => $meta['strength'],
                    'value' => (string) $value,
                    'ids' => $ids,
                ];
            }
        }

        return [
            'identifier' => $identifier,
            'name' => collect($this->groupBy($employees, 'name', forName: true))
                ->map(fn (array $ids, string $value) => ['value' => $value, 'ids' => $ids])
                ->values()
                ->all(),
        ];
    }

    /**
     * Employees sharing an identical, normalised value of one column.
     *
     * Normalising matters: terminals write phones with and without a country
     * code, and ` Ali ` and `ALI` are the same person to a human reading the
     * screen. Anything too short to mean anything is dropped — a 3-digit
     * "phone number" would group half the roster.
     *
     * @return array<string, list<int>>
     */
    private function groupBy(Collection $employees, string $column, bool $forName = false): array
    {
        $groups = [];

        foreach ($employees as $e) {
            $value = $this->normalise((string) ($e->{$column} ?? ''), $column, $forName);

            if ($value === '') {
                continue;
            }

            $groups[$value][] = $e->id;
        }

        return array_filter($groups, fn (array $ids) => count($ids) > 1);
    }

    private function normalise(string $raw, string $column, bool $forName): string
    {
        $raw = trim($raw);

        if ($forName) {
            // Case, spacing and punctuation all vary between terminals for one
            // person — the same normalisation the device finder uses.
            return preg_replace('/[^a-z0-9]/', '', strtolower($raw)) ?? '';
        }

        if ($column === 'email') {
            return strtolower($raw);
        }

        if (in_array($column, ['mobile_phone', 'home_phone', 'card_number'], true)) {
            // Digits only: +973 3344 5566 and 0097333445566 are one number, and a
            // card is a bare integer on the device.
            $digits = preg_replace('/\D+/', '', $raw) ?? '';
            $min = $column === 'card_number' ? 4 : 7;

            return strlen($digits) >= $min ? $digits : '';
        }

        return $raw;
    }

    /**
     * True when this card appears under more than one PIN, and so identifies
     * nobody. Mirrors DuplicateEmployeeFinder::cardsHeldByMoreThanOnePin().
     */
    private function cardIsShared(string $card, Collection $employees): bool
    {
        $holders = $employees
            ->filter(fn (Employee $e) => $this->normalise((string) ($e->card_number ?? ''), 'card_number', false) === $card)
            ->map(fn (Employee $e) => $this->employeePins($e))
            ->flatten()
            ->unique()
            ->count();

        return $holders > 1;
    }

    /** Every PIN this person holds on any terminal. */
    private function employeePins(Employee $e): array
    {
        return $e->deviceMaps
            ->filter(fn ($m) => $m->valid_to === null)
            ->pluck('device_user_id')
            ->filter(fn ($pin) => $pin !== null && $pin !== '')
            ->values()
            ->all();
    }
}
