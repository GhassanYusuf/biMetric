<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company a terminal belongs to (owner, 2026-11-11: "put the devices under a company name
 * dropdown, so GEMS will have multiple devices; when I click GEMS I see its devices").
 *
 * Nullable: a terminal in no company (a shared door panel, a test unit) stays under "No company"
 * on the Devices page. One company per terminal, the one that owns it; who punches on it is still
 * per employee. Not device groups (device_groups): those are sets of terminals for sending one
 * command to several, and a terminal can be in many.
 *
 * No cascade: SQL Server refuses a second cascade path to the same row (devices → tenants and
 * companies → tenants), and a company is soft-deleted anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->constrained('companies')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
