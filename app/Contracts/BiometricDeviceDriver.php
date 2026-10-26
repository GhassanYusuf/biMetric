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
     * Every stored fingerprint template on the device, as opaque identifiers.
     *
     * WHAT THIS IS FOR: deciding whether two device records are the same human. A
     * vendor tool that enrols a finger once and pushes it to the other terminals
     * leaves BYTE-IDENTICAL templates behind, so a matching digest is hardware-grade
     * evidence of one person — stronger than a name, stronger than a card.
     *
     * CONFIRMED on 2026-09-15: PIN 261 slot 3 returned the same 1,054 bytes from the
     * F18 at 192.168.7.62 and the F22 at 192.168.7.63. Identical across two different
     * models, which is what makes the digest usable as an identifier at all.
     *
     * READ IT THE RIGHT WAY ROUND. Identical digests mean one enrolment, copied.
     * DIFFERENT digests mean NOTHING: re-presenting the same finger produces a
     * different template every time, and there is no matcher here. Never conclude
     * "different people" from a digest mismatch.
     *
     * NO BYTES CROSS THIS SEAM. A driver returns sizes and digests; the template
     * itself stays inside the protocol layer. Biometric data that is never carried
     * upward cannot be logged, serialised into an API response or committed to a
     * trace by accident.
     *
     * An empty array means the table would not decode — UNKNOWN, never "nobody is
     * enrolled" (rule 10). A driver whose family has no readable template table
     * throws UnsupportedCommandException rather than returning empty, so the two
     * cases stay distinguishable.
     *
     * @return list<array{user_id:?string,uid:int,slot:int,size:int,sha1:string}>
     */
    public function readTemplateDigests(Device $device): array;

    /**
     * Every template on the device WITH its bytes — biometric data.
     *
     * Exists for one consumer: App\Services\Employees\FingerprintVault, which keeps an
     * encrypted copy per employee (owner's decision, 2026-09-24). Nothing else may call
     * it, log its result, or return it over HTTP. Same table, same read as
     * readTemplateDigests(); `valid` is the record's own flag byte.
     *
     * @return list<array{user_id:?string,uid:int,slot:int,valid:int,size:int,sha1:string,template:string}>
     */
    public function readTemplates(Device $device): array;

    /**
     * Writes a user record, having first proved the slot is not somebody else's.
     *
     * WHY THIS IS ON THE CONTRACT rather than done by a service inside a run()
     * closure: the read that proves the slot is free and the write that fills it MUST
     * share one session. This protocol is stateful and permits a single session per
     * device (rule 11), so checking in one session and writing in another leaves a
     * window — and a window here means overwriting a stranger's enrollment.
     *
     * WHAT IT PREVENTS. save() on this firmware family is an UPSERT. Writing to an
     * occupied slot does not fail; it replaces the record and that person's fingerprint
     * templates with it. Templates cannot be recovered from a backup — the employee has
     * to stand at the terminal again. On 2026-09-15 employees were pushed to terminals
     * that had been in service for years, addressed by payroll code, and every
     * collision destroyed a real enrollment. This method is the reason that cannot
     * recur: there is no unguarded write path left above the driver.
     *
     * $guard receives whoever currently holds the slot — or null when it is empty —
     * and throws to abandon the write. The driver calls it after the read and before
     * the write, inside the session. A driver MUST NOT write if the guard throws, and
     * MUST NOT skip calling it. A guard that is null means the caller has accepted an
     * unguarded upsert, which only a caller that just read the slot itself may do.
     *
     * $onlyIfMissing writes nothing when the record already exists, returning it
     * unchanged — for callers that need the record to exist before attaching a
     * credential to it, not to update its fields.
     *
     * @param  ?\Closure(?DeviceUser):void  $guard
     */
    /*
     * $privilege (2026-10-11): null keeps what the terminal record has (0 for a new
     * record). Passed explicitly only to put back a privilege the person had before
     * their record was re-created — an admin re-added as a normal user is a silent
     * demotion (rule 19). Ignored by families with no per-user privilege (C3).
     *
     * $password (2026-10-12): the keypad password. Null keeps whatever the terminal
     * record has — before library 2.13.0 a standalone write erased it — and '' clears
     * it. Read back after the write and never put in a message (a door credential).
     * Callers pass one only where the model's `password.write` allows (rule 9).
     */
    public function saveUser(
        Device $device,
        string $userId,
        string $name,
        ?\Closure $guard = null,
        bool $onlyIfMissing = false,
        ?int $privilege = null,
        ?string $password = null,
    ): DeviceUser;

    /**
     * Deletes a user record, with its templates and card.
     *
     * Vendor-neutral for the same reason readUsers() is, and expressed here rather
     * than as a run() closure so that no app service has to name a protocol session
     * in order to remove somebody.
     */
    public function deleteUser(Device $device, string $userId): void;

    /**
     * Moves an existing user record to a different user id ("PIN") in one session,
     * keeping its card, privilege and fingerprints, and returns it as read back.
     *
     * Refuses when the new id is held by anyone on the device. A driver MUST verify
     * rather than trust the ACK: that the record now sits under the new id, that the
     * old id is gone, and — where its family can read templates — that no finger was
     * lost. EXPERIMENTAL on every model as of 2026-09-23; see
     * docs/device-capabilities.md (user.renumber).
     */
    public function renumberUser(Device $device, string $fromUserId, string $toUserId): DeviceUser;

    /**
     * Writes a card number onto an EXISTING user record, keeping name, privilege and
     * fingerprints, and returns the record as read back afterwards.
     *
     * A contract method rather than a run() closure for the same reason as
     * deleteUser(). The caller compares the returned card with the one it asked for:
     * this firmware family ACKs writes it then drops (rule 10), so the ACK proves
     * nothing. 0 clears the card.
     */
    public function setUserCard(Device $device, string $userId, int $cardNumber): DeviceUser;

    /**
     * Makes an EXISTING user an administrator of the terminal (may open its menu) or a
     * normal user, keeping name, card, password and fingerprints, and returns the record
     * as read back — the driver confirms the privilege took (rule 10). Gated by
     * user.privilege; families with no per-user privilege throw UnsupportedCommandException.
     * Added 2026-10-18 (owner: "be able to change the access on the device").
     */
    public function setUserPrivilege(Device $device, string $userId, bool $admin): DeviceUser;

    /**
     * How many attendance records the terminal says it holds, from its own counters,
     * without transferring the log. Null when this family cannot say — never a guessed
     * 0 (rule 10). Added 2026-10-18.
     */
    public function attendanceRecordCount(Device $device): ?int;

    /**
     * ERASES the terminal's attendance log; users, cards and fingerprints are kept.
     * Call only through AttendanceClearService, which stores every record first.
     *
     * $expectedRecords is how many records the caller has just read and stored. The
     * driver re-reads the terminal's own count in the same session and refuses, sending
     * nothing, when it differs; afterwards the count must be 0 or it throws. Returns
     * the number of records cleared. Gated by attendance.clear. Added 2026-10-18.
     */
    public function clearAttendance(Device $device, int $expectedRecords): int;

    /**
     * REMOVES EVERY USER from the terminal — records, fingerprints, cards and passwords
     * with them. Call only through DeviceWipeService, which saves what the owner's wipe
     * procedure keeps first (CLAUDE.md rule 22). The user table is read back and must
     * be empty, or it throws. Returns how many were removed. Gated by device.wipe.
     * Added 2026-10-21.
     */
    public function deleteAllUsers(Device $device): int;

    /**
     * Reboots the terminal. Returns as soon as it has ACKed and dropped the session; it is
     * then away for some seconds (25 s on Camelot), so the caller waits for it to answer
     * again. Nothing in its tables changes. Throws when it refuses. Gated by
     * device.restart; call through DeviceRestartService. Added 2026-10-26 (DC-034: a
     * restart clears the state in which Camelot refuses every user insert).
     */
    public function restart(Device $device): void;

    /**
     * The terminal's clock as it shows it (LOCAL time in the device's zone), or null
     * when this family has no way to read it — "not read", never midnight (rule 10).
     * Added 2026-10-11 so the clock can be read on every family, each its own way.
     */
    public function readClock(Device $device): ?\DateTimeImmutable;

    /**
     * Sets the terminal's clock to the real time in its zone (Timezone::forDevice)
     * and returns ['before' => …, 'after' => …] as read from the terminal — the caller
     * checks `after` rather than trusting the acknowledgement (rule 10).
     * Gated by device.time.write.
     *
     * @return array{before:?\DateTimeImmutable, after:?\DateTimeImmutable}
     */
    public function setClock(Device $device): array;

    /**
     * Writes fingerprint templates for a user the device already holds — biometric
     * data going DOWN, the mirror of readTemplates(). Added 2026-09-24 so a finger
     * enrolled once (on an F18, say) reaches the C3 panels, which cannot capture one.
     *
     * ADD-ONLY: a slot the device already fills is skipped, never overwritten. Returns
     * the slots written and CONFIRMED by a read-back; a driver MUST verify per finger
     * rather than trust the acknowledgement (rule 10), and MUST leave the user's
     * existing fingers as they were when it fails.
     *
     * Only App\Services\Employees\FingerprintPusher calls this: like readTemplates(),
     * its argument is biometric data and must not be logged or returned over HTTP.
     *
     * @param  list<array{slot:int, template:string, valid?:int}>  $templates
     * @return list<int> slots written
     */
    public function uploadTemplates(Device $device, string $userId, array $templates): array;

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
