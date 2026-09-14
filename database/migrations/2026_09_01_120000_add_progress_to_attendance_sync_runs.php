<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live progress for a sync that is still running.
 *
 * The existing counters (records_read, records_imported, …) are the RESULT — they
 * are written once, at the end. Nothing described a run while it was in flight, so
 * an operator syncing a 10,000-record terminal watched a spinner for a minute with
 * no way to tell a slow read from a hung one.
 *
 * These columns are the in-flight view. They are deliberately on the run row rather
 * than in a cache: the row already exists, is already tenant-scoped, is already
 * fetched by the sync-history screens, and survives the request that wrote it.
 *
 * They are written by SyncProgressRecorder, which throttles — a bulk transfer emits
 * hundreds of chunk events and one UPDATE each would cost more than the transfer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_sync_runs', function (Blueprint $table) {
            // reading = pulling the table off the device (the slow, uncertain part)
            // importing = decoding and writing rows (bounded, we know the total)
            $table->string('progress_phase')->nullable()->after('status');

            $table->unsignedInteger('progress_read')->default(0)->after('progress_phase');

            /*
             * NULLABLE, and null means "the device never told us".
             *
             * Not zero. Rule 10: a total we were not given and a total of nothing
             * are different facts, and only one of them justifies drawing a bar.
             * A small table arrives inline with no declared size, and a CSL
             * terminal announces nothing at all — both land here as null so the UI
             * can show an indeterminate state instead of inventing a denominator.
             */
            $table->unsignedInteger('progress_total')->nullable()->after('progress_read');

            /*
             * 'records' or 'bytes'.
             *
             * The transport honestly knows only bytes; the driver converts to
             * records when it can infer the record width, and reports bytes when it
             * cannot. Storing which one it was stops a screen rendering 412,000
             * bytes as "412,000 punches left".
             */
            $table->string('progress_unit')->nullable()->after('progress_total');

            // A retry restarts the transfer from zero. Surfacing the attempt keeps a
            // bar that visibly runs backwards from looking like a bug.
            $table->unsignedSmallInteger('progress_attempt')->default(1)->after('progress_unit');

            // Lets a poller tell "still working" from "the worker died mid-read".
            $table->timestamp('progress_updated_at')->nullable()->after('progress_attempt');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_sync_runs', function (Blueprint $table) {
            $table->dropColumn([
                'progress_phase',
                'progress_read',
                'progress_total',
                'progress_unit',
                'progress_attempt',
                'progress_updated_at',
            ]);
        });
    }
};
