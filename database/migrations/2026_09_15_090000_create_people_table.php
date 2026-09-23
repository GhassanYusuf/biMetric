<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The HUMAN, as distinct from their employment and from their PIN on a terminal.
 *
 * WHY: an employees row describes a person's job at one company. It is the wrong
 * thing to key attendance on when the same human works across sister companies —
 * they have two employments, two employee_codes and a different PIN on every
 * terminal, but they are one person and payroll must see one attendance account.
 *
 * The join key is the NATIONAL ID, because that is what the downstream HRMS uses to
 * link attendance to payroll. It is therefore the one identifier we do not get to
 * choose, and the one that must be right.
 *
 * IT IS ALSO GOVERNMENT ID, so it is encrypted at rest. Lookups go through
 * national_id_hash (a deterministic HMAC) rather than the ciphertext, which cannot be
 * searched — see App\Models\Person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();

            // Deliberately NOT tenant-scoped. A person spanning sister companies is the
            // entire reason this table exists; scoping it would recreate the duplicate
            // it is meant to collapse. Their EMPLOYMENTS are tenant-scoped.
            $table->text('national_id')->nullable();          // encrypted
            $table->string('national_id_hash')->nullable()->unique(); // blind index
            $table->string('hrms_person_id')->nullable()->index();
            $table->string('full_name');

            /*
             * verified       — national id present and accepted; exportable to HRMS
             * unverified     — real human, id not supplied yet; attendance still
             *                  collects, export is blocked rather than guessed at
             */
            $table->string('status')->default('unverified');

            $table->timestamps();
        });

        /*
         * A national id can be REISSUED — an iqama or permit renewal changes the
         * number for the same human. Payroll exports produced under the old number
         * must still reconcile, so superseded ones are kept rather than overwritten.
         */
        Schema::create('person_national_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained()->cascadeOnDelete();
            $table->text('national_id');                       // encrypted
            $table->string('national_id_hash')->index();       // blind index
            $table->timestamp('superseded_at')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_national_ids');
        Schema::dropIfExists('people');
    }
};
