<?php

namespace Zkteco\Protocol\Protocol;

/**
 * Packet encoding and decoding — the only place in the library that knows the
 * byte layout of a request or reply.
 *
 * Wire format (little-endian throughout):
 *
 *   offset 0  uint16  command
 *   offset 2  uint16  checksum
 *   offset 4  uint16  session id   (0 on the initial CONNECT; assigned by device)
 *   offset 6  uint16  reply id     (incremented per request, wraps at USHRT_MAX)
 *   offset 8  ...     payload
 *
 * Over TCP the whole thing is additionally wrapped in an 8-byte transport header
 * (magic 0x5050, reserved, uint32 length). CONFIRMED against real hardware: the
 * F18-class device speaks this protocol directly over UDP datagrams — one packet
 * per datagram, no wrapper — so wrapTcp/unwrapTcp exist for TCP transports but
 * are unused by UdpTransport.
 */
final class Packet
{
    /** 8-byte "top" frame that wraps every packet sent over TCP. */
    public const TCP_MAGIC = 0x5050;

    private function __construct()
    {
    }

    /**
     * Builds a request packet, including the protocol's checksum.
     *
     * The checksum is computed over the body with the checksum field zeroed, then
     * spliced in — which is why the body is packed twice here.
     *
     * @return array{0:string,1:int} [rawPacket, nextReplyId]
     */
    public static function build(int $command, int $sessionId, int $replyId, string $data = ''): array
    {
        $body = pack('v4', $command, 0, $sessionId, $replyId) . $data;
        $checksum = self::checksum($body);

        $replyId++;
        if ($replyId >= Command::USHRT_MAX) {
            $replyId -= Command::USHRT_MAX;
        }

        $packet = pack('v4', $command, $checksum, $sessionId, $replyId) . $data;

        return [$packet, $replyId];
    }

    /**
     * Parses the 8-byte command header at the start of a reply.
     *
     * @return array{command:int,checksum:int,sessionId:int,replyId:int,data:string}
     */
    public static function parse(string $payload): array
    {
        $header = unpack('vcommand/vchecksum/vsessionId/vreplyId', substr($payload, 0, 8));

        return [
            'command' => $header['command'],
            'checksum' => $header['checksum'],
            'sessionId' => $header['sessionId'],
            'replyId' => $header['replyId'],
            'data' => substr($payload, 8),
        ];
    }

    /** Wraps a command packet in the 8-byte TCP transport header. */
    public static function wrapTcp(string $packet): string
    {
        return pack('vvV', self::TCP_MAGIC, 0, strlen($packet)) . $packet;
    }

    /**
     * Strips the TCP transport header from bytes read off a stream socket.
     *
     * @return array{0:int,1:string} [declaredPayloadLength, remainingBytes]
     */
    public static function unwrapTcp(string $chunk): array
    {
        if (strlen($chunk) < 8) {
            return [0, $chunk];
        }

        $header = unpack('vmagic/vreserved/Vlength', substr($chunk, 0, 8));

        return [$header['length'], substr($chunk, 8)];
    }

    /**
     * The protocol's checksum: a 16-bit ones'-complement-style sum, mirroring the
     * algorithm every known ZK client library uses.
     */
    public static function checksum(string $payload): int
    {
        $length = strlen($payload);
        $checksum = 0;
        $i = 0;

        while ($length > 1) {
            $word = ord($payload[$i]) | (ord($payload[$i + 1]) << 8);
            $checksum += $word;
            if ($checksum > Command::USHRT_MAX) {
                $checksum -= Command::USHRT_MAX;
            }
            $i += 2;
            $length -= 2;
        }

        if ($length === 1) {
            $checksum += ord($payload[$i]);
        }

        while ($checksum > Command::USHRT_MAX) {
            $checksum -= Command::USHRT_MAX;
        }

        $checksum = ~$checksum;
        while ($checksum < 0) {
            $checksum += Command::USHRT_MAX;
        }

        return $checksum & Command::USHRT_MAX;
    }

    /**
     * The standard ZK "comm key" scrambling algorithm, as documented by
     * long-standing open-source ZK implementations.
     *
     * CONFIRMED working against real hardware with commKey = 0 (comm key
     * disabled). Non-zero keys follow the same published algorithm but have not
     * been exercised in this project — see docs/protocol-overview.md.
     */
    public static function makeCommKey(int $key, int $sessionId, int $ticks = 50): string
    {
        $k = 0;
        for ($i = 0; $i < 32; $i++) {
            $k = (($key & (1 << $i)) ? (($k << 1) | 1) : ($k << 1)) & 0xFFFFFFFF;
        }
        $k = ($k + $sessionId) & 0xFFFFFFFF;

        $b = array_values(unpack('C4', pack('V', $k)));
        $xored = [$b[0] ^ 0x5A, $b[1] ^ 0x4B, $b[2] ^ 0x53, $b[3] ^ 0x4F]; // 'Z','K','S','O'
        $h = array_values(unpack('v2', pack('C4', ...$xored)));
        $swapped = array_values(unpack('C4', pack('v2', $h[1], $h[0])));

        $B = $ticks & 0xFF;

        return pack('C4', $swapped[0] ^ $B, $swapped[1] ^ $B, $B, $swapped[3] ^ $B);
    }

    /**
     * Builds a 72-byte user record for USER_WRQ.
     *
     * Field layout per the documented ZK protocol (adrobinoga/zk-protocol, "Set
     * User Info"): uid(2)@0, permission(1)@2, password(8)@3, name(24)@11,
     * card(4)@35, group(1)@39, user-tz-flag(2)@40, tz1/tz2/tz3(2 each)@42/44/46,
     * user id(9)@48, zeros(15)@57.
     *
     * CONFIRMED: group must be >= 1 — the device rejects writes with group 0.
     */
    public static function buildUserRecord(
        int $uid,
        string $userId,
        string $name,
        int $privilege = 0,
        int $cardNumber = 0,
        int $group = 1,
    ): string {
        $record = str_repeat("\0", 72);
        $record = substr_replace($record, pack('v', $uid), 0, 2);
        $record = substr_replace($record, chr($privilege & 0xFF), 2, 1);
        $record = substr_replace($record, str_pad(substr($name, 0, 24), 24, "\0"), 11, 24);
        $record = substr_replace($record, pack('V', $cardNumber), 35, 4);
        $record = substr_replace($record, chr($group & 0xFF), 39, 1);
        $record = substr_replace($record, str_pad(substr($userId, 0, 9), 9, "\0"), 48, 9);

        return $record;
    }

    /**
     * Payload for START_ENROLL.
     *
     * 26-byte structure per the documented protocol: user id (9 bytes) + zero
     * padding to offset 24 + finger slot (1 byte) + fingerprint flag (1 byte,
     * 1 = valid fingerprint). CONFIRMED against real hardware.
     *
     * The user id is truncated to 9 bytes deliberately — that is the device's own
     * field width, the same one buildUserRecord() writes at offset 48.
     */
    public static function buildEnrollRequest(string $userId, int $fingerIndex): string
    {
        $record = str_repeat("\0", 26);
        $record = substr_replace($record, str_pad(substr($userId, 0, 9), 9, "\0"), 0, 9);
        $record = substr_replace($record, chr($fingerIndex & 0xFF), 24, 1);
        $record = substr_replace($record, chr(1), 25, 1);

        return $record;
    }

    /**
     * Acknowledgement for an unsolicited realtime push.
     *
     * CONFIRMED: these are acknowledged with a bare ACK_OK carrying reply number
     * 0, not the usual incrementing counter.
     */
    public static function buildRealtimeAck(int $sessionId): string
    {
        $body = pack('v4', Command::ACK_OK, 0, $sessionId, 0);

        return pack('v4', Command::ACK_OK, self::checksum($body), $sessionId, 0);
    }
}
