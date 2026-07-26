<?php

namespace Zkteco\Protocol\Exception;

/**
 * A write was acknowledged but did not survive being read back.
 *
 * This firmware family genuinely does this: it returns CMD_ACK_OK and then
 * silently drops the change. Every write in this library reads back and confirms,
 * and raises this when the device's own tables disagree with what it accepted.
 */
class VerificationException extends DeviceException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
