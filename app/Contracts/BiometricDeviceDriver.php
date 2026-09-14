<?php

namespace App\Contracts;

use App\Models\Device;
use App\Support\DeviceProgress;
use Promoseven\Biometric\Capability\CapabilitySet;
use Promoseven\Biometric\Client\Device as ProtocolDevice;
use Promoseven\Biometric\Value\DeviceUser;

/**
 * The seam between this application and any biometric protocol library.
 *
 * Everything above this interface speaks Eloquent, tenancy and policies.
 * Everything below speaks packets. Neither knows about the other.
 *
 * WHY THIS EXISTS: the protocol implementation is a standalone package
 * (promoseven/biometric-protocol) that other platforms are meant to reuse. If app
 * code called it directly, the package would slowly re-acquire Laravel-shaped
 * assumptions and stop being portable. Controllers must depend on services;
 * services depend on THIS; only the driver knows the library.
 *
 * Adding a second vendor (Hikvision, Suprema, Anviz) means writing another
 * implementation of this interface, not touching a controller.
 */
interface BiometricDeviceDriver
{
    /**
     * Runs a callback against an open device session, always closing it.
     *
     * The ONLY way app code touches a device. Takes the Eloquent model and handles
     * mapping it to whatever the underlying library needs.
     *
     * @template T
     *
     * @param  \Closure(ProtocolDevice):T  $callback
     * @return T
     */
    public function run(Device $device, \Closure $callback): mixed;

    /**
     * Opens a session the CALLER owns and must close.
     *
     * run() is the right tool for almost everything: it guarantees the session is
     * closed even when the callback throws. This exists for the one shape run()
     * cannot express — a realtime listener that holds sessions to several devices
     * open at once and waits on all of them together. A callback per device would
     * mean a blocking loop per device, and there is no pcntl on Windows.
     *
     * Callers must close() in a finally block. If you are not writing a listener,
     * use run().
     */
    public function open(Device $device): ProtocolDevice;

    /**
     * Proves the device is reachable and serving commands.
     *
     * @return array{alive:bool, device_time:?\DateTimeImmutable, latency_ms:float}
     */
    public function ping(Device $device): array;

    /**
     * Identity and capacity fields read off the device.
     *
     * @return array<string,mixed>
     */
    public function info(Device $device): array;

    /**
     * Transfers and decodes the device's attendance table.
     *
     * VENDOR-NEUTRAL ON PURPOSE. Transfer and decoding are both vendor-specific —
     * ZKTeco streams a binary table over UDP, a CSL terminal returns tab-separated
     * text from a web form — but what comes back is the same shape either way, so
     * AttendanceSyncService can own watermarks, dedupe and archiving for both
     * without knowing which it is talking to.
     *
     * Every record MUST carry its own `raw` payload. That is what lets a decoder
     * fix be replayed later over punches the device has since rolled over, and it
     * is why this returns records rather than one opaque blob.
     *
     * A record with no `timestamp` key means "arrived but would not decode".
     * Return it anyway — the caller counts and archives it. Dropping it is the
     * failure mode that silently discarded a third of this project's attendance.
     *
     * PROGRESS: $onProgress, when given, is called repeatedly while the table
     * transfers, with an App\Support\DeviceProgress describing how far it has got.
     * It is optional on purpose — every existing caller keeps working and pays
     * nothing — and a driver whose transport cannot report progress simply never
     * calls it, which the UI renders as indeterminate rather than as 0%.
     *
     * The callback runs INSIDE the transfer loop, so it must be fast and must not
     * throw. Throttle anything that writes.
     *
     * @param  ?callable(DeviceProgress):void  $onProgress
     * @return list<array{user_id?:string,timestamp?:\DateTimeInterface,verify_method?:int,status?:int,raw?:string}>
     */
    public function readAttendance(Device $device, ?callable $onProgress = null): array;

    /**
     * Reads the device's user roster.
     *
     * VENDOR-NEUTRAL, for exactly the reason readAttendance() is. Until 2026-09-14
     * reading users was expressed only as "call run() and use the ZKTeco UserService
     * inside the closure", which meant every consumer of a roster was hard-wired to
     * one protocol. Two drivers already could not serve it: CslHttpDriver and
     * C3Driver both throw on run(), so "Import staff" failed on both while the
     * capability gate cheerfully said yes.
     *
     * A driver returns DeviceUser objects however it can get them — a ZKTeco table,
     * a C3 panel's `user` table, an HTML form — and the caller never learns which.
     *
     * $withFingerprints asks the driver to populate DeviceUser::$fingerSlots. It is
     * a REQUEST, not a promise: a driver that cannot read the template table leaves
     * the slots null, which means "not read". Null must never be rendered as "none
     * enrolled" — that tells an operator somebody is unenrolled when they are not
     * (rule 10).
     *
     * @return list<DeviceUser>
     */
    public function readUsers(Device $device, bool $withFingerprints = false): array;

    /**
     * What this device can do.
     *
     * Returns the stored/declared set without touching the network. Use
     * probeCapabilities() to refresh it from the hardware.
     */
    public function capabilities(Device $device): CapabilitySet;

    /** Probes the device read-only and returns a refined capability set. */
    public function probeCapabilities(Device $device): CapabilitySet;

    /** Which protocol family this driver speaks, matching Device::$protocol. */
    public function supports(string $protocol): bool;
}
