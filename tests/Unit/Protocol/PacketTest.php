<?php

namespace Tests\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use Promoseven\Biometric\Protocol\Command;
use Promoseven\Biometric\Protocol\Packet;

/**
 * The packet encoder, byte for byte, with no device.
 *
 * Every offset asserted here was either confirmed against real hardware or comes
 * from the documented layout. They are asserted as literal numbers rather than
 * derived from the code, so a change to the encoder has to be a deliberate
 * decision rather than something that quietly still passes.
 */
class PacketTest extends TestCase
{
    public function test_a_built_packet_has_an_eight_byte_header_then_the_payload(): void
    {
        [$packet] = Packet::build(Command::CONNECT, sessionId: 0, replyId: 0);

        $this->assertSame(8, strlen($packet));

        [$packet] = Packet::build(Command::USER_WRQ, sessionId: 5, replyId: 1, data: 'abcd');

        $this->assertSame(12, strlen($packet));
        $this->assertSame('abcd', substr($packet, 8));
    }

    public function test_build_and_parse_round_trip(): void
    {
        [$packet] = Packet::build(Command::ATTLOG_RRQ, sessionId: 4242, replyId: 7, data: 'payload');

        $parsed = Packet::parse($packet);

        $this->assertSame(Command::ATTLOG_RRQ, $parsed['command']);
        $this->assertSame(4242, $parsed['sessionId']);
        $this->assertSame('payload', $parsed['data']);
    }

    /**
     * The reply counter increments on the SENT packet, and the caller is handed
     * the next value. A device that receives an unexpected counter ignores the
     * packet entirely, which presents as a timeout — so an off-by-one here is
     * indistinguishable from a dead device.
     */
    public function test_build_returns_the_incremented_reply_id(): void
    {
        [$packet, $next] = Packet::build(Command::CONNECT, sessionId: 1, replyId: 41);

        $this->assertSame(42, $next);
        $this->assertSame(42, Packet::parse($packet)['replyId']);
    }

    public function test_the_reply_counter_wraps_at_the_unsigned_short_boundary(): void
    {
        [, $next] = Packet::build(Command::CONNECT, sessionId: 1, replyId: Command::USHRT_MAX - 1);

        $this->assertSame(0, $next);
    }

    /**
     * The checksum is computed over the reply id BEFORE it is incremented, while
     * the packet ships the incremented value.
     *
     * That looks like an off-by-one, and it is worth knowing that it is not one:
     * the long-standing reference implementations do exactly this, and this
     * firmware accepts the result — 9,582 attendance records were read with these
     * bytes. The device evidently does not validate the checksum against the
     * shipped header.
     *
     * This test pins the behaviour so nobody "fixes" it into something the device
     * has never seen.
     */
    public function test_the_checksum_covers_the_pre_increment_reply_id(): void
    {
        [$packet] = Packet::build(Command::CONNECT, sessionId: 9, replyId: 3, data: 'xyz');
        $parsed = Packet::parse($packet);

        $overSent = pack('v4', $parsed['command'], 0, $parsed['sessionId'], $parsed['replyId']).$parsed['data'];
        $overOriginal = pack('v4', Command::CONNECT, 0, 9, 3).'xyz';

        $this->assertSame(Packet::checksum($overOriginal), $parsed['checksum']);
        $this->assertNotSame(Packet::checksum($overSent), $parsed['checksum']);
    }

    public function test_the_checksum_changes_when_any_byte_changes(): void
    {
        $this->assertNotSame(Packet::checksum('hello'), Packet::checksum('hellp'));
    }

    // --- User record -------------------------------------------------------

    public function test_a_user_record_is_seventy_two_bytes(): void
    {
        $this->assertSame(72, strlen(Packet::buildUserRecord(1, '261', 'Ghassan Yusuf')));
    }

    public function test_a_user_record_places_every_field_at_its_documented_offset(): void
    {
        $record = Packet::buildUserRecord(
            uid: 7,
            userId: '261',
            name: 'Ghassan Yusuf',
            privilege: 14,
            cardNumber: 123456,
            group: 1,
        );

        $this->assertSame(7, unpack('v', substr($record, 0, 2))[1], 'uid at offset 0');
        $this->assertSame(14, ord($record[2]), 'privilege at offset 2');
        $this->assertSame('Ghassan Yusuf', rtrim(substr($record, 11, 24), "\0"), 'name at offset 11');
        $this->assertSame(123456, unpack('V', substr($record, 35, 4))[1], 'card at offset 35');
        $this->assertSame(1, ord($record[39]), 'group at offset 39');
        $this->assertSame('261', rtrim(substr($record, 48, 9), "\0"), 'user id at offset 48');
    }

    /**
     * CONFIRMED HAZARD: a group of 0 is accepted by the device and then ignored —
     * the record appears to write and the user cannot authenticate. The default
     * must therefore never be 0.
     */
    public function test_the_group_defaults_to_one_because_zero_silently_breaks_authentication(): void
    {
        $record = Packet::buildUserRecord(1, '261', 'Someone');

        $this->assertGreaterThanOrEqual(1, ord($record[39]));
    }

    public function test_an_over_long_name_is_truncated_rather_than_overflowing_the_next_field(): void
    {
        $record = Packet::buildUserRecord(1, '261', str_repeat('A', 40), cardNumber: 999);

        $this->assertSame(72, strlen($record));
        $this->assertSame(str_repeat('A', 24), substr($record, 11, 24));
        // The card must survive: a name that ran past its field would eat it.
        $this->assertSame(999, unpack('V', substr($record, 35, 4))[1]);
    }

    public function test_an_over_long_user_id_is_truncated_to_the_devices_nine_byte_field(): void
    {
        $record = Packet::buildUserRecord(1, '1234567890123', 'Someone');

        $this->assertSame('123456789', substr($record, 48, 9));
        $this->assertSame(72, strlen($record));
    }

    // --- Enrollment request ------------------------------------------------

    public function test_an_enroll_request_is_twenty_six_bytes_with_the_slot_at_offset_24(): void
    {
        $request = Packet::buildEnrollRequest('261', 6);

        $this->assertSame(26, strlen($request));
        $this->assertSame('261', rtrim(substr($request, 0, 9), "\0"), 'user id at offset 0');
        $this->assertSame(6, ord($request[24]), 'finger slot at offset 24');
        $this->assertSame(1, ord($request[25]), 'valid-fingerprint flag at offset 25');
    }

    public function test_an_enroll_request_truncates_the_user_id_to_nine_bytes(): void
    {
        $request = Packet::buildEnrollRequest('1234567890123', 0);

        $this->assertSame(26, strlen($request));
        $this->assertSame('123456789', substr($request, 0, 9));
        // Truncation must not have shifted the slot out of position.
        $this->assertSame(0, ord($request[24]));
    }

    public function test_every_finger_slot_zero_through_nine_encodes(): void
    {
        foreach (range(0, 9) as $slot) {
            $this->assertSame($slot, ord(Packet::buildEnrollRequest('261', $slot)[24]));
        }
    }

    // --- Misc --------------------------------------------------------------

    /**
     * The comm key is four bytes and deterministic.
     *
     * Note what it is NOT: session-unique. The published algorithm discards the
     * third scrambled byte and replaces it with the tick value, so two sessions
     * whose ids differ only in the bits that land there produce an identical key
     * (4242 and 4243 do). That is a property of the algorithm every ZK client
     * implements, not a defect introduced here — but it means the comm key is
     * obfuscation, never authentication, and nothing should be built on the
     * assumption that it is per-session.
     */
    public function test_the_comm_key_is_deterministic_and_key_sensitive(): void
    {
        $this->assertSame(Packet::makeCommKey(123, 4242), Packet::makeCommKey(123, 4242));
        $this->assertSame(4, strlen(Packet::makeCommKey(123, 4242)));

        $this->assertNotSame(
            Packet::makeCommKey(123, 4242),
            Packet::makeCommKey(124, 4242),
            'a different comm key must produce different bytes',
        );

        // Documented above: session ids CAN collide. This pins that reality.
        $this->assertSame(
            Packet::makeCommKey(123, 4242),
            Packet::makeCommKey(123, 4243),
            'the discarded byte makes these two sessions collide — see the docblock',
        );
    }

    /**
     * CONFIRMED: realtime pushes are acknowledged with reply number 0, not the
     * usual incrementing counter.
     */
    public function test_a_realtime_ack_carries_reply_number_zero(): void
    {
        $parsed = Packet::parse(Packet::buildRealtimeAck(4242));

        $this->assertSame(Command::ACK_OK, $parsed['command']);
        $this->assertSame(4242, $parsed['sessionId']);
        $this->assertSame(0, $parsed['replyId']);
    }
}
