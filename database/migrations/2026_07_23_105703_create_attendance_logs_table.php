<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('device_user_id')->comment('Raw user id as enrolled on the device');
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('punched_at');
            $table->unsignedTinyInteger('status')->default(0);
            $table->unsignedTinyInteger('verify_method')->default(0)->comment('0 password, 1 fingerprint, 2 card, 15 face, ...');
            $table->timestamps();

            $table->unique(['device_id', 'device_user_id', 'punched_at', 'verify_method'], 'attendance_unique_punch');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
