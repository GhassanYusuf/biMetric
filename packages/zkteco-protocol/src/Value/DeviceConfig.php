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
    ) {
        if ($host === '') {
            throw new \InvalidArgumentException('Device host cannot be empty.');
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException("Device port must be 1-65535, got {$port}.");
        }
    }

    /** @param array{host:string,port?:int,comm_key?:int,timeout?:float,timezone?:string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            host: $data['host'],
            port: (int) ($data['port'] ?? 4370),
            commKey: (int) ($data['comm_key'] ?? 0),
            timeout: (float) ($data['timeout'] ?? 8.0),
            timezone: $data['timezone'] ?? '+03:00',
        );
    }

    public function endpoint(): string
    {
        return "{$this->host}:{$this->port}";
    }
}
