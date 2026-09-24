<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fills in the template storage biometric_templates was built to hold (owner's
 * decision, 2026-09-24: keep a copy of every fingerprint "in case we need to change the
 * id or bring in a new device"). See FingerprintVault and docs/PROJECT-STATE.md.
 *
 * `template` was `binary` (varbinary(max) on SQL Server) and NULL on every row. The
 * copy is stored ENCRYPTED, and ciphertext is text: SQL Server refuses to write text
 * into varbinary implicitly, and SQLite in the tests would never show it. With every
 * row NULL, dropping and re-adding the column loses nothing.
 *
 * `template_sha1` is the plaintext digest, so a re-read can tell "same enrollment"
 * from "re-enrolled" without decrypting anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biometric_templates', function (Blueprint $table) {
            $table->dropColumn('template');
        });

        Schema::table('biometric_templates', function (Blueprint $table) {
            $table->longText('template')->nullable();
            $table->char('template_sha1', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('biometric_templates', function (Blueprint $table) {
            $table->dropColumn(['template', 'template_sha1']);
        });

        Schema::table('biometric_templates', function (Blueprint $table) {
            $table->binary('template')->nullable();
        });
    }
};
