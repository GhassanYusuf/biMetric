<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the person behind a website sign-in last DID something (owner, 2026-11-11: sign-ins end
 * after 15 minutes idle and 8 hours at most). last_used_at cannot say it: an open tab polls the
 * server every few seconds for live updates, which would keep a sign-in alive for ever. Requests
 * the page marks as background (X-Background: 1) do not move this. App\Support\WebSession.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->timestamp('last_active_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('last_active_at');
        });
    }
};
