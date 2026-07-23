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
        Schema::table('devices', function (Blueprint $table) {
            $table->string('protocol')->default('binary')->after('port')
                ->comment('binary = classic ZK TCP protocol on port 4370; csl_http = CSL/Secutime-style web export');
            $table->string('web_username')->nullable()->after('protocol');
            $table->string('web_password')->nullable()->after('web_username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['protocol', 'web_username', 'web_password']);
        });
    }
};
