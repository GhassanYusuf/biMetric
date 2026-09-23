<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device grouping, so an operation can target "all Gate terminals" instead of
 * being repeated per device.
 *
 * A pivot table rather than a group_id column on devices: a terminal genuinely can
 * belong to more than one grouping (by site AND by function), and a nullable
 * foreign key would force a false choice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_groups', function (Blueprint $table) {
            $table->id();
            // tenant_id is NO ACTION, not CASCADE: SQL Server rejects two cascade paths
            // into one table, and tenants reach most of these both directly and via
            // devices. Tenant soft-deletes, so the cascade never fired. See
            // 2026_07_26_080003_add_tenant_id_to_domain_tables.php.
            $table->foreignId('tenant_id')->nullable()->constrained();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();

            // Group names are unique per tenant, not globally — two companies may
            // both have a "Main Gate".
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('device_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['device_group_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_group_members');
        Schema::dropIfExists('device_groups');
    }
};
