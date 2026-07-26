<?php

namespace Zkteco\Protocol\Exception;

/**
 * The device did not answer in time.
 *
 * Distinct from ConnectionException: the socket was fine, the device simply did
 * not reply — which is also the normal outcome of an enrollment nobody attended.
 */
class TimeoutException extends DeviceException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
