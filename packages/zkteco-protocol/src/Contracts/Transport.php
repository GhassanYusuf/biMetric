<?php

namespace Zkteco\Protocol\Contracts;

use Zkteco\Protocol\Value\Reply;

/**
 * Byte-level link to a device.
 *
 * Everything above this interface is protocol logic; everything below is sockets.
 * Swapping UDP for TCP, or for a recorded fixture in tests, happens here and
 * nowhere else.
 *
 * Implementations own the session id / reply id counters, because the reply id
 * increments per request and the session id is assigned by the device during the
 * handshake — both are properties of a live link, not of the protocol.
 */
interface Transport
{
    /** Opens the link. Resets session state. */
    public function open(): void;

    /** Closes the link. Safe to call when already closed. */
    public function close(): void;

    public function isOpen(): bool;

    /** Sends one command and waits for its reply. */
    public function send(int $command, string $data = ''): Reply;

    /**
     * Sends raw pre-built bytes without waiting for a reply — used for realtime
     * acknowledgements, which the device does not answer.
     */
    public function sendRaw(string $packet): void;

    /**
     * Reads the next unsolicited datagram, or null if none arrived within the
     * poll window. Used by the realtime event listener.
     */
    public function readUnsolicited(float $pollSeconds = 0.5): ?Reply;

    /** Overrides the read timeout for the duration of a bulk reassembly. */
    public function setReadTimeout(int $seconds, int $microseconds = 0): void;

    public function sessionId(): int;

    public function setSessionId(int $sessionId): void;
}
