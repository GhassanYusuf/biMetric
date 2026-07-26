<?php

namespace App\Services\Zkteco;

/**
 * Command / constant table for the ZKTeco "standalone" device protocol
 * (the same wire protocol implemented by the widely used open-source
 * pyzk / node-zklib / zkteco-js libraries). This is a plain TCP protocol
 * spoken by the device itself on port 4370 - nothing here depends on any
 * Windows DLL/COM component.
 */
final class ZktecoProtocol
{
    // Session / handshake
    public const CMD_CONNECT = 1000;
    public const CMD_EXIT = 1001;
    public const CMD_ENABLEDEVICE = 1002;
    public const CMD_DISABLEDEVICE = 1003;
    public const CMD_AUTH = 1102;

    // Acknowledgements
    public const CMD_ACK_OK = 2000;
    public const CMD_ACK_ERROR = 2001;
    public const CMD_ACK_DATA = 2002;
    public const CMD_ACK_UNAUTH = 2005;

    // Bulk data transfer envelope
    public const CMD_PREPARE_DATA = 1500;
    public const CMD_DATA = 1501;
    public const CMD_FREE_DATA = 1502;

    // Reads
    public const CMD_DB_RRQ = 7;
    public const CMD_USER_WRQ = 8;
    public const CMD_USERTEMP_RRQ = 9;
    public const CMD_ATTLOG_RRQ = 13;
    public const CMD_CLEAR_DATA = 14;
    public const CMD_CLEAR_ATTLOG = 15;
    public const CMD_DELETE_USER = 18;
    /** Deletes ONE fingerprint template: payload is (uint16 uid, uint8 finger index). */
    public const CMD_DELETE_USERTEMP = 19;
    public const CMD_WRITE_LCD = 66;
    public const CMD_CLEAR_LCD = 67;
    public const CMD_GET_TIME = 201;
    public const CMD_SET_TIME = 202;
    public const CMD_VERSION = 1100;
    public const CMD_DEVICE = 11;
    public const CMD_GET_FREE_SIZES = 50;
    public const CMD_REG_EVENT = 500;
    public const CMD_REFRESHDATA = 1013;
    public const CMD_TESTVOICE = 1017;

    // Fingerprint enrollment
    public const CMD_STARTVERIFY = 60;
    public const CMD_STARTENROLL = 61;
    public const CMD_CANCELCAPTURE = 62;

    // Realtime event bit flags (OR'd together, registered via CMD_REG_EVENT)
    public const EF_ATTLOG = 1;
    public const EF_FINGER = 1 << 1;
    public const EF_ENROLLUSER = 1 << 2;
    public const EF_ENROLLFINGER = 1 << 3;
    public const EF_BUTTON = 1 << 4;
    public const EF_UNLOCK = 1 << 5;
    public const EF_VERIFY = 1 << 7;
    public const EF_FPFTR = 1 << 8;
    public const EF_ALARM = 1 << 9;
    public const EF_ALL = 0xFFFF;

    // Data "function" selectors used alongside CMD_DB_RRQ to pick which
    // table the bulk read returns (matching pyzk's FCT_* constants).
    public const FCT_FINGERTMP = 2;
    public const FCT_USER = 5;

    public const USHRT_MAX = 0xFFFF;

    /** 8-byte "top" frame that wraps every packet sent/received over TCP. */
    public const TCP_MAGIC = 0x5050;

    private function __construct()
    {
    }

    /**
     * Builds the 8-byte command header (+ payload) that every request
     * needs, including the protocol's own checksum.
     *
     * @return array{0:string,1:int} [rawPacket, nextReplyId]
     */
    public static function buildPacket(int $command, int $sessionId, int $replyId, string $data = ''): array
    {
        $body = pack('v4', $command, 0, $sessionId, $replyId) . $data;
        $checksum = self::checksum($body);

        $replyId++;
        if ($replyId >= self::USHRT_MAX) {
            $replyId -= self::USHRT_MAX;
        }

        $packet = pack('v4', $command, $checksum, $sessionId, $replyId) . $data;

        return [$packet, $replyId];
    }

    /** Wraps a command packet in the 8-byte TCP transport header (magic + length). */
    public static function wrapTcp(string $packet): string
    {
        return pack('vvV', self::TCP_MAGIC, 0, strlen($packet)) . $packet;
    }

    /**
     * Strips the TCP transport header from a chunk of bytes read off the socket.
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

    /** Parses the 8-byte command header found at the start of a payload. */
    public static function parseHeader(string $payload): array
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

    /**
     * The protocol's checksum: a 16-bit ones'-complement-style sum over the
     * packet, mirroring the algorithm used by every known ZK client library.
     */
    public static function checksum(string $payload): int
    {
        $length = strlen($payload);
        $checksum = 0;
        $i = 0;

        while ($length > 1) {
            $word = ord($payload[$i]) | (ord($payload[$i + 1]) << 8);
            $checksum += $word;
            if ($checksum > self::USHRT_MAX) {
                $checksum -= self::USHRT_MAX;
            }
            $i += 2;
            $length -= 2;
        }

        if ($length === 1) {
            $checksum += ord($payload[$i]);
        }

        while ($checksum > self::USHRT_MAX) {
            $checksum -= self::USHRT_MAX;
        }

        $checksum = ~$checksum;
        while ($checksum < 0) {
            $checksum += self::USHRT_MAX;
        }

        return $checksum & self::USHRT_MAX;
    }
}
