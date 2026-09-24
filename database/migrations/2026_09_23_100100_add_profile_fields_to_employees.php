<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The remaining ZKAccess Personnel fields (Profile, Details, Access levels tabs).
 *
 * ADDITIVE ONLY: every column is nullable, nothing is renamed, split or dropped.
 * `name` stays the full display name it has always been; `last_name` is a new,
 * separate column and is not derived from it.
 *
 * No defaults on purpose. `privilege` in particular stays NULL on existing rows
 * rather than defaulting to "user": we have not read anyone's privilege off a
 * terminal, and a default would state as fact something nobody observed (rule 10).
 * NULL means "not set in this app".
 *
 * `device_password` and `privilege` are STORED ONLY. Nothing sends them to a
 * terminal until the owner approves that write separately (2026-09-23). The
 * password is kept under the `encrypted` cast because, unlike a login password, it
 * has to be recoverable to be pushed one day — so it cannot be hashed.
 *
 * `access_valid_from` / `access_valid_to`, NOT `valid_from` / `valid_to`:
 * employee_device_maps already has those two names, and existing queries that
 * join the two tables filter on an unqualified `valid_to` (DuplicateEmployeeFinder
 * among them). Reusing the names made those queries ambiguous — caught by
 * EmployeeMergeTest on 2026-09-23 — and renaming the new columns keeps the working
 * code untouched.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'last_name', 'gender', 'mobile_phone', 'hired_date', 'birthday',
        'device_password', 'privilege',
        'job_title', 'office', 'education', 'country', 'city', 'postal_code',
        'home_phone', 'political_status', 'race', 'home_address', 'work_address',
        'multi_card_group_id', 'valid_period_enabled', 'access_valid_from', 'access_valid_to',
    ];

    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Profile
            $table->string('last_name')->nullable();
            $table->string('gender', 16)->nullable();
            $table->string('mobile_phone', 32)->nullable();
            $table->date('hired_date')->nullable();
            $table->date('birthday')->nullable();
            $table->text('device_password')->nullable(); // ciphertext is far longer than 8 digits
            $table->string('privilege', 16)->nullable();

            // Details
            $table->string('job_title')->nullable();
            $table->string('office')->nullable();
            $table->string('education')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('home_phone', 32)->nullable();
            $table->string('political_status')->nullable();
            $table->string('race')->nullable();
            $table->string('home_address', 500)->nullable();
            $table->string('work_address', 500)->nullable();

            // Access levels tab
            $table->foreignId('multi_card_group_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('valid_period_enabled')->nullable();
            $table->date('access_valid_from')->nullable();
            $table->date('access_valid_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('multi_card_group_id');
            $table->dropColumn(array_values(array_diff(self::COLUMNS, ['multi_card_group_id'])));
        });
    }
};
