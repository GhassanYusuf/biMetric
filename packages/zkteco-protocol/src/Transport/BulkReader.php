<?php

namespace Zkteco\Protocol\Transport;

use Zkteco\Protocol\Contracts\HasReceiveBuffer;
use Zkteco\Protocol\Contracts\ProgressListener;
use Zkteco\Protocol\Contracts\Transport;
use Zkteco\Protocol\Exception\DeviceException;
use Zkteco\Protocol\Exception\ProtocolException;
use Zkteco\Protocol\Protocol\Command;
use Zkteco\Protocol\Protocol\Packet;
use Zkteco\Protocol\Session\Session;
use Zkteco\Protocol\Value\Progress;

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

    /**
     * Consecutive empty polls before progress is reported from inside the loop.
     *
     * Three polls is ~600ms of proven silence. Anything less and we are guessing
     * that the device has paused; at three we KNOW it has, so a listener can run
     * without competing with datagrams for the receive buffer. See emit().
     */
    private const IDLE_POLLS_BEFORE_REPORT = 3;

    private readonly Transport $transport;

    /**
     * Set for the duration of one read() so the reassembler can label its events
     * without threading the name through every private method.
     */
    private string $operation = 'bulk';

    /** Which whole-request attempt is running; a retry restarts the byte count. */
    private int $attempt = 1;

    /**
     * Last value handed to the listener, so the same reading is not sent twice.
     *
     * With buffer headroom the final chunk already reports completion, and the
     * post-loop emit would repeat it. Harmless on a bar, but a listener that
     * counts events or writes a row should not see phantom duplicates.
     */
    private ?int $lastEmitted = null;

    public function __construct(
        private readonly Session $session,
        private readonly ?ProgressListener $progress = null,
    ) {
        $this->transport = $session->transport();
    }

    /**
     * Requests a table and returns its raw bytes.
     *
     * @param  int  $command  e.g. Command::USERTEMP_RRQ, Command::ATTLOG_RRQ
     * @param  string  $requestData  selector payload, e.g. pack('C', Command::FCT_FINGERTMP)
     */
    public function read(int $command, string $requestData = '', string $operation = 'bulk'): string
    {
        $lastException = null;
        $this->operation = $operation;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $this->attempt = $attempt;
            $this->lastEmitted = null; // a retry restarts the count from zero

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

        // Small tables arrive whole in the first packet. There was never a
        // PREPARE_DATA and so never a declared total — report the transfer as done
        // with the size we actually received, rather than leaving a caller's bar
        // stuck at nothing because no chunk loop ever ran.
        if ($reply->isInlineData()) {
            $this->emit(strlen($reply->data), strlen($reply->data));

            return $reply->data;
        }

        $size = $reply->declaredSize();

        // Emit once at zero so a caller knows the TOTAL before any bytes arrive.
        // Without this the first event a UI sees is already part-way in, and the
        // bar has to guess its own scale.
        $this->emit(0, $size);

        // The request/reply timeout is seconds long, but here we are reassembling
        // many back-to-back chunks — a brief gap between them is normal and must
        // not cost a multi-second stall each time. Poll fast, restore afterwards.
        $this->transport->setReadTimeout(0, 200_000);

        try {
            $buffer = $this->reassemble($size);

            // The loop only reports during idle windows, so a transfer that never
            // paused has reported nothing since the initial zero. Close it out.
            $this->emit(strlen($buffer), $size);
        } finally {
            if ($this->transport instanceof UdpTransport) {
                $this->transport->resetReadTimeout();
            }
        }

        if (strlen($buffer) < $size) {
            throw new ProtocolException(
                'Incomplete transfer: got '.strlen($buffer)." of {$size} bytes.",
                ['command' => Command::name($command), 'expected' => $size, 'received' => strlen($buffer)],
            );
        }

        return substr($buffer, 0, $size);
    }

    private function reassemble(int $size): string
    {
        $buffer = '';
        $emptyPolls = 0;

        /*
         * Can we afford to report on every chunk?
         *
         * Only if the kernel can hold the WHOLE remaining transfer while we are not
         * reading. When the receive buffer is at least the declared size, a pause
         * cannot cost a datagram — the buffer absorbs everything the device sends
         * meanwhile — so the listener may run freely and the bar is smooth.
         *
         * Otherwise we are back on the 64 KB default against a 412 KB table, where
         * any pause loses data permanently, and reporting falls back to proven
         * silence only. See emit().
         */
        $headroom = $this->receiveBufferBytes();
        $canReportFreely = $headroom !== null && $headroom >= $size;

        while (strlen($buffer) < $size && $emptyPolls < self::MAX_EMPTY_POLLS) {
            $chunk = $this->transport->readUnsolicited(0.2);

            if ($chunk === null) {
                $emptyPolls++;

                // Proven silence: safe to report even with no buffer headroom.
                if ($emptyPolls === self::IDLE_POLLS_BEFORE_REPORT) {
                    $this->emit(strlen($buffer), $size);
                }

                continue;
            }

            $emptyPolls = 0;
            $buffer .= $chunk->data;

            // Per-chunk reporting ONLY with buffer headroom. Without it this line
            // is the difference between a smooth bar and a failed sync — see
            // emit(), which carries the measurements.
            if ($canReportFreely) {
                $this->emit(strlen($buffer), $size);
            }
        }

        return $buffer;
    }

    /**
     * Hands one progress event to the listener, if there is one.
     *
     * ==========================================================================
     *  CALLED ONLY FROM IDLE WINDOWS — CONFIRMED ON REAL HARDWARE (ZKTeco F18)
     * ==========================================================================
     *
     * The first version of this called emit() once per received chunk. That is the
     * obvious place for it and it is WRONG, because this transfer has no flow
     * control: the device streams UDP datagrams as fast as it can and anything not
     * read promptly overflows the socket receive buffer and is gone. Time spent
     * inside the loop is data lost.
     *
     * MEASURED on a 412,084-byte / 10,302-record attendance table, reading the same
     * device repeatedly:
     *
     *   no listener at all ......................... 4 of 4 transfers complete
     *   a listener doing nothing at all ............ 2 of 3 complete
     *   a listener doing one throttled DB UPDATE ... 0 of 3 complete
     *                                                ("Incomplete transfer: got
     *                                                 89,088 of 412,084 bytes")
     *
     * The failures were not a slow bar, they were a FAILED SYNC.
     *
     * THE ROOT CAUSE turned out to be the socket receive buffer: the OS default is
     * 64 KB against a 412 KB table, so the kernel discarded the overflow before PHP
     * ever saw it. With ext-sockets present, UdpTransport now asks for 4 MB and the
     * whole transfer fits. Re-running the same A/B with that in place:
     *
     *   12 of 12 transfers complete, writes enabled or not.
     *
     * So reporting is now ADAPTIVE, and the condition is exact rather than a guess:
     *
     *   - buffer >= declared size -> report on EVERY chunk. A pause cannot cost a
     *     datagram, because the kernel is holding everything the device sends while
     *     we are away. The bar is smooth.
     *   - otherwise (no ext-sockets, or a platform that clamped the request) ->
     *     report only after IDLE_POLLS_BEFORE_REPORT consecutive silent polls, plus
     *     once before and once after the loop. Coarse, but it cannot lose punches.
     *
     * Do not collapse those two branches. The unbuffered path is what a fresh
     * install without ext-sockets gets, and this project has already silently
     * discarded a third of its attendance once.
     *
     * Swallows anything the listener throws. Progress is an aside: a broken reporter
     * must never fail a read that was otherwise working.
     */
    /**
     * The socket receive buffer the OS granted, or null when unknown.
     *
     * Deliberately NOT on the Transport contract: it is a datagram concern, and a
     * stream or fixture transport has no equivalent. Unknown is treated as "no
     * headroom", which is the safe direction.
     */
    private function receiveBufferBytes(): ?int
    {
        return $this->transport instanceof HasReceiveBuffer
            ? $this->transport->receiveBufferBytes()
            : null;
    }

    private function emit(int $bytesRead, int $bytesTotal): void
    {
        if ($this->progress === null || $bytesRead === $this->lastEmitted) {
            return;
        }

        $this->lastEmitted = $bytesRead;

        try {
            $this->progress->onProgress(
                new Progress($this->operation, $bytesRead, $bytesTotal, $this->attempt),
            );
        } catch (\Throwable) {
            // Deliberately ignored — see the docblock.
        }
    }

    /** Acknowledges an unsolicited realtime push. */
    public static function acknowledge(Transport $transport, int $sessionId): void
    {
        $transport->sendRaw(Packet::buildRealtimeAck($sessionId));
    }
}
