<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant key/value settings (shift hours, grace minutes, work days,
 * timezone overrides, branding, etc). One row per (tenant, key); values are
 * JSON so a setting can be a scalar, list, or object.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
