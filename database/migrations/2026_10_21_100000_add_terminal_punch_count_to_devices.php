<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many punches the TERMINAL holds, as it last said (owner, 2026-10-21: show "device
 * punches and system punches" as two columns). The devices list showed only the
 * system's stored count for the terminal, which stays the same after a clear or a wipe
 * and read as "the wipe did not work".
 *
 * Null = never counted, kept distinct from 0 (rule 10). Filled by the 5-minute health
 * check (GET_FREE_SIZES on the ZKTeco family), by every successful punch read (these
 * terminals always send their whole log), and by a clear or a wipe (0, read back).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedInteger('terminal_punch_count')->nullable();
            $table->timestamp('terminal_punch_counted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['terminal_punch_count', 'terminal_punch_counted_at']);
        });
    }
};
