<?php

namespace App\Exceptions;

use App\Models\Device;

/**
 * A device write was refused because the target PIN belongs to somebody else.
 *
 * WHY THIS IS AN EXCEPTION AND NOT A WARNING: the ZKTeco save() is an upsert. Writing
 * to an occupied slot does not fail — it OVERWRITES the record already there, taking
 * that person's fingerprint templates with it. A template cannot be restored from a
 * backup; the employee has to stand at the terminal again.
 *
 * This happened in production on 2026-09-15: employees were pushed to an existing
 * terminal by payroll code, and every collision silently replaced a real enrolled
 * person. The guard that throws this is the reason it cannot happen twice.
 *
 * Recovery is always an operator decision — claim the existing device user, or
 * allocate a different PIN — so this deliberately offers no automatic resolution.
 */
class DeviceIdentityConflictException extends \RuntimeException
{
    public function __construct(
        public readonly Device $device,
        public readonly string $pin,
        public readonly string $occupantName,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function occupied(Device $device, string $pin, string $occupantName, string $wanted): self
    {
        $who = $occupantName !== '' ? "\"{$occupantName}\"" : 'an unnamed record';

        return new self(
            $device,
            $pin,
            $occupantName,
            "Refused to write {$wanted} to {$device->name} at PIN {$pin}: that slot already holds {$who}, "
            ."who is not claimed by this employee. Writing would have overwritten their record and any "
            ."fingerprints with it. Claim the existing device user or allocate a different PIN.",
        );
    }
}
