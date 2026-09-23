<?php

namespace App\Exceptions;

use App\Models\Device;

/**
 * A write was refused because the device has not been adopted.
 *
 * Adoption means: its existing roster has been read, and every person already on the
 * hardware has been claimed by an employee or explicitly ignored. Until that is true
 * we do not know which PINs are occupied, and a write is a coin flip on somebody
 * else's enrollment.
 *
 * Operator-facing on purpose — this is a normal state for a newly added terminal, not
 * a fault. It tells them what to do next rather than reporting a failure.
 */
class DeviceNotAdoptedException extends \RuntimeException
{
    public function __construct(public readonly Device $device, string $message)
    {
        parent::__construct($message);
    }
}
