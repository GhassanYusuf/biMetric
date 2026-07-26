<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();      // used in URLs / MQTT topic scoping
            $table->string('timezone')->default('Asia/Bahrain');
            $table->string('logo_path')->nullable();
            $table->string('primary_color')->nullable();   // light branding
            $table->boolean('is_active')->default(true);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
