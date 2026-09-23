<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enrollment sessions, cards and template metadata.
 *
 * ENROLLMENT SESSIONS: capture was synchronous and unrecorded — a 30-second
 * fingerprint blocked a request, and closing the browser lost all knowledge that it
 * had ever been attempted. A session row makes the state machine durable:
 *
 *   pending -> sent -> waiting -> success | timeout | failed | cancelled
 *
 * ACCESS_CARDS: a ZKTeco user record holds exactly ONE card field, so a person
 * cannot have two cards ON a device. This table is therefore card HISTORY, not
 * concurrent cards — it's what makes "revoke the lost card, issue a new one"
 * answerable later.
 *
 * BIOMETRIC_TEMPLATES: metadata only for now. Template BYTES cannot be downloaded
 * by this library (blob format unverified — see TemplateService), so this records
 * which slots are filled per device, not the templates themselves. The column
 * exists so adding real template storage later is a fill-in, not a migration of
 * everything that references it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_sessions', function (Blueprint $table) {
            $table->id();
            // tenant_id is NO ACTION, not CASCADE: SQL Server rejects two cascade paths
            // into one table, and tenants reach most of these both directly and via
            // devices. Tenant soft-deletes, so the cascade never fired. See
            // 2026_07_26_080003_add_tenant_id_to_domain_tables.php.
            $table->foreignId('tenant_id')->nullable()->constrained();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            // NO ACTION: devices reach enrollment_sessions directly and again
            // through device_commands, and SQL Server counts SET NULL as a cascade
            // path too. The device_id cascade above is the one that matters.
            $table->foreignId('device_command_id')->nullable()->constrained();

            $table->string('type');                          // fingerprint | card
            $table->unsignedTinyInteger('finger_slot')->nullable();  // fingerprint only

            // pending|sent|waiting|success|timeout|failed|cancelled
            $table->string('status')->default('pending');

            $table->timestamp('requested_at');
            $table->timestamp('sent_at')->nullable();        // trigger reached the device
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('timeout_seconds')->default(30);

            // What came back.
            $table->unsignedBigInteger('captured_card_number')->nullable();
            $table->unsignedSmallInteger('device_error_code')->nullable();

            /**
             * Ordered progress events from the device: beeped, waiting_for_finger,
             * finger_placed_sample_1, sample_score_N, enrolled.
             *
             * This is the audit trail for "the operator says it did not work" — it
             * shows whether the device ever beeped, whether a finger was placed, and
             * what score it scored.
             */
            $table->json('events')->nullable();

            $table->text('message')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'status']);
            $table->index(['tenant_id', 'device_id', 'requested_at']);
        });

        Schema::create('access_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedBigInteger('card_number');

            // active | replaced | revoked | lost
            $table->string('status')->default('active');
            $table->timestamp('assigned_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason')->nullable();

            /**
             * 0xFFFFFFFF (4294967295) is all bits set. OBSERVED on real hardware: one
             * user carried exactly that. On many firmwares it is the "no card" marker
             * rather than a real number, so it is flagged rather than trusted.
             */
            $table->boolean('is_suspect')->default(false);

            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'status']);
            $table->index(['tenant_id', 'card_number']);
        });

        Schema::create('biometric_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('finger_slot');      // 0-9, the device's numbering
            $table->unsignedInteger('template_size')->nullable(); // bytes, as the device reports

            /**
             * Template bytes. NULL for every row today.
             *
             * This library cannot download template blobs — the format and the matching
             * upload command are unverified, so a stored template could not be trusted
             * to work when written back. Enrollment is therefore DEVICE-SIDE.
             *
             * The column exists so that capability, once verified, does not require
             * migrating every table that references a template.
             */
            $table->binary('template')->nullable();
            $table->string('template_format')->nullable();   // vendor/version, once known

            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('verified_at')->nullable();     // last confirmed present on device

            $table->timestamps();

            $table->unique(['device_id', 'employee_id', 'finger_slot']);
            $table->index(['tenant_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_templates');
        Schema::dropIfExists('access_cards');
        Schema::dropIfExists('enrollment_sessions');
    }
};
