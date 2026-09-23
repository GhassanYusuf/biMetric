<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Copies the pre-2026-09-21 SQLite database into the primary connection.
 *
 * WHY IT IS NEEDED. This app ran on SQLite until 2026-09-21, when it moved to
 * SQL Server 2022 Express on 192.168.7.8 (`NewAttendance`). `php artisan migrate`
 * builds the schema there but brings no data, and the history is not reproducible
 * from the terminals: ZKTeco devices hold only what still fits in their own
 * attendance table, so the 151,440 punches already synced exist nowhere else.
 *
 * IT IS A COMMAND, NOT A ONE-OFF SCRIPT, because the move needs at least two runs.
 * The first copies the bulk while the old system is still serving; the second runs
 * at cutover to pick up whatever was recorded in between. Re-running is safe: every
 * table is keyed on its real primary key and rows already present are skipped, so a
 * second pass copies only the difference and never duplicates a punch.
 *
 * WHAT IT DELIBERATELY DOES NOT COPY. `migrations` is already correct on the target
 * and copying it would claim migrations ran that did not. `cache`, `cache_locks`,
 * `jobs` and `job_batches` are transient by definition -- a cache entry copied to a
 * new database is a stale answer, and a queued job copied twice runs twice.
 *
 * ON PRIMARY KEYS. Ids are preserved, not regenerated. Every foreign key in this
 * schema points at one, and re-keying would mean rewriting 42,322 attendance_raw_logs
 * references with no way to verify the result. SQL Server refuses writes to an
 * IDENTITY column unless IDENTITY_INSERT is on for that table, so the copy turns it
 * on per table and off again immediately.
 *
 * ON BYTES THAT ARE NOT TEXT. SQLite will store any byte sequence in a text column;
 * SQL Server will not, because the driver has to translate every string to UCS-2 and
 * raises "An error occurred translating string for input param N to UCS-2" when the
 * bytes are not valid UTF-8. The legacy database holds exactly one such value --
 * attendance_logs.device_user_id on punch 981687, a corrupt id off device 5 -- and
 * dropping the row was never an option: silently discarding undecodable attendance is
 * the specific bug rule 11 exists to prevent. Each offending value is instead widened
 * byte-for-byte (ISO-8859-1 -> UTF-8), which is lossless and reversible with
 * mb_convert_encoding($v, 'ISO-8859-1', 'UTF-8'), and every one is reported by table,
 * column and primary key at the end of the run. The row survives, the bytes survive,
 * and the transformation is stated rather than hidden.
 *
 * ON VERIFICATION (rules 3 and 10). The copy does not trust its own inserts. Foreign
 * keys are disabled for the load and then re-enabled WITH CHECK, which makes the
 * server re-validate every row rather than trusting the data it was handed; any
 * constraint left untrusted is reported as a failure. Row counts are compared per
 * table at the end. A table that does not match is named, and the command exits
 * non-zero -- it never reports a partial copy as done.
 */
class LegacySqliteCopy extends Command
{
    protected $signature = 'legacy:sqlite-copy
                            {--table=* : Only these tables; omit for all}
                            {--dry-run : Report what would be copied without writing}';

    protected $description = 'Copy the legacy SQLite database into the primary connection, preserving ids';

    /**
     * Transient or bookkeeping tables. See the class docblock for why each is here.
     */
    private const SKIP = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches'];

    /**
     * Values that were not valid UTF-8 and had to be widened. See the class docblock.
     *
     * @var list<string>
     */
    private array $widened = [];

    public function handle(): int
    {
        $legacy = DB::connection('sqlite_legacy');
        $target = DB::connection();

        if ($target->getDriverName() === 'sqlite') {
            $this->error('The primary connection is still SQLite. There is nothing to copy into.');

            return self::FAILURE;
        }

        $this->line("Source: {$legacy->getDatabaseName()}");
        $this->line("Target: {$target->getDatabaseName()} ({$target->getDriverName()})");

        $tables = $this->tablesToCopy($legacy, $target);
        if ($tables === []) {
            $this->warn('No matching tables.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            return $this->reportOnly($legacy, $target, $tables);
        }

        $this->disableForeignKeys($target);

        $copied = [];
        try {
            foreach ($tables as $table) {
                $copied[$table] = $this->copyTable($legacy, $target, $table);
            }
        } finally {
            // Always re-enable, even if a table threw. Leaving a production schema
            // with its foreign keys switched off is worse than a failed copy.
            $untrusted = $this->enableForeignKeys($target);
        }

        return $this->verify($legacy, $target, $copied, $untrusted);
    }

    /**
     * Tables present in BOTH databases, ordered as SQLite lists them. Order does not
     * matter here because foreign keys are off during the load and re-validated after.
     *
     * @return list<string>
     */
    private function tablesToCopy($legacy, $target): array
    {
        $only = $this->option('table');

        $source = collect($legacy->select(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        ))->pluck('name');

        return $source
            ->reject(fn ($t) => in_array($t, self::SKIP, true))
            ->when($only !== [], fn ($c) => $c->filter(fn ($t) => in_array($t, $only, true)))
            ->filter(fn ($t) => $target->getSchemaBuilder()->hasTable($t))
            ->values()
            ->all();
    }

    private function reportOnly($legacy, $target, array $tables): int
    {
        $rows = [];
        foreach ($tables as $t) {
            $src = $legacy->table($t)->count();
            $dst = $target->table($t)->count();
            $rows[] = [$t, $src, $dst, $src - $dst];
        }
        $this->table(['table', 'legacy', 'target', 'to copy'], $rows);
        $this->info('Dry run: nothing was written.');

        return self::SUCCESS;
    }

    /**
     * Copies one table in chunks, skipping rows whose primary key is already present.
     *
     * @return array{inserted:int, skipped:int}
     */
    private function copyTable($legacy, $target, string $table): array
    {
        $columns = $this->sharedColumns($legacy, $target, $table);
        $key = $this->primaryKey($target, $table);
        $identity = $this->hasIdentity($target, $table);

        $total = $legacy->table($table)->count();
        if ($total === 0) {
            $this->line(sprintf('  %-28s empty', $table));

            return ['inserted' => 0, 'skipped' => 0];
        }

        // SQL Server caps a statement at 2100 parameters, so the chunk size has to
        // fall as the table gets wider or a wide table fails at the driver.
        $perStatement = max(1, (int) floor(2000 / max(1, count($columns))));

        // Tables with a composite primary key (the permission pivots) have no single
        // column to test "is this row already here?" against, so the whole row is
        // fingerprinted instead. Without this a second run would duplicate them --
        // and a second run is the normal case, not the exception.
        $existing = $key !== null
            ? $target->table($table)->pluck($key)->flip()
            : collect($target->table($table)->get())
                ->mapWithKeys(fn ($r) => [$this->fingerprint((array) $r, $columns) => true]);

        $inserted = 0;
        $skipped = 0;
        $bar = $this->output->createProgressBar($total);

        $this->withIdentityInsert($target, $table, $identity, function () use (
            $legacy, $target, $table, $columns, $key, $existing, $perStatement, &$inserted, &$skipped, $bar
        ) {
            $query = $legacy->table($table)->select($columns);
            // chunk() insists on a deterministic order; with no primary key to use,
            // any stable column ordering will do since every row is read exactly once.
            $query->orderBy($key ?? $columns[0]);

            $query->chunk(2000, function ($rows) use (
                $target, $table, $columns, $key, $existing, $perStatement, &$inserted, &$skipped, $bar
            ) {
                $batch = [];
                foreach ($rows as $row) {
                    $row = (array) $row;
                    $seen = $key !== null
                        ? $existing->has($row[$key])
                        : $existing->has($this->fingerprint($row, $columns));

                    if ($seen) {
                        $skipped++;
                        $bar->advance();

                        continue;
                    }
                    $batch[] = $this->widenInvalidUtf8($row, $table, $key);
                    $bar->advance();
                }

                foreach (array_chunk($batch, $perStatement) as $slice) {
                    $target->table($table)->insert($slice);
                    $inserted += count($slice);
                }
            });
        });

        $bar->finish();
        $this->newLine();
        $this->line(sprintf('  %-28s %6d inserted, %6d already present', $table, $inserted, $skipped));

        return ['inserted' => $inserted, 'skipped' => $skipped];
    }

    /**
     * A stable identity for a row that has no single-column primary key.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $columns
     */
    private function fingerprint(array $row, array $columns): string
    {
        $ordered = [];
        foreach ($columns as $c) {
            $ordered[] = $row[$c] ?? null;
        }

        return md5(serialize($ordered));
    }

    /**
     * Makes a row safe for the UCS-2 translation the sqlsrv driver performs, without
     * losing a byte. A value that is not valid UTF-8 is widened one byte to one
     * codepoint; every original byte is still recoverable. See the class docblock.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function widenInvalidUtf8(array $row, string $table, ?string $key): array
    {
        foreach ($row as $column => $value) {
            if (! is_string($value) || $value === '' || mb_check_encoding($value, 'UTF-8')) {
                continue;
            }

            $row[$column] = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');

            $at = $key !== null ? "{$key}={$row[$key]}" : 'unkeyed row';
            $this->widened[] = "{$table}.{$column} ({$at}): ".bin2hex($value);
        }

        return $row;
    }

    /**
     * IDENTITY_INSERT is per table and only one table may have it on at a time, so it
     * is turned on immediately before the writes and off again straight after --
     * including when they throw.
     *
     * It MUST go through PDO::exec, not $connection->statement(). Laravel sends a
     * statement() as a prepared batch (sp_prepexec), and SQL Server scopes
     * SET IDENTITY_INSERT to that batch: the setting is reverted the instant the
     * batch ends, so the very next insert fails with "Cannot insert explicit value
     * for identity column ... when IDENTITY_INSERT is set to OFF". PDO::exec issues
     * it directly, where it persists for the connection. Verified on SQL Server 2022
     * Express, 2026-09-21.
     */
    private function withIdentityInsert($target, string $table, bool $identity, callable $write): void
    {
        if (! $identity) {
            $write();

            return;
        }

        $quoted = '['.str_replace(']', ']]', $table).']';
        $target->getPdo()->exec("SET IDENTITY_INSERT $quoted ON");
        try {
            $write();
        } finally {
            $target->getPdo()->exec("SET IDENTITY_INSERT $quoted OFF");
        }
    }

    /**
     * @return list<string>
     */
    private function sharedColumns($legacy, $target, string $table): array
    {
        $src = $legacy->getSchemaBuilder()->getColumnListing($table);
        $dst = $target->getSchemaBuilder()->getColumnListing($table);

        $shared = array_values(array_intersect($src, $dst));

        foreach (array_diff($src, $dst) as $dropped) {
            $this->warn("  {$table}.{$dropped} exists only in the legacy database and was not copied.");
        }

        return $shared;
    }

    private function primaryKey($target, string $table): ?string
    {
        $cols = $target->select(
            'SELECT c.name FROM sys.indexes i
             JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
             JOIN sys.columns c ON c.object_id = i.object_id AND c.column_id = ic.column_id
             WHERE i.is_primary_key = 1 AND i.object_id = OBJECT_ID(?)',
            [$table]
        );

        // Only single-column keys are usable as a "already copied?" probe.
        return count($cols) === 1 ? $cols[0]->name : null;
    }

    private function hasIdentity($target, string $table): bool
    {
        return (bool) $target->selectOne(
            'SELECT 1 AS x FROM sys.identity_columns WHERE object_id = OBJECT_ID(?)',
            [$table]
        );
    }

    private function disableForeignKeys($target): void
    {
        foreach ($target->select('SELECT name, OBJECT_NAME(parent_object_id) tbl FROM sys.foreign_keys') as $fk) {
            $target->statement("ALTER TABLE [{$fk->tbl}] NOCHECK CONSTRAINT [{$fk->name}]");
        }
    }

    /**
     * Re-enables every foreign key WITH CHECK, which re-validates the existing rows
     * instead of trusting them, and returns the ones that would not validate.
     *
     * @return list<string>
     */
    private function enableForeignKeys($target): array
    {
        $untrusted = [];

        foreach ($target->select('SELECT name, OBJECT_NAME(parent_object_id) tbl FROM sys.foreign_keys') as $fk) {
            try {
                $target->statement("ALTER TABLE [{$fk->tbl}] WITH CHECK CHECK CONSTRAINT [{$fk->name}]");
            } catch (Throwable $e) {
                $untrusted[] = "{$fk->tbl}.{$fk->name}: {$e->getMessage()}";
            }
        }

        return $untrusted;
    }

    /**
     * @param  array<string, array{inserted:int, skipped:int}>  $copied
     * @param  list<string>  $untrusted
     */
    private function verify($legacy, $target, array $copied, array $untrusted): int
    {
        $this->newLine();
        $this->info('Verifying against the source.');

        $mismatched = [];
        $rows = [];
        foreach (array_keys($copied) as $table) {
            $src = $legacy->table($table)->count();
            $dst = $target->table($table)->count();
            $ok = $src === $dst;
            if (! $ok) {
                $mismatched[] = $table;
            }
            $rows[] = [$table, $src, $dst, $ok ? 'ok' : 'MISMATCH'];
        }

        $this->table(['table', 'legacy', 'target', ''], $rows);

        foreach ($untrusted as $problem) {
            $this->error("Foreign key would not validate: {$problem}");
        }

        // Not a failure -- the rows were copied and no byte was lost -- but it must
        // never pass unmentioned (rule 10: say exactly what was and was not decoded).
        foreach ($this->widened as $note) {
            $this->warn("Not valid UTF-8, widened byte-for-byte; original bytes in hex: {$note}");
        }

        if ($mismatched !== [] || $untrusted !== []) {
            $this->error('Copy INCOMPLETE. '.implode(', ', $mismatched ?: ['constraint failures above']));

            return self::FAILURE;
        }

        $this->info('Every table matches the source and every foreign key re-validated.');

        return self::SUCCESS;
    }
}
