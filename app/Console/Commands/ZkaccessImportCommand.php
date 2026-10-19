<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Import\ZkaccessImportService;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ZkaccessImportCommand extends Command
{
    protected $signature = 'zkaccess:import {file : Path to the zkaccess-export.json produced by scripts/export-zkaccess.ps1}
        {--tenant=1 : Tenant id the imported data belongs to}
        {--apply : Actually write. Without it the run only reports what WOULD happen.}
        {--no-create-new : Do not create employees for unmatched ZKAccess users}
        {--skip-attendance : Only import departments and employee profiles}';

    protected $description = 'Imports a ZKAccess backup export (departments, employee profiles, attendance).';

    /**
     * The export is a one-shot snapshot of a legacy database, produced on the
     * Windows machine that owns the .mdb (the Access ODBC driver is not available on
     * this Linux box). It matches people to employees by PIN (Badgenumber) and will
     * only ever fill empty fields, so running it dry first and then again --apply is
     * the intended flow. Dry runs read everything and write nothing.
     */
    public function handle(ZkaccessImportService $service): int
    {
        $file = $this->argument('file');

        if (! is_file($file) || ! is_readable($file)) {
            $this->error("File not found or not readable: {$file}");

            return self::FAILURE;
        }

        $json = file_get_contents($file);
        if ($json === false) {
            $this->error("Could not read: {$file}");

            return self::FAILURE;
        }

        $data = json_decode($json, true);
        if (! is_array($data)) {
            $this->error("{$file} is not valid JSON (".json_last_error_msg().').');
            $this->error('Did the Windows export run to completion?');

            return self::FAILURE;
        }

        $errors = Validator::make($data, [
            'source' => 'required|string',
            'exported_at' => 'required|string',
            'users' => 'required|array',
            'punches' => 'required|array',
        ])->errors();
        if ($errors->isNotEmpty()) {
            $this->error('Export is missing required sections: '.implode(', ', $errors->keys()));

            return self::FAILURE;
        }

        $tenant = Tenant::withoutGlobalScopes()->find($this->option('tenant'));
        if (! $tenant) {
            $this->error("Tenant {$this->option('tenant')} does not exist.");

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        $this->line('Export: '.$data['source'].' at '.$data['exported_at']);
        $this->line('Tenant: '.$tenant->name.' (#'.$tenant->id.')');
        $this->warn($apply
            ? 'APPLY MODE — data will be written.'
            : 'DRY RUN — nothing will be written. Repeat with --apply to commit.');
        $this->newLine();

        Tenancy::set($tenant->id);
        try {
            $report = $service
                ->setData($data)
                ->setTenant((int) $tenant->id)
                ->setApply($apply)
                ->setCreateNew(! $this->option('no-create-new'))
                ->setImportAttendance(! $this->option('skip-attendance'))
                ->run();
        } catch (Throwable $e) {
            Tenancy::clear();
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            Tenancy::clear();
        }

        $this->renderReport($report, $apply);

        return $report['warnings'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function renderReport(array $report, bool $apply): void
    {
        $this->info('Departments: '.$report['departments']['existing'].' existing, '
            .$report['departments']['created'].' '.($apply ? 'created' : 'to create'));

        $e = $report['employees'];
        $this->info("Employees: {$e['matched_by_pin']} by PIN, {$e['matched_by_name']} by name, {$e['created']} ".($apply ? 'created' : 'to create').'.');
        $this->info("  skipped: {$e['skipped_deleted']} deleted, {$e['skipped_no_pin']} no PIN, {$e['skipped_code_collision']} PIN collision.");
        if ($apply) {
            $this->info("  profiles filled: {$e['profiles_filled']} employees / {$e['fields_filled']} fields, {$e['cards_skipped']} cards skipped (already held).");
        }

        $a = $report['attendance'];
        if ($a['skipped']) {
            $this->warn('Attendance: skipped (--skip-attendance).');
        } else {
            $this->info("Attendance: {$a['candidates']} candidates, {$a['duplicates']} already in the system, "
                .$a['inserted'].' '.($apply ? 'inserted' : 'would be inserted').'.');
            $this->line("  skipped: {$a['orphans']} unknown users, {$a['unresolvable_device']} no matching device.");
        }

        if ($report['warnings'] !== []) {
            $this->newLine();
            $this->warn(count($report['warnings']).' warnings (ambiguous names, card conflicts, PIN collisions):');
            foreach ($report['warnings'] as $warning) {
                $this->line('  - '.$warning['user'].' (card '.$warning['card'].'): '.$warning['reason']);
            }
        }
    }
}
