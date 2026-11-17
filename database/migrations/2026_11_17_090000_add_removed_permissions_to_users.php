<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permissions taken away from one user although their level holds them (owner, 2026-11-17: "I can
 * add or remove any permission to everyone, make it flexible"). A JSON list of permission names;
 * null = none removed. Permissions GIVEN to one user on top of their level need no column: they are
 * spatie's direct permissions (model_has_permissions). User::hasPermissionTo reads both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('removed_permissions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('removed_permissions');
        });
    }
};
