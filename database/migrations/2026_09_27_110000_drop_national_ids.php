<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes national ids from the system, at the owner's request (2026-09-27).
 *
 * Drops the superseded-id history table and the encrypted value + blind index on
 * `people`. The Person layer itself stays: employees still carry person_id, and
 * `hrms_person_id` remains as a join key. Both tables were empty when this ran.
 *
 * down() restores the structure only. The values are gone; the pre-wipe backups of
 * 2026-09-27 are the only copy (see docs/PROJECT-STATE.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('person_national_ids');

        Schema::table('people', function (Blueprint $table) {
            $table->dropUnique(['national_id_hash']);
        });

        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['national_id', 'national_id_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->text('national_id')->nullable();
            $table->string('national_id_hash')->nullable()->unique();
        });

        Schema::create('person_national_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained()->cascadeOnDelete();
            $table->text('national_id');
            $table->string('national_id_hash')->index();
            $table->timestamp('superseded_at')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }
};
