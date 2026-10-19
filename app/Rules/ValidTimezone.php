<?php

namespace App\Rules;

use App\Support\Timezone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A device's own time zone must be one PHP can actually use.
 *
 * Deliberately permits NULL, which means "inherit the company time zone". That is the
 * common case and the whole point of the setting: a terminal in another country sets
 * its own, and every terminal stays on the default until someone says otherwise. An
 * empty string is treated as null too, because an HTML select that is cleared to "Same
 * as the company default" submits '' far more often than it submits null.
 */
class ValidTimezone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || trim((string) $value) === '') {
            return;
        }

        if (! Timezone::isValid($value)) {
            $fail(Timezone::invalidMessage(trim((string) $value)))->translate();
        }
    }
}
