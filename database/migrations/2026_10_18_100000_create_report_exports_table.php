<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report exports built in the background (2026-10-18): "every employee's report for
 * September" is too long for a web request (30 s, 256 MB on this server), so a row
 * records the request and its progress, and BuildEmployeeReports fills it in.
 *
 * `params` holds the range and the employee ids FROZEN at the start, so a person
 * added or edited mid-build does not change what the file covers. `manifest` is a
 * line per person rendered (name, code, pages, headline figures), which the contents
 * page is built from at the end. The file itself is on the local disk, never public.
 *
 * tenant_id is NO ACTION, not CASCADE — see 2026_07_26_080003 for the SQL Server reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('kind', 40);
            $table->text('params');
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->text('manifest')->nullable();
            $table->string('file_path')->nullable();
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('message')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
