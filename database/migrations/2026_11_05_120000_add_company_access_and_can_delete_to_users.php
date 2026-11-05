<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner, 2026-11-05: "in permission I need to hide some companies for someone; add also a
 * delete permission given to someone and someone not".
 *
 * - users.can_delete: a per-user switch on top of the permission level. Off = this user can
 *   never delete employees, terminals or users, whatever the level says (User::hasPermissionTo).
 *   Defaults to true, so every existing user keeps exactly what they have.
 * - user_companies: the companies a user may see. NO rows = every company (as before), so
 *   nobody is limited until an administrator ticks companies for them. A limited user sees
 *   only those companies' employees, departments and punches; employees in no company and
 *   punches linked to nobody are hidden from them (owner's choice, 2026-11-05). CompanyAccess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_delete')->default(true);
        });

        Schema::create('user_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // No cascade from companies: SQL Server refuses a second cascade path to the
            // same row (users → tenants → companies). A company is soft-deleted anyway.
            $table->foreignId('company_id')->constrained()->noActionOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_companies');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_delete');
        });
    }
};
