<?php

namespace Zkteco\Protocol\Transport;

use Zkteco\Protocol\Contracts\HasReceiveBuffer;
use Zkteco\Protocol\Contracts\Transport;
use Zkteco\Protocol\Exception\ConnectionException;
use Zkteco\Protocol\Exception\TimeoutException;
use Zkteco\Protocol\Protocol\Packet;
use Zkteco\Protocol\Value\DeviceConfig;
use Zkteco\Protocol\Value\Reply;

/**
 * UDP transport for the ZKTeco standalone protocol.
 *
 * CONFIRMED against real hardware (ZKTeco F18): despite this protocol commonly
 * being documented as TCP with an extra 8-byte transport wrapper, this device
 * family speaks it directly over UDP datagrams — one packet per datagram, no
 * wrapper. That is why nothing here calls Packet::wrapTcp().
 *
 * UDP has no delivery guarantee, so a bulk transfer can arrive short when a
 * datagram is dropped. This class does NOT retry individual datagrams; retrying
 * the whole request is simpler and safe, because reading from the device never
 * consumes or deletes anything. That retry lives in BulkReader.
 *
 * The other half of that defence is the socket receive buffer, raised from the
 * 64 KB default to hold a whole table — see enlargeReceiveBuffer(), which is the
 * single most effective fix for short transfers on this protocol.
 */
final class UdpTransport implements HasReceiveBuffer, Transport
{
    /** @var resource|null */
    private $socket = null;

    /**
     * The same socket as $socket, imported as an ext-sockets handle.
     *
     * Held as a property, not a local, ON PURPOSE. socket_import_stream() returns a
     * handle that SHARES the underlying descriptor; letting it fall out of scope
     * has historically closed the stream with it. Keeping it alive for the lifetime
     * of the connection avoids depending on which PHP version fixed that.
     */
    private ?\Socket $rawSocket = null;

    /** What the OS actually granted for SO_RCVBUF, or null when unknown. */
    private ?int $grantedReceiveBuffer = null;

    private int $sessionId = 0;

    private int $replyId = 0;

    public function __construct(private readonly DeviceConfig $config) {}

    public function open(): void
    {
        $errno = 0;
        $errstr = '';

        $socket = @stream_socket_client(
            "udp://{$this->config->host}:{$this->config->port}",
            $errno,
            $errstr,
            $this->config->timeout,
        );

        if ($socket === false) {
            throw new ConnectionException(
                "Unable to reach device at {$this->config->endpoint()} ({$errstr})",
                ['host' => $this->config->host, 'port' => $this->config->port, 'errno' => $errno],
            );
        }

        stream_set_timeout($socket, (int) ceil($this->config->timeout));

        $this->socket = $socket;
        $this->sessionId = 0;
        $this->replyId = 0;

        $this->enlargeReceiveBuffer();
    }

    /**
     * Asks the OS for a receive buffer big enough to hold a whole table.
     *
     * ==========================================================================
     *  THE MOST EFFECTIVE FIX AVAILABLE FOR "Incomplete transfer" — MEASURED
     * ==========================================================================
     *
     * The OS default is 64 KB. One attendance table on an F18 is 412 KB. This
     * protocol has no flow control and the device does not wait for us, so any
     * pause longer than it takes 64 KB to arrive drops datagrams permanently — the
     * kernel discards them before PHP ever sees them.
     *
     * With a 4 MB buffer the entire transfer fits, so a stall costs latency rather
     * than data, and the reader can afford to do work between chunks.
     *
     * BEST EFFORT, BY DESIGN:
     *
     *  - ext-sockets is a SUGGEST, not a require. Without it this returns silently
     *    and the library behaves exactly as it did before — correct, just more
     *    prone to short transfers. Never make this a hard dependency; the package
     *    is meant to install anywhere.
     *  - The size is a request. Linux halves it and clamps to
     *    net.core.rmem_max (raise that if the granted value comes back small);
     *    Windows grants it outright. Read back what was actually given rather than
     *    assuming, which is why grantedReceiveBuffer exists.
     */
    private function enlargeReceiveBuffer(): void
    {
        $this->rawSocket = null;
        $this->grantedReceiveBuffer = null;

        if (! function_exists('socket_import_stream') || $this->config->receiveBufferBytes <= 0) {
            return;
        }

        $raw = @socket_import_stream($this->socket);

        if (! $raw instanceof \Socket) {
            return;
        }

        // Suppressed rather than thrown: a device we can talk to with a small
        // buffer is strictly better than one we refuse to talk to at all.
        @socket_set_option($raw, SOL_SOCKET, SO_RCVBUF, $this->config->receiveBufferBytes);

        $granted = @socket_get_option($raw, SOL_SOCKET, SO_RCVBUF);

        $this->rawSocket = $raw;
        $this->grantedReceiveBuffer = is_int($granted) ? $granted : null;
    }

    /**
     * Receive buffer the OS actually granted, or null if it could not be set.
     *
     * Null means ext-sockets is missing and the socket is on the platform default
     * — worth surfacing when diagnosing repeated short transfers, because it is the
     * difference between 64 KB and 4 MB of headroom.
     */
    public function receiveBufferBytes(): ?int
    {
        return $this->grantedReceiveBuffer;
    }

    public function close(): void
    {
        // Drop the imported handle first — it shares this descriptor, and holding
        // it past fclose() leaves a stale reference to a closed socket.
        $this->rawSocket = null;
        $this->grantedReceiveBuffer = null;

        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    public function isOpen(): bool
    {
        return $this->socket !== null;
    }

    public function send(int $command, string $data = ''): Reply
    {
        if ($this->socket === null) {
            throw new ConnectionException('Not connected to device.');
        }

        [$packet, $this->replyId] = Packet::build($command, $this->sessionId, $this->replyId, $data);

        if (fwrite($this->socket, $packet) === false) {
            throw new ConnectionException('Failed to write to device socket.', [
                'command' => $command,
            ]);
        }

        $datagram = @fread($this->socket, 8192);

        if ($datagram === '' || $datagram === false) {
            $meta = stream_get_meta_data($this->socket);

            if ($meta['timed_out'] ?? false) {
                throw new TimeoutException('Device connection timed out or closed unexpectedly.', [
                    'command' => $command,
                    'endpoint' => $this->config->endpoint(),
                ]);
            }

            throw new ConnectionException('No response from device.', [
                'command' => $command,
                'endpoint' => $this->config->endpoint(),
            ]);
        }

        return Reply::fromArray(Packet::parse($datagram));
    }

    public function sendRaw(string $packet): void
    {
        if ($this->socket === null) {
            return;
        }

        @fwrite($this->socket, $packet);
    }

    public function readUnsolicited(float $pollSeconds = 0.5): ?Reply
    {
        if ($this->socket === null) {
            throw new ConnectionException('Not connected to device.');
        }

        $seconds = (int) $pollSeconds;
        $this->setReadTimeout($seconds, (int) round(($pollSeconds - $seconds) * 1_000_000));

        $datagram = @fread($this->socket, 8192);

        if ($datagram === '' || $datagram === false) {
            return null;
        }

        return Reply::fromArray(Packet::parse($datagram));
    }

    public function setReadTimeout(int $seconds, int $microseconds = 0): void
    {
        if ($this->socket !== null) {
            stream_set_timeout($this->socket, $seconds, $microseconds);
        }
    }

    /** Restores the configured request/reply timeout after a bulk reassembly. */
    public function resetReadTimeout(): void
    {
        $this->setReadTimeout((int) ceil($this->config->timeout));
    }

    public function sessionId(): int
    {
        return $this->sessionId;
    }

    public function setSessionId(int $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function config(): DeviceConfig
    {
        return $this->config;
    }

    /** @return resource|null The UDP socket, for stream_select() over several devices. */
    public function selectableStream()
    {
        return $this->socket;
    }

    public function __destruct()
    {
        $this->close();
    }
}
