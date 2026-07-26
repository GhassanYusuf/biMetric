<?php

namespace Zkteco\Protocol\Exception;

/**
 * The device answered with something this implementation cannot interpret: an
 * unexpected command code, a malformed record table, a truncated bulk transfer.
 *
 * Not retryable by default — a firmware whose layout we misread will misread
 * identically next time. Bulk transfers retry at the transport level instead,
 * where a dropped UDP datagram is the actual cause.
 */
class ProtocolException extends DeviceException
{
}
