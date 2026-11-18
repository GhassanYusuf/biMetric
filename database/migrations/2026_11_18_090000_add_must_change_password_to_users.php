<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Must change password at next sign-in" (owner, 2026-11-18: "give option when creating a user to be
 * forced to change at first login"). While true, the user can sign in but can do nothing except choose
 * their own password (RequirePasswordChange middleware); changing it clears the flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
