<?php

namespace Tests\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use Zkteco\Protocol\Codec\AttendanceCodec;
use Zkteco\Protocol\Codec\RecordBuffer;
use Zkteco\Protocol\Codec\Timestamp;

/**
 * The attendance decoder, driven by synthesised buffers rather than a device.
 *
 * The behaviour that matters most here is not decoding a good record — it is what
 * happens to a bad one. A record this codec cannot read must be COUNTED and KEPT,
 * never silently skipped, because silently skipping is exactly how 34% of the
 * attendance history disappeared for months without anybody noticing.
 */
class AttendanceCodecTest extends TestCase
{
    private const TZ = '+03:00';

    /** Builds one 40-byte record in the confirmed hardware layout. */
    private function record40(int $uid, string $userId, string $moment, int $verify = 1, int $status = 255): string
    {
        $chunk = str_repeat("\0", 40);
        $chunk = substr_replace($chunk, pack('v', $uid), 0, 2);
        $chunk = substr_replace($chunk, str_pad(substr($userId, 0, 24), 24, "\0"), 2, 24);
        $chunk = substr_replace($chunk, chr($verify), 26, 1);
        $chunk = substr_replace($chunk, pack('V', Timestamp::encode(
            new \DateTimeImmutable($moment, new \DateTimeZone(self::TZ)),
        )), 27, 4);
        $chunk = substr_replace($chunk, chr($status), 31, 1);

        return $chunk;
    }

    /** Prefixes the 4-byte length field the bulk envelope carries. */
    private function envelope(string $body): string
    {
        return pack('V', strlen($body)) . $body;
    }

    public function test_it_decodes_a_forty_byte_record(): void
    {
        $raw = $this->envelope($this->record40(7, '261', '2026-07-26 14:30:45'));

        $records = AttendanceCodec::decode($raw, self::TZ);

        $this->assertCount(1, $records);
        $this->assertSame(7, $records[0]['uid']);
        $this->assertSame('261', $records[0]['user_id']);
        $this->assertSame('2026-07-26 14:30:45', $records[0]['timestamp']->format('Y-m-d H:i:s'));
    }

    public function test_it_decodes_many_records_in_order(): void
    {
        $raw = $this->envelope(
            $this->record40(1, '100', '2026-07-26 08:00:00')
            . $this->record40(2, '101', '2026-07-26 08:05:03')   // single-digit second
            . $this->record40(3, '102', '2026-07-26 17:09:00'),  // single-digit minute
        );

        $records = AttendanceCodec::decode($raw, self::TZ);

        $this->assertCount(3, $records);
        $this->assertSame(['100', '101', '102'], array_column($records, 'user_id'));
        // The middle two are the shape the old decoder threw away.
        $this->assertSame('08:05:03', $records[1]['timestamp']->format('H:i:s'));
        $this->assertSame('17:09:00', $records[2]['timestamp']->format('H:i:s'));
    }

    /**
     * Byte 26 is the verify method. Values 3 and 4 are emitted by this hardware and
     * are UNDECODED — we do not know what they mean.
     *
     * They must survive verbatim rather than being mapped to a plausible guess: a
     * wrong label on an authentication method is worse than an unlabelled number.
     */
    public function test_unknown_verify_methods_survive_verbatim(): void
    {
        foreach ([0, 1, 2, 3, 4, 15] as $verify) {
            $raw = $this->envelope($this->record40(1, '261', '2026-07-26 09:00:00', verify: $verify));
            $records = AttendanceCodec::decode($raw, self::TZ);

            $this->assertSame($verify, $records[0]['verify_method']);
        }
    }

    /**
     * Byte 31 is a constant placeholder (255 on every record from this unit), not
     * a punch direction. This pins that it is exposed as-is so nobody starts
     * inferring in/out from it.
     */
    public function test_the_status_byte_is_exposed_as_read_not_interpreted(): void
    {
        $raw = $this->envelope($this->record40(1, '261', '2026-07-26 09:00:00', status: 255));

        $this->assertSame(255, AttendanceCodec::decode($raw, self::TZ)[0]['status']);
    }

    public function test_a_null_terminated_user_id_is_trimmed_at_the_terminator(): void
    {
        $raw = $this->envelope($this->record40(1, '261', '2026-07-26 09:00:00'));

        $this->assertSame('261', AttendanceCodec::decode($raw, self::TZ)[0]['user_id']);
    }

    // --- Failure behaviour -------------------------------------------------

    /**
     * THE important one. An undecodable chunk is returned as raw hex, not dropped.
     *
     * The caller can then count it (`undecodable` on the sync run) and archive it
     * (`attendance_raw_logs`), which is what makes a decoder bug recoverable
     * instead of permanent data loss.
     */
    public function test_an_undecodable_record_is_kept_as_raw_hex_rather_than_dropped(): void
    {
        $bad = str_repeat("\0", 40);   // zero timestamp → undecodable
        $raw = $this->envelope($this->record40(1, '261', '2026-07-26 09:00:00') . $bad);

        $records = AttendanceCodec::decode($raw, self::TZ);

        $this->assertCount(2, $records, 'the bad record must still be accounted for');
        $this->assertArrayHasKey('raw', $records[1]);
        $this->assertArrayNotHasKey('timestamp', $records[1]);
        $this->assertSame(bin2hex($bad), $records[1]['raw']);
    }

    public function test_a_good_record_after_a_bad_one_still_decodes(): void
    {
        $raw = $this->envelope(
            str_repeat("\0", 40)
            . $this->record40(9, '999', '2026-07-26 11:11:11'),
        );

        $records = AttendanceCodec::decode($raw, self::TZ);

        $this->assertCount(2, $records);
        $this->assertSame('999', $records[1]['user_id']);
    }

    public function test_an_empty_buffer_decodes_to_nothing(): void
    {
        $this->assertSame([], AttendanceCodec::decode('', self::TZ));
        $this->assertSame([], AttendanceCodec::decode($this->envelope(''), self::TZ));
    }

    /**
     * A buffer that divides by no known width is a truncated or misaligned
     * transfer. Guessing a width would produce confident garbage — a whole table
     * of plausible, wrong punches — so it decodes to nothing at all.
     */
    public function test_a_buffer_matching_no_known_width_decodes_to_nothing(): void
    {
        $misaligned = $this->envelope(str_repeat('x', 41 * 3 + 1));

        $this->assertSame([], AttendanceCodec::decode($misaligned, self::TZ));
    }

    public function test_record_width_detection_prefers_the_confirmed_forty_byte_layout(): void
    {
        // 80 divides by 40, 16 and 8. The confirmed width must win.
        $this->assertSame(40, RecordBuffer::detectRecordSize(80, [40, 16, 8]));
        // 32 divides by 16 and 8 but not 40.
        $this->assertSame(16, RecordBuffer::detectRecordSize(32, [40, 16, 8]));
        // Divides by none.
        $this->assertSame(0, RecordBuffer::detectRecordSize(37, [40, 16, 8]));
    }

    public function test_the_declared_length_can_be_cross_checked_against_the_body(): void
    {
        $body = $this->record40(1, '261', '2026-07-26 09:00:00');
        $raw = $this->envelope($body);

        $this->assertSame(strlen($body), RecordBuffer::declaredLength($raw));
        $this->assertSame($body, RecordBuffer::body($raw));
    }
}
