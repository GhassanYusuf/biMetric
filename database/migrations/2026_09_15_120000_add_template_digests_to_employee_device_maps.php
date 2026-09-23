<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SHA-1 digests of the fingerprint templates a terminal holds for this person.
 *
 * WHY DIGESTS AND NOT THE TEMPLATES. A vendor tool that enrols a finger once and
 * pushes it to the other terminals leaves byte-identical templates behind, so a
 * matching digest proves two device records are one human — better evidence than a
 * name or even a card. Confirmed 2026-09-15: PIN 261 slot 3 is the same 1,054 bytes
 * on the F18 at .62 and the F22 at .63.
 *
 * The BYTES are deliberately not stored. They are biometric data: keeping them turns
 * this database into a far more sensitive asset, invites them into a log line or an
 * API response, and buys nothing the digest does not already give us for matching.
 * Writing a template back to a device is a separate, still-unverified problem, and
 * the day that is solved is the day to revisit this — not before.
 *
 * Null means NOT READ. An empty array means the table decoded and this person has no
 * templates on that terminal. The two must never collapse (rule 10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_device_maps', function (Blueprint $table) {
            // ["<slot>:<sha1>", …] — the slot matters because one person's index and
            // thumb are different enrolments and must not be compared to each other.
            $table->json('template_digests')->nullable()->after('fingerprint_slots');
            $table->timestamp('templates_read_at')->nullable()->after('template_digests');
        });
    }

    public function down(): void
    {
        Schema::table('employee_device_maps', function (Blueprint $table) {
            $table->dropColumn(['template_digests', 'templates_read_at']);
        });
    }
};
