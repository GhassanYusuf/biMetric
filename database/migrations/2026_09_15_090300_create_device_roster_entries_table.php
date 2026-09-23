<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everybody the DEVICE thinks it has, before anyone decides who they are.
 *
 * WHY A SEPARATE TABLE: the old code read a terminal's roster and immediately either
 * matched a row to an employee by string-comparing employee_code, or created an
 * employee from it. Both are guesses. On a terminal that has been running for years
 * the roster contains leavers nobody deleted, the installer's test user, contractors,
 * and the same human enrolled twice — and on a SECOND terminal it contains unrelated
 * people who happen to hold the same numbers.
 *
 * So the roster lands here as raw evidence, and stays here until a human claims each
 * entry. employee_device_maps holds only DECIDED identities; this holds undecided
 * ones. Keeping them in one table would mean a nullable employee_id, and every query
 * downstream would have to remember which kind of row it was looking at.
 *
 * Entries are also what stops nextFreePin() from allocating over a real person: an
 * unclaimed occupant still holds their PIN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_roster_entries', function (Blueprint $table) {
            $table->id();
            // tenant_id is NO ACTION, not CASCADE: SQL Server rejects two cascade paths
            // into one table, and tenants reach most of these both directly and via
            // devices. Tenant soft-deletes, so the cascade never fired. See
            // 2026_07_26_080003_add_tenant_id_to_domain_tables.php.
            $table->foreignId('tenant_id')->nullable()->constrained();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();

            // Exactly what the device said, unparsed and unmatched.
            $table->unsignedInteger('device_uid')->nullable();
            $table->string('device_user_id');
            $table->string('device_name')->nullable();
            $table->unsignedTinyInteger('device_privilege')->default(0);
            $table->unsignedBigInteger('card_number')->nullable();
            // null = the template table would not decode. NEVER render as "none" (rule 10).
            $table->json('fingerprint_slots')->nullable();

            /*
             * unclaimed — read off the device, nobody has decided who this is
             * claimed   — an operator bound it to an employee; a live mapping exists
             * ignored   — an operator declared it not a person we track (test users,
             *             the installer's account). Kept, not deleted, so the PIN
             *             stays reserved and the next read does not re-surface it.
             */
            $table->string('claim_status')->default('unclaimed');
            $table->foreignId('claimed_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->string('ignore_reason')->nullable();

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'device_user_id']);
            $table->index(['tenant_id', 'claim_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_roster_entries');
    }
};
