<?php

namespace App\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clears everything derived from the terminals, so the estate can be re-adopted.
 *
 * WHY THIS EXISTS: the data in this database was built by the identity model that
 * failed on 2026-09-15. Employees were created by string-matching device PINs against
 * payroll codes, mappings were inferred rather than decided, and attendance was
 * attributed through those inferences. None of it can be trusted, and repairing it
 * row by row would mean guessing again at exactly the questions that were guessed at
 * the first time.
 *
 * WHAT IT KEEPS: tenants, users, departments, devices and their credentials. A device
 * is reset to `unadopted`, not deleted — its address, protocol and probed capabilities
 * are hard-won facts about the hardware and are unaffected by the identity bug.
 *
 * WHAT IT DESTROYS AND CANNOT RESTORE: attendance. A ZKTeco terminal holds a bounded
 * log buffer and rolls old records off. Whatever has already rolled off exists only
 * here. Take a copy of the database file first — the command refuses to run without
 * an explicit acknowledgement for that reason.
 */
class ResetDeviceIdentity extends Command
{
    protected $signature = 'identity:reset
                            {--force : Skip the interactive confirmation}
                            {--keep-attendance : Keep punches; clear only identity and mappings}
                            {--employees-only : Clear the staff list and every claim, but KEEP rosters and punches}';

    protected $description = 'Wipe device-derived identity (and optionally attendance) so devices can be re-adopted';

    /**
     * Ordered child-first so foreign keys never block a delete.
     *
     * Identity tables only. Attendance is handled separately because it is the one
     * part that cannot be re-read from the hardware.
     */
    private const IDENTITY_TABLES = [
        'biometric_templates',
        'access_cards',
        'enrollment_sessions',
        'employee_device_maps',
        'device_roster_entries',
        'attendance_waivers',
        'employees',
        'person_national_ids',
        'people',
    ];

    private const ATTENDANCE_TABLES = [
        'attendance_logs',
        'attendance_raw_logs',
        'attendance_sync_runs',
    ];

    /**
     * Tables cleared by --employees-only.
     *
     * Deliberately NOT device_roster_entries: what the hardware holds is evidence, and
     * re-reading six terminals to recover it costs minutes and proves nothing new. The
     * CLAIMS on those entries are undone instead, because a claim points at an employee
     * that is about to stop existing.
     */
    private const EMPLOYEE_TABLES = [
        'biometric_templates',
        'access_cards',
        'enrollment_sessions',
        'employee_device_maps',
        'attendance_waivers',
        'employees',
        'person_national_ids',
        'people',
    ];

    /**
     * Clears the staff list so it can be re-imported from HR, keeping everything the
     * hardware told us.
     *
     * WHAT SURVIVES AND WHY:
     *
     *   - **Roster entries.** They are what the terminals actually hold. Re-reading six
     *     devices to recover them costs minutes and yields the same bytes.
     *   - **`ignored` decisions.** "This is the installer's test account" is a statement
     *     about the DEVICE, not about the staff list, so it stays true across a
     *     re-import — and it keeps that PIN reserved so nobody is allocated over it.
     *   - **Attendance.** `attendance_logs.employee_id` is SET NULL, so punches survive
     *     unattributed. They carry their raw device_user_id, so `attendance:reattribute`
     *     re-attaches them once the new staff are claimed. Deleting them would throw
     *     away history the terminals may since have rolled off.
     *
     * WHAT IS UNDONE: every `claimed` entry goes back to `unclaimed`, because its
     * employee is about to stop existing. Devices that were adopted on the strength of
     * those claims drop back to `read_only` — an adopted device with undecided entries
     * is the exact state that allowed the original overwrite, so it is not a state this
     * system may sit in.
     */
    private function resetEmployeesOnly(): int
    {
        $counts = [];
        foreach (self::EMPLOYEE_TABLES as $table) {
            $counts[$table] = Schema::hasTable($table) ? DB::table($table)->count() : null;
        }

        $claimed = DB::table('device_roster_entries')->where('claim_status', 'claimed')->count();
        $ignored = DB::table('device_roster_entries')->where('claim_status', 'ignored')->count();
        $adopted = DB::table('devices')->where('adoption_status', Device::ADOPTED)->count();
        $punches = Schema::hasTable('attendance_logs') ? DB::table('attendance_logs')->count() : 0;

        $this->newLine();
        $this->line('About to delete:');
        foreach ($counts as $table => $count) {
            $this->line($count === null
                ? sprintf('  %-24s (table not present, skipped)', $table)
                : sprintf('  %-24s %s row(s)', $table, number_format($count)));
        }

        $this->newLine();
        $this->line('And to undo:');
        $this->line(sprintf('  %-24s %s claim(s) released back to unclaimed', 'device_roster_entries', number_format($claimed)));
        $this->line(sprintf('  %-24s %s device(s) dropped back to read-only', 'devices', number_format($adopted)));

        $this->newLine();
        $this->line('Kept:');
        $this->line(sprintf('  %-24s %s punch(es), now unattributed — re-attach with attendance:reattribute', 'attendance_logs', number_format($punches)));
        $this->line(sprintf('  %-24s %s ignored entr%s (still true, and the PINs stay reserved)', 'device_roster_entries', number_format($ignored), $ignored === 1 ? 'y' : 'ies'));
        $this->line('  devices, tenants, users, departments, rosters, raw logs, sync history');
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Proceed?', false)) {
            $this->line('Nothing was deleted.');

            return self::FAILURE;
        }

        DB::transaction(function () {
            foreach (self::EMPLOYEE_TABLES as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            // claimed_employee_id is already SET NULL by the foreign key; the STATUS is
            // not, and an entry reading "claimed" with nobody attached would count as
            // decided and let a device be adopted with unknown occupants.
            DB::table('device_roster_entries')
                ->where('claim_status', 'claimed')
                ->update([
                    'claim_status' => 'unclaimed',
                    'claimed_employee_id' => null,
                    'claimed_by' => null,
                    'claimed_at' => null,
                ]);

            DB::table('devices')
                ->where('adoption_status', Device::ADOPTED)
                ->update([
                    'adoption_status' => Device::READ_ONLY,
                    'adopted_at' => null,
                    'adopted_by' => null,
                ]);
        });

        $pending = DB::table('device_roster_entries')->where('claim_status', 'unclaimed')->count();

        $this->newLine();
        $this->info('Done. The staff list is empty; every terminal is read-only.');
        $this->newLine();
        $this->line("{$pending} roster entr".($pending === 1 ? 'y is' : 'ies are').' waiting to be claimed.');
        $this->newLine();
        $this->line('Next:');
        $this->line('  1. import your staff (Employees screen, or however HR supplies them)');
        $this->line('  2. php artisan device:suggest-claims {device}        review proposals');
        $this->line('  3. php artisan device:suggest-claims {device} --apply');
        $this->line('  4. php artisan device:adopt {device}                 once nothing is undecided');
        $this->line('  5. php artisan attendance:reattribute                re-attach the kept punches');
        $this->newLine();

        return self::SUCCESS;
    }

    public function handle(): int
    {
        if ($this->option('employees-only')) {
            return $this->resetEmployeesOnly();
        }

        $keepAttendance = (bool) $this->option('keep-attendance');

        $tables = self::IDENTITY_TABLES;
        if (! $keepAttendance) {
            $tables = array_merge(self::ATTENDANCE_TABLES, $tables);
        }

        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = Schema::hasTable($table) ? DB::table($table)->count() : null;
        }

        $this->newLine();
        $this->line('About to delete:');
        foreach ($counts as $table => $count) {
            $this->line($count === null
                ? sprintf('  %-24s (table not present, skipped)', $table)
                : sprintf('  %-24s %s row(s)', $table, number_format($count)));
        }

        if (! $keepAttendance) {
            $this->newLine();
            $this->warn('Attendance is included. Terminals roll old punches off a bounded buffer, so');
            $this->warn('anything already rolled off will not come back on a re-read. It is gone.');
        }

        $this->newLine();
        $this->line('Kept: tenants, users, departments, devices (reset to unadopted), holidays, settings.');
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Proceed?', false)) {
            $this->line('Nothing was deleted.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($tables) {
            /*
             * Deleting rather than truncating: truncate does not fire foreign keys on
             * every driver and cannot be rolled back if a later step fails. These are
             * hundreds of thousands of rows at most, so the cost is irrelevant next to
             * doing it atomically.
             */
            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            /*
             * Every device goes back to unadopted. Its roster is now unknown to us, and
             * an unknown roster must not be writable — that is the precondition that
             * failed and let the overwrite happen.
             */
            DB::table('devices')->update([
                'adoption_status' => Device::UNADOPTED,
                'roster_read_at' => null,
                'adopted_at' => null,
                'adopted_by' => null,
                'attendance_watermark' => null,
                'attendance_imported_total' => 0,
            ]);
        });

        $this->newLine();
        $this->info('Done. Every device is unadopted and read-only.');
        $this->newLine();
        $this->line('Next, per device:');
        $this->line('  1. php artisan device:roster {device}        read who is already on it');
        $this->line('  2. review and claim or ignore each entry     (or device:import-roster to bulk-create)');
        $this->line('  3. php artisan device:adopt {device}         unlock writes');
        $this->newLine();

        return self::SUCCESS;
    }
}
