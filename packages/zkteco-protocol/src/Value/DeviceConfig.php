<?php

namespace Zkteco\Protocol\Value;

/**
 * Everything the library needs to reach one device.
 *
 * This value object is the seam that keeps the library framework-agnostic: the
 * old implementation had ZktecoDevice::fromModel(\App\Models\Device), which made
 * the protocol layer depend on Eloquent. A host app now maps its own model to
 * this object in its adapter instead.
 */
final class DeviceConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port = 4370,
        public readonly int $commKey = 0,
        public readonly float $timeout = 8.0,
        /**
         * Wall-clock offset the device stamps records with.
         *
         * CONFIRMED against real hardware: the device stamps attendance with its
         * own LOCAL time, not UTC. Callers store UTC, so decoded timestamps must
         * be anchored to the device's real zone or every value is off by that
         * offset — and a browser then applies the viewer's offset on top, doubling
         * the error.
         *
         * A fixed offset rather than a named zone is deliberate for deployments
         * that do not observe DST; pass a named zone ('Europe/Berlin') where DST
         * applies.
         */
        public readonly string $timezone = '+03:00',

        /**
         * Socket receive buffer to ask the OS for, in bytes.
         *
         * MEASURED, and the single highest-leverage number in this class. The OS
         * default is 64 KB; one attendance table on an F18 is 412 KB. This protocol
         * has no flow control, so the moment the reader pauses — for a progress
         * callback, a GC cycle, a scheduler slice — the device keeps blasting and
         * anything past 64 KB is dropped by the kernel. That surfaces as
         * "Incomplete transfer: got 89,088 of 412,084 bytes", which then burns all
         * five whole-request retries.
         *
         * 4 MB holds a ~100,000-record table whole, so a stall costs latency
         * instead of data. It is a REQUEST, not a guarantee: platforms clamp it,
         * and the granted size is readable via receiveBufferBytes() for diagnosis.
         *
         * Requires ext-sockets. Without it the request is skipped silently and the
         * library still works exactly as before — see UdpTransport::open().
         */
        public readonly int $receiveBufferBytes = 4 * 1024 * 1024,
    ) {
        if ($host === '') {
            throw new \InvalidArgumentException('Device host cannot be empty.');
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException("Device port must be 1-65535, got {$port}.");
        }
    }

    /** @param array{host:string,port?:int,comm_key?:int,timeout?:float,timezone?:string,receive_buffer_bytes?:int} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            host: $data['host'],
            port: (int) ($data['port'] ?? 4370),
            commKey: (int) ($data['comm_key'] ?? 0),
            timeout: (float) ($data['timeout'] ?? 8.0),
            timezone: $data['timezone'] ?? '+03:00',
            receiveBufferBytes: (int) ($data['receive_buffer_bytes'] ?? 4 * 1024 * 1024),
        );
    }

    public function endpoint(): string
    {
        return "{$this->host}:{$this->port}";
    }
}
