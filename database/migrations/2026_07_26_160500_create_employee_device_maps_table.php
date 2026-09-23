<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit mapping between an employee and their identity ON a specific device.
 *
 * WHY: matching was done by comparing employees.employee_code to the device's user
 * id string. That works until somebody renumbers an employee, two devices assign
 * different PINs to the same person, or a code is reused after someone leaves —
 * then punches silently attach to the wrong human, which is a payroll error nobody
 * would notice.
 *
 * device_uid is the device-INTERNAL slot (not portable between devices);
 * device_user_id is the PIN attendance records actually carry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_device_maps', function (Blueprint $table) {
            $table->id();
            // tenant_id is NO ACTION, not CASCADE: SQL Server rejects two cascade paths
            // into one table, and tenants reach most of these both directly and via
            // devices. Tenant soft-deletes, so the cascade never fired. See
            // 2026_07_26_080003_add_tenant_id_to_domain_tables.php.
            $table->foreignId('tenant_id')->nullable()->constrained();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('device_uid')->nullable();
            $table->string('device_user_id');

            // What the device holds for them, as of the last read.
            $table->string('device_name')->nullable();
            $table->unsignedTinyInteger('device_privilege')->default(0);
            $table->unsignedBigInteger('card_number')->nullable();
            $table->json('fingerprint_slots')->nullable();
            $table->timestamp('synced_at')->nullable();

            // in_sync | missing_on_device | missing_in_system | mismatched
            $table->string('reconciliation_status')->default('in_sync');
            $table->json('reconciliation_detail')->nullable();

            $table->timestamps();

            // One mapping per employee per device, and one device PIN per device.
            $table->unique(['device_id', 'employee_id']);
            $table->unique(['device_id', 'device_user_id']);
            $table->index(['tenant_id', 'reconciliation_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_device_maps');
    }
};
