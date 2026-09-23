<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Heartbeat history per device.
 *
 * devices.health_status holds the CURRENT verdict; this holds how it got there.
 * Without the history, "the gate terminal keeps dropping" is unanswerable — you
 * only ever see the latest state.
 *
 * Only TRANSITIONS are written, not every poll. A device polled every minute for a
 * month would otherwise produce 43k rows saying "still online".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_status_logs', function (Blueprint $table) {
            $table->id();
            // tenant_id is NO ACTION, not CASCADE: SQL Server rejects two cascade paths
            // into one table, and tenants reach most of these both directly and via
            // devices. Tenant soft-deletes, so the cascade never fired. See
            // 2026_07_26_080003_add_tenant_id_to_domain_tables.php.
            $table->foreignId('tenant_id')->nullable()->constrained();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();

            $table->string('status');                       // online|offline|degraded
            $table->string('previous_status')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->text('reason')->nullable();             // why it changed
            $table->timestamp('observed_at');

            $table->timestamps();

            $table->index(['tenant_id', 'device_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_status_logs');
    }
};
