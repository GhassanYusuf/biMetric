<?php

namespace App\Services\Employees;

use App\Models\Employee;

/**
 * Suggests the lowest unused employee code — the first gap in the numeric codes already
 * registered, or one past the highest when there is no gap.
 *
 * Gap-filling rather than always incrementing: codes get freed when people leave, and
 * the terminal keys attendance by this code, so reusing a free low number keeps them
 * short and typeable at the keypad.
 *
 * Only numeric codes are considered. Non-numeric ones (e.g. "TEMP-4") are ignored
 * rather than guessed at, so they never collide with a suggestion.
 *
 * Extracted from EmployeeController::nextCode() (2026-10-08) so the integration API,
 * which may create an employee without naming a code, allocates exactly as the form does.
 * A suggestion, not a reservation: the unique rule on the request is what decides.
 */
class EmployeeCodeAllocator
{
    /** @return array{code:int, taken:int} */
    public function next(): array
    {
        $taken = Employee::query()
            ->pluck('employee_code')
            ->filter(fn ($code) => is_string($code) && ctype_digit($code))
            ->map(fn ($code) => (int) $code)
            ->filter(fn (int $code) => $code > 0)
            ->unique()
            ->sort()
            ->values();

        $suggestion = 1;
        foreach ($taken as $code) {
            if ($code > $suggestion) {
                break; // found the gap
            }
            $suggestion = $code + 1;
        }

        return ['code' => $suggestion, 'taken' => $taken->count()];
    }
}
