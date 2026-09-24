<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access levels and multi-card opening groups, as the ZKAccess Personnel dialog
 * presents them.
 *
 * DATABASE ONLY (owner's instruction, 2026-09-23). Nothing here is written to a
 * terminal or a C3 panel. On the panels an access level is door x time-zone
 * authorisation (`userauthorize`), and no write to that table has been confirmed
 * on this estate's hardware — so these rows record intent, not what any door will
 * do. `time_zone` is therefore a free label ("24-Hour Accessible"), not a device
 * time-zone index: inventing the index would claim more than the panel told us.
 *
 * tenant_id is NO ACTION, not CASCADE, for the SQL Server reason recorded in
 * 2026_07_26_080003_add_tenant_id_to_domain_tables.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained();
            $table->string('name');
            $table->string('time_zone')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('employee_access_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('access_level_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'access_level_id']);
        });

        Schema::create('multi_card_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained();
            $table->string('name');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_access_levels');
        Schema::dropIfExists('access_levels');
        Schema::dropIfExists('multi_card_groups');
    }
};
