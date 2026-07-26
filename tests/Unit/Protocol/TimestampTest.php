<?php

namespace Tests\Unit\Protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zkteco\Protocol\Codec\Timestamp;

/**
 * The timestamp codec.
 *
 * This is the most consequential class in the library. A bug here does not throw
 * and does not log — it silently discards attendance records, and the first
 * symptom is a payroll number that is quietly too small.
 *
 * The original decoder built its date with the format 'Y-n-j-G-i-s'. `i` and `s`
 * require TWO digits, so every punch whose minute or second was below 10 returned
 * false and was thrown away: 3,236 of 9,582 records — 34% — for the entire life of
 * the code. The regression tests below are the reason that cannot come back.
 */
class TimestampTest extends TestCase
{
    private const TZ = '+03:00';

    /** Encodes the way the device does, so tests can start from a real moment. */
    private function encode(int $y, int $mo, int $d, int $h, int $mi, int $s): int
    {
        return ((($y - 2000) * 12 * 31 + ($mo - 1) * 31 + ($d - 1)) * 24 * 60 * 60)
            + ($h * 60 + $mi) * 60 + $s;
    }

    public function test_it_decodes_a_known_moment(): void
    {
        $decoded = Timestamp::decode($this->encode(2026, 7, 26, 14, 30, 45), self::TZ);

        $this->assertNotNull($decoded);
        $this->assertSame('2026-07-26 14:30:45', $decoded->format('Y-m-d H:i:s'));
    }

    /**
     * THE regression test.
     *
     * Every one of these was silently discarded by the original decoder. If this
     * test ever fails, attendance data is being lost again.
     *
     */
    #[DataProvider('singleDigitComponents')]
    public function test_single_digit_components_decode_rather_than_being_discarded(
        int $month, int $day, int $hour, int $minute, int $second,
    ): void {
        $decoded = Timestamp::decode($this->encode(2026, $month, $day, $hour, $minute, $second), self::TZ);

        $this->assertNotNull(
            $decoded,
            sprintf(
                'A punch at %04d-%02d-%02d %02d:%02d:%02d decoded to null. '
                . 'This is the 34%% data-loss bug returning.',
                2026, $month, $day, $hour, $minute, $second,
            ),
        );

        $this->assertSame(
            sprintf('%04d-%02d-%02d %02d:%02d:%02d', 2026, $month, $day, $hour, $minute, $second),
            $decoded->format('Y-m-d H:i:s'),
        );
    }

    public static function singleDigitComponents(): array
    {
        return [
            'single-digit minute' => [7, 26, 14, 5, 30],
            'single-digit second' => [7, 26, 14, 30, 5],
            'both single digit' => [7, 26, 14, 5, 5],
            'zero minute' => [7, 26, 14, 0, 30],
            'zero second' => [7, 26, 14, 30, 0],
            'zero minute and second' => [7, 26, 14, 0, 0],
            'single-digit hour' => [7, 26, 9, 5, 5],
            'midnight' => [7, 26, 0, 0, 0],
            'single-digit month and day' => [3, 4, 9, 8, 7],
            'new year midnight' => [1, 1, 0, 0, 0],
            'last second of the year' => [12, 31, 23, 59, 59],
        ];
    }

    /**
     * The loss was ~34% because roughly 1-in-6 punches has a minute below 10 and
     * 1-in-6 has a second below 10. This walks a full hour, second by second, and
     * proves nothing is dropped — the shape of the original bug, at scale.
     */
    public function test_every_second_of_an_hour_decodes(): void
    {
        $lost = 0;

        for ($minute = 0; $minute < 60; $minute++) {
            for ($second = 0; $second < 60; $second++) {
                if (Timestamp::decode($this->encode(2026, 7, 26, 13, $minute, $second), self::TZ) === null) {
                    $lost++;
                }
            }
        }

        $this->assertSame(0, $lost, "{$lost} of 3600 timestamps in one hour decoded to null.");
    }

    // --- Timezone ----------------------------------------------------------

    /**
     * The device stamps its OWN local time with no zone information. Anchoring to
     * the wrong zone shifts every punch — which is how 6,432 rows ended up
     * duplicated at exactly a 3-hour offset.
     */
    public function test_the_decoded_moment_is_anchored_to_the_devices_timezone(): void
    {
        $encoded = $this->encode(2026, 7, 26, 14, 30, 0);

        $device = Timestamp::decode($encoded, '+03:00');
        $utc = Timestamp::decode($encoded, '+00:00');

        // Same wall-clock reading...
        $this->assertSame('14:30:00', $device->format('H:i:s'));
        $this->assertSame('14:30:00', $utc->format('H:i:s'));

        // ...but three hours apart as absolute moments.
        $this->assertSame(3 * 3600, $utc->getTimestamp() - $device->getTimestamp());
    }

    public function test_converting_to_utc_moves_the_wall_clock_back_by_the_offset(): void
    {
        $decoded = Timestamp::decode($this->encode(2026, 7, 26, 17, 59, 0), '+03:00');

        // The exact case that was stored wrongly as the sync watermark: the device
        // said 17:59 local, which is 14:59 UTC. Storing 17:59 as UTC put the
        // watermark three hours into the future and skipped real punches.
        $this->assertSame(
            '14:59:00',
            $decoded->setTimezone(new \DateTimeZone('UTC'))->format('H:i:s'),
        );
    }

    public function test_a_named_timezone_is_accepted_for_deployments_with_dst(): void
    {
        $decoded = Timestamp::decode($this->encode(2026, 7, 26, 14, 30, 0), 'Europe/Berlin');

        $this->assertNotNull($decoded);
        $this->assertSame('14:30:00', $decoded->format('H:i:s'));
    }

    // --- Round trip and edges ----------------------------------------------

    public function test_encode_and_decode_round_trip(): void
    {
        $moments = [
            '2023-01-01 00:00:00',
            '2024-02-29 09:05:03',
            '2026-07-26 14:30:45',
            '2026-12-31 23:59:59',
        ];

        foreach ($moments as $moment) {
            $original = new \DateTimeImmutable($moment, new \DateTimeZone(self::TZ));
            $decoded = Timestamp::decode(Timestamp::encode($original), self::TZ);

            $this->assertNotNull($decoded, "{$moment} failed to round trip");
            $this->assertSame($moment, $decoded->format('Y-m-d H:i:s'));
        }
    }

    public function test_zero_decodes_to_null_rather_than_the_year_2000(): void
    {
        // A zeroed timestamp field means "no value", not 2000-01-01. Treating it
        // as a real moment would insert phantom punches decades in the past.
        $this->assertNull(Timestamp::decode(0, self::TZ));
    }

    public function test_records_across_a_day_boundary_stay_ordered(): void
    {
        $before = Timestamp::decode($this->encode(2026, 7, 26, 23, 59, 59), self::TZ);
        $after = Timestamp::decode($this->encode(2026, 7, 27, 0, 0, 0), self::TZ);

        $this->assertLessThan($after->getTimestamp(), $before->getTimestamp());
        $this->assertSame(1, $after->getTimestamp() - $before->getTimestamp());
    }
}
