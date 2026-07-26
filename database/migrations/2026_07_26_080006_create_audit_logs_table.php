<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable audit trail of security- and compliance-relevant actions
 * (who did what, to which record, from where). Written by services, never
 * edited or deleted through the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // actor
            $table->string('action');                       // e.g. employee.updated
            $table->nullableMorphs('auditable');            // subject record
            $table->json('changes')->nullable();            // before/after diff
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
