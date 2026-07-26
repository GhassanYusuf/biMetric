<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted in-app notifications. Every real-time MQTT delivery is backed by a
 * row here first, so history and unread/read state survive regardless of
 * whether the recipient was connected when it fired (per the standards:
 * "MQTT enhances real-time delivery, it does not replace persistent records").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();   // recipient
            $table->string('type');                 // e.g. attendance.punch, device.offline
            $table->string('level')->default('info'); // info | success | warning | urgent
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('action_url')->nullable();
            $table->json('data')->nullable();       // structured payload for the UI
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
