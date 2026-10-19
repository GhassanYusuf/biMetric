<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound webhooks for the integration API (2026-10-08).
 *
 * An endpoint is a URL the CUSTOMER'S software exposes; we POST signed events to it.
 * A delivery row is the durable record of one event to one endpoint — payload kept so a
 * failed one can be redelivered byte-for-byte, and so "did you ever send me X?" has an
 * answer.
 *
 * `punch_cursor` is the id of the last attendance_logs row handed to this endpoint.
 * Punches are NOT emitted from the sync code (bulk inserts raise no model events and
 * that path is the calibrated one, rule 15); a relay reads attendance_logs past each
 * endpoint's cursor instead, which also makes delivery at-least-once and gap-free.
 *
 * tenant_id is NO ACTION, not CASCADE — see 2026_07_26_080003 for the SQL Server reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained();
            $table->string('url', 2048);
            $table->string('description')->nullable();
            // Encrypted by the model cast; signs every delivery.
            $table->text('secret');
            // JSON list of event names, or ["*"].
            $table->text('events');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('punch_cursor')->default(0);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            // Set when the endpoint was switched off for failing too long.
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained();
            $table->unsignedBigInteger('webhook_endpoint_id');
            $table->foreign('webhook_endpoint_id')->references('id')->on('webhook_endpoints')->cascadeOnDelete();
            $table->string('event_id', 36);
            $table->string('event', 64);
            $table->text('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['webhook_endpoint_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
