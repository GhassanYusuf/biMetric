<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            /*
             * WHICH finger slots hold a template on the device, e.g. [1, 6].
             * The device's template table carries a finger index per record, so
             * the roster read already knows this — storing it lets the employee
             * list draw each person's enrolled fingers without opening a
             * connection per row.
             *
             * Null = unknown (never read, or the firmware's template table could
             * not be decoded), kept distinct from [] = read successfully, none
             * enrolled. fingerprint_count stays as the denormalised count.
             */
            $table->json('fingerprint_slots')->nullable()->after('fingerprint_count');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('fingerprint_slots');
        });
    }
};
