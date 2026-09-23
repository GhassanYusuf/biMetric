<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A device is READ-ONLY until somebody has looked at who is already on it.
 *
 * WHY: adding a terminal used to make it immediately writable. The first push then
 * overwrote whoever already held the target PINs, destroying fingerprint templates
 * that only exist on that hardware. The failure is not recoverable from a backup —
 * the employee has to be re-enrolled in person.
 *
 * Adoption is therefore a gate, not a status field: writes are refused until the
 * roster has been read and every entry claimed or ignored.
 *
 *   unadopted  — just added. Nothing has been read. No writes, no attendance.
 *   read_only  — roster read; attendance syncs; entries awaiting a human. No writes.
 *   adopted    — every roster entry decided. Writes permitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('adoption_status')->default('unadopted')->after('is_active');
            $table->timestamp('roster_read_at')->nullable()->after('adoption_status');
            $table->timestamp('adopted_at')->nullable()->after('roster_read_at');
            $table->foreignId('adopted_by')->nullable()->after('adopted_at')->constrained('users')->nullOnDelete();

            $table->index(['tenant_id', 'adoption_status']);
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'adoption_status']);
            $table->dropConstrainedForeignId('adopted_by');
            $table->dropColumn(['adoption_status', 'roster_read_at', 'adopted_at']);
        });
    }
};
