<?php

namespace App\Console\Commands;

use App\Services\Employees\DuplicateEmployeeFinder;
use App\Services\Employees\EmployeeMergeService;
use Illuminate\Console\Command;

/**
 * Collapses the duplicate employment records left by a per-terminal roster import.
 *
 * Defaults to the CONFIRMED set only — the groups where every terminal reports the same
 * card number. Everything weaker has to be asked for explicitly, because "the names
 * matched" is not a reason to merge two people's attendance together.
 *
 * Contacts no device. See EmployeeMergeService for what actually moves.
 */
class EmployeesMerge extends Command
{
    protected $signature = 'employees:merge
                            {--verdict=confirmed : namespaced | proven | confirmed | unconfirmed | all}
                            {--skip= : Comma-separated keep-record ids to leave alone}
                            {--dry-run : Show the plan and change nothing}';

    protected $description = 'Merge duplicate employee records created by per-device roster imports';

    public function handle(DuplicateEmployeeFinder $finder, EmployeeMergeService $merger): int
    {
        $verdict = strtolower((string) $this->option('verdict'));

        if (! in_array($verdict, ['namespaced', 'proven', 'confirmed', 'unconfirmed', 'all'], true)) {
            $this->error('--verdict must be namespaced, proven, confirmed, unconfirmed or all.');

            return self::FAILURE;
        }

        $skip = collect(explode(',', (string) $this->option('skip')))
            ->map(fn ($v) => (int) trim($v))
            ->filter()
            ->all();

        $groups = $finder->find();

        $wanted = $groups->filter(function (array $g) use ($verdict) {
            // CONTRADICTED is never included by any option. The evidence points away
            // from merging, and a flag that overrides evidence is a flag that gets used.
            if ($g['verdict'] === DuplicateEmployeeFinder::CONTRADICTED) {
                return false;
            }

            return match ($verdict) {
                // PROVEN is stronger than CONFIRMED, so asking for the card-confirmed
                // set includes it. Asking for the weaker set never pulls in the stronger.
                'namespaced' => $g['verdict'] === DuplicateEmployeeFinder::NAMESPACED,
                'proven' => in_array($g['verdict'], [
                    DuplicateEmployeeFinder::NAMESPACED,
                    DuplicateEmployeeFinder::PROVEN,
                ], true),
                'confirmed' => in_array($g['verdict'], [
                    DuplicateEmployeeFinder::NAMESPACED,
                    DuplicateEmployeeFinder::PROVEN,
                    DuplicateEmployeeFinder::CONFIRMED,
                ], true),
                'unconfirmed' => $g['verdict'] === DuplicateEmployeeFinder::UNCONFIRMED,
                default => true,
            };
        })->reject(fn (array $g) => in_array($g['keep'], $skip, true))->values();

        $contradicted = $groups->where('verdict', DuplicateEmployeeFinder::CONTRADICTED)->count();

        $this->newLine();
        $this->line(sprintf('  %-34s %d', 'duplicate groups found', $groups->count()));
        $this->line(sprintf('  %-34s %d', '  one vendor-managed PIN namespace', $groups->where('verdict', DuplicateEmployeeFinder::NAMESPACED)->count()));
        $this->line(sprintf('  %-34s %d', '  proven by fingerprint template', $groups->where('verdict', DuplicateEmployeeFinder::PROVEN)->count()));
        $this->line(sprintf('  %-34s %d', '  card-confirmed', $groups->where('verdict', DuplicateEmployeeFinder::CONFIRMED)->count()));
        $this->line(sprintf('  %-34s %d', '  unconfirmed', $groups->where('verdict', DuplicateEmployeeFinder::UNCONFIRMED)->count()));
        $this->line(sprintf('  %-34s %d  (never merged)', '  contradicted by the cards', $contradicted));
        $this->newLine();
        $this->line(sprintf('  %-34s %d group(s), %d record(s) deleted',
            'selected', $wanted->count(), $wanted->sum(fn ($g) => count($g['merge']))));

        if ($skip !== []) {
            $this->line(sprintf('  %-34s %d', 'skipped by --skip', count($skip)));
        }

        if ($wanted->isEmpty()) {
            $this->newLine();
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['Name', 'PIN', 'Evidence', 'Keep', 'Delete'],
            $wanted->take(15)->map(fn (array $g) => [
                mb_strimwidth($g['name'], 0, 22, '…'),
                $g['pin'],
                $g['reason'],
                '#'.$g['keep'],
                '#'.implode(' #', $g['merge']),
            ])->all(),
        );

        if ($wanted->count() > 15) {
            $this->line('  … and '.($wanted->count() - 15).' more');
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->line('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        $this->newLine();
        if (! $this->confirm('Merge these? The folded records are deleted and cannot be restored from the app.', false)) {
            $this->line('Nothing was merged.');

            return self::FAILURE;
        }

        $bar = $this->output->createProgressBar($wanted->count());
        $bar->start();

        $merged = 0;
        $failed = [];

        foreach ($wanted as $g) {
            try {
                $merger->merge($g['keep'], $g['merge']);
                $merged += count($g['merge']);
            } catch (\Throwable $e) {
                $failed[] = "{$g['name']} PIN {$g['pin']}: {$e->getMessage()}";
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Merged {$wanted->count()} group(s); {$merged} record(s) removed.");

        if ($failed !== []) {
            $this->newLine();
            $this->warn(count($failed).' group(s) failed:');
            foreach (array_slice($failed, 0, 10) as $f) {
                $this->line("  {$f}");
            }
        }

        $this->newLine();
        $this->line('Now re-run attribution so the moved punches resolve to the kept records:');
        $this->line('  php artisan attendance:reattribute');

        return self::SUCCESS;
    }
}
