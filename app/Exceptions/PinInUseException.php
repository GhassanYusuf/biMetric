<?php

namespace App\Exceptions;

/**
 * A user ID (PIN) the employee wants on a terminal is held there by someone else, or
 * was. Carries enough for the screen to offer the fix (casebook DC-014, 2026-10-11).
 *
 * RELEASABLE only when the holder is a record no employee was ever mapped to on that
 * terminal — a leftover test record, say ("ali-it" on IT ROOM). A number that belonged
 * to a real employee is never handed to another: their punches are recorded under it.
 */
class PinInUseException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $pin,
        public readonly ?string $holderName,
        public readonly bool $releasable,
    ) {
        parent::__construct($message);
    }

    /** @return array{type:string, device_user_id:string, holder:?string}|null */
    public function action(): ?array
    {
        return $this->releasable
            ? ['type' => 'release_number', 'device_user_id' => $this->pin, 'holder' => $this->holderName]
            : null;
    }
}
