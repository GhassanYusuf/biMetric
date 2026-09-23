<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a group of terminals that ONE vendor tool keeps in step.
 *
 * WHY THIS IS THE MISSING FACT. A PIN is normally local to one terminal — on this
 * estate PIN 1 is three different people across six devices, which is why nothing may
 * join on it. But when a single ZKAccess install manages a set of terminals, it holds
 * one employee list and pushes the SAME badge number to every device in that set. A
 * PIN then identifies a person across the whole set, and that is a fact about the
 * installation which no amount of reading the wire can discover.
 *
 * EVIDENCE, 2026-09-15, from the vendor's own Access.mdb:
 *
 *   Machines            4 rows: 192.168.7.60, .61, .62, .63 — exactly the members of
 *                       the "Promoseven Holdings" group, and nothing else.
 *   USERINFO            334 people, 334 distinct Badgenumbers.
 *   Cross-check         320 distinct PINs across those four terminals; all 320 present
 *                       in USERINFO; all 669 roster names agreed with USERINFO exactly,
 *                       zero disagreements.
 *
 * So this is operator-declared and evidence-backed, not inferred. It must stay OFF by
 * default: asserting it wrongly merges different people, which is the failure this
 * whole identity layer exists to prevent. Good Wheels and Camelot are separate
 * installs and are deliberately not in any such group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_groups', function (Blueprint $table) {
            $table->boolean('shared_pin_namespace')->default(false)->after('description');
            // What the claim rests on. A flag this powerful should not be a bare
            // boolean somebody turned on once for a reason nobody recorded.
            $table->string('pin_namespace_evidence')->nullable()->after('shared_pin_namespace');
        });
    }

    public function down(): void
    {
        Schema::table('device_groups', function (Blueprint $table) {
            $table->dropColumn(['shared_pin_namespace', 'pin_namespace_evidence']);
        });
    }
};
