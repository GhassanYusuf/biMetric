<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable: platform super-admins belong to no single tenant.
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->boolean('is_super_admin')->default(false)->after('tenant_id');
            $table->foreignId('employee_id')->nullable()->after('is_super_admin');
            $table->boolean('is_active')->default(true)->after('employee_id');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn(['tenant_id', 'is_super_admin', 'employee_id', 'is_active']);
        });
    }
};
