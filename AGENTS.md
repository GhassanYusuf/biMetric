# AGENTS.md

## Where this project lives

The code is **not** in this working directory. It is on the app host:

- Working dir: `/var/www/zkt`
- Reachable as: `ssh -o BatchMode=yes root@192.168.0.39 "cd /var/www/zkt && ..."`
- Laravel 12, PHP 8.x, MySQL, PHP-FPM + a `queue:work` worker under systemd
  (`zkt-queue.service`).

There is no local checkout, so `git`, `php`, `npm` and `artisan` must all run over
SSH. Copy files up with `scp` before editing them remotely; there is no shared
filesystem.

## Verifying a change

Run these on the host before calling anything done:

```
php artisan test          # 441 tests; no --parallel, ParaTest 7 is not installed
npm run build            # Vite; the app ships compiled assets
php -l <each .php file you touched>
```

`npm run build` output must be committed with the JSX change — the deployed app serves
`public/build`, not the source.

## Editing files remotely

`scp` the file down, edit it, `scp` it back. Two traps, both hit repeatedly:

- **PowerShell mangles quotes.** `$(...)`, `"` and `|` inside an `ssh "..."` string get
  interpreted locally. Put anything non-trivial in a script file, `scp` it to `/tmp`,
  and run it with `bash /tmp/script.sh`.
- **`sed -i` on four files at once is how you lose work.** Back up first, `diff` the
  backup against the result, and only then trust it. `C3Driver.php`, `ZktecoDriver.php`
  and `CslHttpDriver.php` all had uncommitted changes from earlier sessions, so a
  `git diff` after editing cannot isolate what you changed.

New files must be `chown www-data:www-data` — php-fpm runs as `www-data` and cannot
rewrite a root-owned source file.

## Hard rules this project enforces

There are tests for these, so they fail loudly rather than quietly:

- **Rule 8 — protocol boundary.** Only `ZktecoDriver` may import
  `Promoseven\Biometric`. Everything else uses `App\Contracts\BiometricDeviceDriver`.
  `tests/Unit/ProtocolLibraryBoundaryTest.php` has an explicit allow-list; adding a
  file to it needs a comment saying why.
- **Probe discipline.** Any script in `tools/zkprobe/*.php` must declare `READ-ONLY`
  or `WRITES TO THE DEVICE` in its header, or `ProbeDisciplineTest` fails.
- **Zero deletion** unless the owner has explicitly authorised it.

## Things that are true and will bite you

- **ZKT Access owns all four primary terminals** and can revert anything written to
  them. Direct writes have been made under explicit one-off authorisation; ongoing dual
  writing is not safe.
- **The device protocol cannot read "punches since X".** Every attendance read
  transfers the device's entire table. Schedule accordingly.
- **A terminal has no timezone database.** Setting a "timezone" means writing a wall
  clock. `App\Support\Timezone` is the single resolver; do not reintroduce
  `config('attendance.device_timezone')` fallbacks elsewhere.
- **`app.timezone` is UTC** on this host. Anything time-of-day for a person is
  evaluated in the company zone (`system.timezone`), never the server's.
- **The Laravel scheduler had no `schedule:run` wired to cron** until 2026-10-05. The
  hourly attendance job defined in `routes/console.php` had therefore never executed.
