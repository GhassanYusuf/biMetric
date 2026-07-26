<?php

namespace Zkteco\Protocol\Exception;

/** The device could not be reached, or refused the handshake / comm key. */
class ConnectionException extends DeviceException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
