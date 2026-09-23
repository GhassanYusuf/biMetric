<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sync run bounded to a window of dates.
 *
 * WHAT THIS IS NOT: a device-side filter. The protocol has no "punches since X"
 * (see AttendanceSyncService) — ATTLOG_RRQ always hands over the whole table — so
 * the window bounds what is CONSIDERED, not what is transferred. These columns
 * exist so a finished run says which window it was asked for; without them a range
 * read and a full re-read are indistinguishable in the history, and "why did this
 * import only 640 of 9,579?" has no answer on the row.
 *
 * `records_out_of_range` follows the same discipline as records_undecodable: a
 * record we deliberately did not import is COUNTED, never silently dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_sync_runs', function (Blueprint $table) {
            /*
             * Dates, not timestamps, and in the DEVICE's local day.
             *
             * An operator asking for "1–31 August" means the terminal's own
             * calendar days, which is what its punches are stamped in. The service
             * widens each date to a device-local start/end of day before comparing;
             * storing the instants instead would make the row unreadable to the
             * person who typed two dates.
             */
            $table->date('range_from')->nullable()->after('watermark_after');
            $table->date('range_to')->nullable()->after('range_from');

            // Read off the device, outside the requested window, therefore not
            // imported. Zero on every non-ranged run.
            $table->unsignedInteger('records_out_of_range')->default(0)->after('records_unmatched');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_sync_runs', function (Blueprint $table) {
            $table->dropColumn(['range_from', 'range_to', 'records_out_of_range']);
        });
    }
};
