<?php

namespace Zkteco\Protocol\Transport;

use Zkteco\Protocol\Contracts\Transport;
use Zkteco\Protocol\Exception\DeviceException;
use Zkteco\Protocol\Exception\ProtocolException;
use Zkteco\Protocol\Protocol\Command;
use Zkteco\Protocol\Protocol\Packet;
use Zkteco\Protocol\Session\Session;

/**
 * Reassembles a chunked bulk transfer (users, attendance, templates).
 *
 * ===========================================================================
 *  THE SINGLE MOST IMPORTANT BEHAVIOUR IN THIS LIBRARY — do not "optimise" it
 * ===========================================================================
 *
 * EVERY bulk read opens a FRESH session. Not just retries — every single read.
 *
 * A completed bulk transfer can leave the device still pushing chunks. Reusing
 * the session makes the NEXT request return those leftovers, and they arrive as a
 * perfectly well-formed CMD_DATA reply — so the read "succeeds" with the wrong
 * table instead of failing loudly.
 *
 * CONFIRMED on real hardware (ZKTeco F18): getUsers() immediately followed by a
 * template read returned 1024 bytes of the PREVIOUS transfer. It decoded to
 * nothing, which made every employee report "fingerprints unknown" — a silent
 * wrong answer, not an error. A diagnostic probe reusing one session reported
 * "0 users" on a 19-user device for the same reason.
 *
 * Reconnecting costs one handshake per bulk read. That is the price of a correct
 * answer, and reads never consume data on the device so it is always safe.
 */
final class BulkReader
{
    /**
     * Whole-request retries. UDP drops datagrams; retrying the entire request is
     * safe because reading never mutates the device.
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * Consecutive empty polls before a reassembly is abandoned. At 200ms each this
     * is ~8 seconds of silence — long enough for a real gap between chunks, short
     * enough not to hang a request.
     */
    private const MAX_EMPTY_POLLS = 40;

    private readonly Transport $transport;

    public function __construct(private readonly Session $session)
    {
        $this->transport = $session->transport();
    }

    /**
     * Requests a table and returns its raw bytes.
     *
     * @param int    $command     e.g. Command::USERTEMP_RRQ, Command::ATTLOG_RRQ
     * @param string $requestData selector payload, e.g. pack('C', Command::FCT_FINGERTMP)
     */
    public function read(int $command, string $requestData = ''): string
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                // Fresh session for EVERY attempt, including the first. See the
                // class docblock — this is not defensive, it is load-bearing.
                $this->session->reopen();

                $data = $this->readOnce($command, $requestData);

                /*
                 * CONFIRMED: the device keeps sending after we have taken the bytes we
                 * asked for. We stop as soon as the declared size is satisfied, so
                 * trailing chunks stay queued in the socket.
                 *
                 * Mark the session dirty so the NEXT command reopens instead of
                 * reading one of those chunks as its own reply. Without this, a probe
                 * issued after a bulk read returns the PREVIOUS request's answer —
                 * observed as template slot 7 reporting slot 6's 1304-byte template.
                 */
                $this->session->markDirty();

                return $data;
            } catch (DeviceException $e) {
                $lastException = $e;
            }
        }

        throw $lastException ?? new ProtocolException('Bulk data transfer failed after retries.', [
            'command' => Command::name($command),
            'attempts' => self::MAX_ATTEMPTS,
        ]);
    }

    private function readOnce(int $command, string $requestData): string
    {
        $reply = $this->transport->send($command, $requestData);

        // Success with nothing to send — a genuinely empty table.
        if ($reply->isAccepted() && $reply->length() === 0) {
            return '';
        }

        if (! $reply->isPreparingData() && ! $reply->isInlineData()) {
            throw new ProtocolException(
                "Unexpected reply {$reply->commandName()} while requesting bulk data.",
                ['command' => Command::name($command), 'reply' => $reply->command],
            );
        }

        // Small tables arrive whole in the first packet.
        if ($reply->isInlineData()) {
            return $reply->data;
        }

        $size = $reply->declaredSize();

        // The request/reply timeout is seconds long, but here we are reassembling
        // many back-to-back chunks — a brief gap between them is normal and must
        // not cost a multi-second stall each time. Poll fast, restore afterwards.
        $this->transport->setReadTimeout(0, 200_000);

        try {
            $buffer = $this->reassemble($size);
        } finally {
            if ($this->transport instanceof UdpTransport) {
                $this->transport->resetReadTimeout();
            }
        }

        if (strlen($buffer) < $size) {
            throw new ProtocolException(
                'Incomplete transfer: got ' . strlen($buffer) . " of {$size} bytes.",
                ['command' => Command::name($command), 'expected' => $size, 'received' => strlen($buffer)],
            );
        }

        return substr($buffer, 0, $size);
    }

    private function reassemble(int $size): string
    {
        $buffer = '';
        $emptyPolls = 0;

        while (strlen($buffer) < $size && $emptyPolls < self::MAX_EMPTY_POLLS) {
            $chunk = $this->transport->readUnsolicited(0.2);

            if ($chunk === null) {
                $emptyPolls++;
                continue;
            }

            $emptyPolls = 0;
            $buffer .= $chunk->data;
        }

        return $buffer;
    }

    /** Acknowledges an unsolicited realtime push. */
    public static function acknowledge(Transport $transport, int $sessionId): void
    {
        $transport->sendRaw(Packet::buildRealtimeAck($sessionId));
    }
}
