<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cron jobs a company manages from the browser (owner, 2026-10-20: "a tab that shows the
 * cron jobs and lets users add another and tinker with the time"). Each row is one
 * action from ScheduledJob::ACTIONS — never a command line — on a cron expression in the
 * COMPANY's timezone, for every terminal (`device_ids` null) or the listed ones.
 *
 * Replaces the settings that held the two schedules before: `devices.clock_sync_*` +
 * `devices.daily_read_enabled` (the morning routine) and `attendance.sync_*` (the full
 * read). Their CURRENT values become rows here, so nothing that was running stops and
 * nothing that was off starts; the settings rows are then removed so there is one place
 * a schedule lives.
 *
 * tenant_id is NO ACTION, not CASCADE — see 2026_07_26_080003 for the SQL Server reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained();
            $table->string('name', 100);
            $table->string('action', 40);
            $table->string('schedule', 100);
            $table->text('device_ids')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_result', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_enabled']);
        });

        $keys = [
            'devices.clock_sync_enabled', 'devices.clock_sync_schedule', 'devices.daily_read_enabled',
            'attendance.sync_enabled', 'attendance.sync_schedule',
        ];
        $now = now();

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $stored = DB::table('settings')->where('tenant_id', $tenantId)->whereIn('key', $keys)->pluck('value', 'key')
                ->map(fn ($v) => json_decode($v, true));
            // The defaults TenantSettings applied to a key never saved.
            $get = fn (string $key, mixed $default) => $stored->has($key) ? $stored[$key] : $default;

            $clocks = (bool) $get('devices.clock_sync_enabled', true);
            $reads = (bool) $get('devices.daily_read_enabled', true);
            $rows = [];

            if ($clocks || $reads) {
                [$action, $name] = match (true) {
                    $clocks && $reads => ['clock_and_read', 'Set clocks, then read the past day'],
                    $clocks => ['set_clocks', 'Set clocks'],
                    default => ['read_past_day', 'Read the past day'],
                };
                $rows[] = ['name' => $name, 'action' => $action, 'schedule' => (string) $get('devices.clock_sync_schedule', '0 5 * * *')];
            }

            if ($get('attendance.sync_enabled', false)) {
                $rows[] = ['name' => 'Read all new punches', 'action' => 'read_all_new', 'schedule' => (string) $get('attendance.sync_schedule', '0 3 * * *')];
            }

            foreach ($rows as $row) {
                DB::table('scheduled_jobs')->insert($row + [
                    'tenant_id' => $tenantId, 'is_enabled' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        DB::table('settings')->whereIn('key', $keys)->delete();
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_jobs');
    }
};
