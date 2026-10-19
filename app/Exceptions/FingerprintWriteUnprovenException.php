<?php

namespace App\Exceptions;

/**
 * An action needs to write fingerprints to a terminal whose model has never done it
 * from this app (level `unknown`), so it was refused before anything changed. The fix
 * is the terminal's fingerprint-write test (FingerprintPusher::trialWrite), which the
 * screen offers (casebook DC-014, 2026-10-11; CLAUDE.md rule 21).
 */
class FingerprintWriteUnprovenException extends \DomainException
{
    /** @return array{type:string} */
    public function action(): array
    {
        return ['type' => 'test_fingerprint_write'];
    }
}
