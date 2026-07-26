<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sync run history and raw payload preservation.
 *
 * attendance_sync_runs answers "did the 06:00 import work, and what did it bring".
 * Previously a sync returned an integer to whoever triggered it and left no trace.
 *
 * attendance_raw_logs preserves the bytes BEFORE parsing. This matters more than it
 * looks: if a decoder bug is found later, an archived payload can be reparsed — a
 * device whose log has since rolled over cannot be re-read. The template decoder in
 * this project was wrong for a while and nobody could tell, which is exactly the
 * situation this prevents repeating.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_command_id')->nullable()->constrained()->nullOnDelete();

            // full = whole table imported; incremental = filtered against the watermark
            $table->string('mode')->default('incremental');
            $table->string('status')->default('running');   // running|succeeded|failed|partial

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            // What the device offered vs what we kept. The gap is the dedupe working.
            $table->unsignedInteger('records_read')->default(0);
            $table->unsignedInteger('records_imported')->default(0);
            $table->unsignedInteger('records_duplicate')->default(0);
            // Records whose timestamp would not decode — never silently dropped.
            $table->unsignedInteger('records_undecodable')->default(0);
            $table->unsignedInteger('records_unmatched')->default(0); // no employee mapping

            $table->timestamp('watermark_before')->nullable();
            $table->timestamp('watermark_after')->nullable();

            $table->text('message')->nullable();
            $table->json('error')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'device_id', 'started_at']);
        });

        Schema::create('attendance_raw_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_sync_run_id')->nullable()->constrained()->cascadeOnDelete();

            /**
             * Hex-encoded raw bytes as they came off the device.
             *
             * Stored per-record rather than one blob per run so a single undecodable
             * punch can be found and reparsed without rehydrating a 22KB table.
             */
            $table->text('raw');
            // Parsed values when decoding succeeded; null when it did not.
            $table->string('device_user_id')->nullable();
            $table->timestamp('punched_at')->nullable();
            $table->unsignedTinyInteger('verify_method')->nullable();
            $table->unsignedTinyInteger('status')->nullable();

            $table->boolean('decoded')->default(true);
            $table->boolean('imported')->default(false);
            $table->string('skip_reason')->nullable();  // duplicate|undecodable|below_watermark|unmatched

            $table->timestamps();

            $table->index(['tenant_id', 'device_id', 'punched_at']);
            $table->index(['attendance_sync_run_id', 'decoded']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_raw_logs');
        Schema::dropIfExists('attendance_sync_runs');
    }
};
