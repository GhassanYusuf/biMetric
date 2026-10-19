<?php

namespace App\Exceptions;

use Promoseven\Biometric\Exception\DeviceException;

/**
 * The terminal is in use by another operator or a background job, and did not come
 * free in time (2026-10-12). Thrown by DeviceDriverManager's per-terminal queue.
 *
 * A DeviceException so every existing catch reports it like any other device failure
 * — with a message an operator can act on — and RETRYABLE, so a queued command retries
 * with backoff (rule 11) instead of failing for good. Nothing was sent to the terminal.
 */
class DeviceBusyException extends DeviceException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
