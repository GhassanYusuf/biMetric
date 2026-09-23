<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable command queue for device actions.
 *
 * WHY THIS EXISTS: every device operation in this app previously ran inside an
 * HTTP request behind set_time_limit(90). A fingerprint capture blocks for ~30s
 * holding a PHP-FPM worker, a failed sync vanished with the request, and nothing
 * could be retried or audited.
 *
 * A command row is the durable record of intent. A job picks it up, and the row
 * accumulates attempts, the device's own raw response, and a final state — which
 * is what makes the whole path auditable and fault-tolerant.
 *
 * This complements Laravel's `jobs` table rather than replacing it: `jobs` is
 * transport, this is domain history that survives a completed job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_commands', function (Blueprint $table) {
            $table->id();
            // tenant_id is NO ACTION, not CASCADE: SQL Server rejects two cascade paths
            // into one table, and tenants reach most of these both directly and via
            // devices. Tenant soft-deletes, so the cascade never fired. See
            // 2026_07_26_080003_add_tenant_id_to_domain_tables.php.
            $table->foreignId('tenant_id')->nullable()->constrained();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();

            // What to do, e.g. sync_attendance, push_user, enroll_fingerprint.
            $table->string('command');
            // Arguments — employee id, finger slot, and so on.
            $table->json('payload')->nullable();

            // queued -> running -> succeeded | failed | cancelled
            $table->string('status')->default('queued');

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->timestamp('available_at')->nullable();   // retry backoff
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Operator-facing message and the technical detail behind it, kept apart
            // deliberately: one goes on screen, the other goes to whoever debugs it.
            $table->text('message')->nullable();
            $table->json('error')->nullable();

            /**
             * The device's own answer, for troubleshooting.
             *
             * Raw protocol bytes are stored hex-encoded. This is what makes a
             * firmware disagreement diagnosable after the fact instead of requiring
             * the failure to be reproduced live.
             */
            $table->json('raw_response')->nullable();

            // Who asked for it. Null for scheduled/system commands.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'device_id', 'status']);
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_commands');
    }
};
