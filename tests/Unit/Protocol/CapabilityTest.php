<?php

namespace Tests\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Capability\CapabilitySet;

/**
 * The capability model.
 *
 * The rule this enforces is a claim-honesty rule, not a functional one: the system
 * must never tell an operator that a device can do something no device has ever
 * been observed doing. These tests pin the four levels apart so a future
 * simplification to a boolean has to break something first.
 */
class CapabilityTest extends TestCase
{
    public function test_there_are_exactly_four_levels_and_they_are_distinct(): void
    {
        $levels = [
            Capability::CONFIRMED,
            Capability::IMPLEMENTED,
            Capability::NOT_IMPLEMENTED,
            Capability::UNKNOWN,
        ];

        $this->assertSame($levels, array_unique($levels));
    }

    /**
     * `implemented` is usable — the action is offered, labelled unproven.
     * `unknown` is NOT usable, and is not the same claim as `not_implemented`:
     * one says "our code does not do this", the other says "we have never asked".
     */
    public function test_usable_means_confirmed_or_implemented_only(): void
    {
        $this->assertTrue(Capability::isUsable(Capability::CONFIRMED));
        $this->assertTrue(Capability::isUsable(Capability::IMPLEMENTED));
        $this->assertFalse(Capability::isUsable(Capability::NOT_IMPLEMENTED));
        $this->assertFalse(Capability::isUsable(Capability::UNKNOWN));
    }

    public function test_every_level_has_its_own_operator_facing_description(): void
    {
        $descriptions = array_map(
            fn (string $l) => Capability::describe($l),
            [Capability::CONFIRMED, Capability::IMPLEMENTED, Capability::NOT_IMPLEMENTED, Capability::UNKNOWN],
        );

        foreach ($descriptions as $d) {
            $this->assertNotSame('', trim($d));
        }

        $this->assertSame($descriptions, array_unique($descriptions), 'each level needs its own wording');
    }

    public function test_an_unlisted_capability_defaults_to_unknown_not_to_supported(): void
    {
        $set = new CapabilitySet([]);

        // Failing open would offer every action on every device.
        $this->assertSame(Capability::UNKNOWN, $set->level('some.capability.we.never.declared'));
        $this->assertFalse($set->supports('some.capability.we.never.declared'));
    }

    public function test_the_defaults_declare_a_level_for_every_known_capability(): void
    {
        $set = CapabilitySet::defaults();

        foreach (Capability::all() as $key) {
            $this->assertContains(
                $set->level($key),
                [Capability::CONFIRMED, Capability::IMPLEMENTED, Capability::NOT_IMPLEMENTED, Capability::UNKNOWN],
                "{$key} has no valid level",
            );
        }
    }

    /**
     * Guards against quiet promotion.
     *
     * These are `implemented` because no hardware has confirmed them. Promoting one
     * to `confirmed` without a device having proved it is the single change that
     * turns this whole system back into a set of claims — so it has to break a test
     * and be argued for in a commit message.
     */
    public function test_unproven_capabilities_are_not_marked_confirmed(): void
    {
        $set = CapabilitySet::defaults();

        $unproven = [
            Capability::SET_TIME,
            Capability::TEMPLATE_DELETE,
            Capability::ATTENDANCE_REALTIME,
            Capability::ATTENDANCE_CLEAR,
            Capability::RESTART,
            Capability::USER_DISABLE,
        ];

        foreach ($unproven as $key) {
            $this->assertFalse(
                $set->isConfirmed($key),
                "{$key} is marked confirmed. Promote it only when a real device has proved it, "
                .'and say which device and firmware in the commit message.',
            );
            $this->assertTrue($set->supports($key), "{$key} should still be offered, labelled unproven");
        }
    }

    /**
     * Library 2.19.0: the F18 reads a tapped card but never hands its number over, so
     * card scanning is NOT offered on it (casebook DC-033, IT ROOM 2026-10-26).
     */
    public function test_card_scanning_is_not_offered_on_the_f18(): void
    {
        $set = CapabilitySet::defaults();

        $this->assertSame(Capability::NOT_IMPLEMENTED, $set->level(Capability::CARD_ENROLL));
        $this->assertFalse($set->supports(Capability::CARD_ENROLL));
        $this->assertTrue($set->isConfirmed(Capability::CARD_WRITE), 'typing the number is the way');
    }

    public function test_capabilities_never_probed_stay_unknown(): void
    {
        $set = CapabilitySet::defaults();

        foreach ([Capability::VERIFY_MODE, Capability::ACCESS_SCHEDULES, Capability::ANTI_PASSBACK,
            Capability::DURESS, Capability::RELAY] as $key) {
            $this->assertSame(
                Capability::UNKNOWN,
                $set->level($key),
                "{$key} was never probed — claiming anything about it invents a fact",
            );
        }
    }

    public function test_the_core_read_path_is_confirmed(): void
    {
        $set = CapabilitySet::defaults();

        foreach ([Capability::PING, Capability::DEVICE_INFO, Capability::USER_READ,
            Capability::ATTENDANCE_READ, Capability::TEMPLATE_TABLE_READ] as $key) {
            $this->assertTrue($set->isConfirmed($key), "{$key} is exercised on real hardware");
        }
    }

    public function test_for_ui_exposes_every_key_with_the_four_fields_a_client_needs(): void
    {
        $ui = CapabilitySet::defaults()->forUi();

        $this->assertSame(count(Capability::all()), count($ui));

        foreach ($ui as $key => $entry) {
            $this->assertArrayHasKey('level', $entry, $key);
            $this->assertArrayHasKey('usable', $entry, $key);
            $this->assertArrayHasKey('confirmed', $entry, $key);
            $this->assertArrayHasKey('description', $entry, $key);

            $this->assertSame(Capability::isUsable($entry['level']), $entry['usable']);
            $this->assertSame($entry['level'] === Capability::CONFIRMED, $entry['confirmed']);
        }
    }

    public function test_a_set_round_trips_through_from_array(): void
    {
        $original = CapabilitySet::defaults();
        $levels = array_map(fn (array $e) => $e['level'], $original->forUi());

        $restored = CapabilitySet::fromArray($levels);

        foreach (Capability::all() as $key) {
            $this->assertSame($original->level($key), $restored->level($key), $key);
        }
    }
}
