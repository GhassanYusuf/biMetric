<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The number the TERMINAL recorded a punch under, kept when device_user_id is replaced by
 * the person's CPR (owner, 2026-11-05: "on punches from September onwards replace the
 * values of the device user id to be the CPR, so it shows in the HR system").
 *
 * Null on every punch that was never replaced: there, device_user_id is still what the
 * terminal said. `attendance:personnel-ids` fills it; the sync and the ZKAccess import
 * de-duplicate on it as well, so re-reading a terminal that still holds the punch under
 * the old number does not import it a second time; PunchAttribution re-links from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->string('original_device_user_id')->nullable()
                ->comment('What the terminal recorded, when device_user_id was replaced by the Personnel ID (CPR)');
            $table->index(['device_id', 'original_device_user_id'], 'attendance_original_user');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex('attendance_original_user');
            $table->dropColumn('original_device_user_id');
        });
    }
};
