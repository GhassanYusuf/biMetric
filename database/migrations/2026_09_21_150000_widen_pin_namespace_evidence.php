<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes device_groups.pin_namespace_evidence a text column instead of a 255-char one.
 *
 * WHY IT SURFACED NOW. The column was declared with $table->string(), i.e. 255
 * characters, but it holds prose: the evidence behind a flag that lets PINs be
 * treated as one namespace across a set of terminals. The Promoseven Holdings entry
 * is 268 characters. SQLite never noticed -- it does not enforce a VARCHAR length and
 * stored the whole string -- so the mismatch sat there invisibly until the move to
 * SQL Server on 2026-09-21, where the same insert failed with "String or binary data
 * would be truncated".
 *
 * WHY text, NOT A BIGGER string(). The field exists so a powerful flag is never a
 * bare boolean somebody turned on for a reason nobody recorded (see
 * 2026_09_15_130000_add_shared_pin_namespace_to_device_groups.php). Evidence is
 * written for a human to read and gets longer as more cross-checks are added; any
 * specific limit is a future truncation of exactly the text that justifies the claim.
 *
 * Truncation here would be silent damage rather than a visible error: the flag would
 * stay on and the sentence explaining why would lose its end.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_groups', function (Blueprint $table) {
            $table->text('pin_namespace_evidence')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('device_groups', function (Blueprint $table) {
            $table->string('pin_namespace_evidence')->nullable()->change();
        });
    }
};
