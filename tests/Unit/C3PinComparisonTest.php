<?php

namespace Tests\Unit;

use App\Services\Devices\C3\C3Driver;
use PHPUnit\Framework\TestCase;

/**
 * A C3 Pin is an integer, so an employee code with a leading zero comes back from the
 * panel without it. On 2026-10-11 that made a successful move of Tariq to 041112750
 * read back as 41112750 and be reported as a failure on Entrance and Glass Door.
 */
class C3PinComparisonTest extends TestCase
{
    public function test_a_leading_zero_is_the_same_pin(): void
    {
        $this->assertTrue(C3Driver::samePin('41112750', '041112750'));
        $this->assertTrue(C3Driver::samePin('940', '940'));
    }

    public function test_different_numbers_are_different_pins(): void
    {
        $this->assertFalse(C3Driver::samePin('940', '9400'));
        $this->assertFalse(C3Driver::samePin('41112750', '41112751'));
    }

    public function test_a_non_numeric_id_never_matches(): void
    {
        $this->assertFalse(C3Driver::samePin('abc', 'abc'));
        $this->assertFalse(C3Driver::samePin('', '0'));
    }
}
