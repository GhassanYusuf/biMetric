<?php

namespace Zkteco\Protocol\Transport;

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
 */
final class UdpTransport implements Transport
{
    /** @var resource|null */
    private $socket = null;

    private int $sessionId = 0;
    private int $replyId = 0;

    public function __construct(private readonly DeviceConfig $config)
    {
    }

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
    }

    public function close(): void
    {
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

    /** @return resource|null Raw socket, for stream_select() over several devices. */
    public function socket()
    {
        return $this->socket;
    }

    public function __destruct()
    {
        $this->close();
    }
}
