<?php

namespace Zkteco\Protocol\Exception;

/**
 * The device or its firmware does not implement this operation.
 *
 * Thrown instead of returning a plausible-looking empty result, so a caller can
 * never mistake "this firmware cannot tell me" for "there is nothing there".
 * Surface it to the UI as a disabled action, not as an error.
 */
class UnsupportedCommandException extends DeviceException
{
}
