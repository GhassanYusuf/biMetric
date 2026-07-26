<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Card number stored on the device for this person (this device's
            // user record has a single card slot, so it's one card or none).
            // Null = enrollment status not yet synced from a device.
            $table->unsignedBigInteger('card_number')->nullable()->after('department');
            // How many fingerprints the person has enrolled on the device.
            // Null = unknown/not yet synced (kept distinct from 0 = synced,
            // none enrolled) so the UI can show "—" rather than a false "0".
            $table->unsignedTinyInteger('fingerprint_count')->nullable()->after('card_number');
            // When card_number/fingerprint_count were last read from a device.
            $table->timestamp('enrollment_synced_at')->nullable()->after('fingerprint_count');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['card_number', 'fingerprint_count', 'enrollment_synced_at']);
        });
    }
};
