<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\ScheduledJob;
use App\Services\Devices\ScheduledJobRunner;
use App\Support\ApiResponse;
use App\Support\Tenancy;
use App\Support\Timezone;
use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Cron jobs screen (owner, 2026-10-20: "shows the cron jobs that exist and lets users
 * add another and tinker with the time"). The company's own jobs are rows in
 * `scheduled_jobs`, run by ScheduledJobRunner; the system's own are listed read-only.
 *
 * A job is an action from ScheduledJob::ACTIONS, never a command line (owner's choice).
 * Seeing the screen is settings.view, changing it settings.manage — these schedules were
 * company settings until this screen existed.
 */
class ScheduledJobController extends Controller
{
    /**
     * The scheduler's own jobs, as routes/console.php defines them, for the screen to
     * show. Not editable there: these are the system's guarantees (rule 19's nightly
     * copy) and housekeeping, not company choices. Times are UTC — the server's.
     * ScheduledJobsApiTest pins this list to the real scheduler, so it cannot drift.
     */
    public const SYSTEM_JOBS = [
        'App\Jobs\CheckAllDeviceHealth' => ['*/5 * * * *', 'Terminal health check', 'Asks every ZKTeco terminal whether it is online.'],
        'expire-stale-enrollments' => ['* * * * *', 'Expire stuck enrollments', 'Ends a fingerprint enrollment whose worker died, so the person can start again.'],
        'fingerprint-copies-nightly' => ['30 2 * * *', 'Nightly copy of fingerprints and cards', 'Every terminal\'s users, fingers and cards stored in the system, so removing someone never loses them (rule 19).'],
        'webhooks:relay-punches' => ['* * * * *', 'Send punches to webhooks', 'Hands new punches to integration subscribers.'],
        'report-exports-prune' => ['15 3 * * *', 'Clear old report files', 'Deletes employee report files after 7 days.'],
    ];

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.view'), 403);

        $zone = Timezone::system();
        $jobs = ScheduledJob::query()->orderBy('name')->get()->map(fn (ScheduledJob $j) => $this->present($j, $zone));

        $system = collect(self::SYSTEM_JOBS)->map(fn (array $j, string $key) => [
            'key' => $key,
            'label' => $j[1],
            'description' => $j[2],
            'schedule' => $j[0],
            'timezone' => 'UTC',
            'next_runs' => $this->nextRuns($j[0], 'UTC', $zone, 1),
        ])->values();

        return ApiResponse::success($jobs, 'Cron jobs retrieved.', [
            'timezone' => $zone,
            'actions' => collect(ScheduledJob::ACTIONS)->map(fn ($a, $key) => ['key' => $key, 'label' => $a['label'], 'description' => $a['description']])->values(),
            'devices' => Device::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->name]),
            'system_jobs' => $system,
            'min_interval_minutes' => ScheduledJob::MIN_INTERVAL_MINUTES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $data = $this->validated($request);
        $job = ScheduledJob::create($data + ['tenant_id' => Tenancy::id(), 'created_by' => $request->user()->id]);

        return ApiResponse::created($this->present($job, Timezone::system()), "“{$job->name}” added.");
    }

    public function update(Request $request, ScheduledJob $scheduledJob): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $scheduledJob->update($this->validated($request, $scheduledJob));

        $message = $request->has('is_enabled') && count($request->all()) === 1
            ? ($scheduledJob->is_enabled ? "“{$scheduledJob->name}” is on." : "“{$scheduledJob->name}” is paused.")
            : "“{$scheduledJob->name}” saved.";

        return ApiResponse::success($this->present($scheduledJob, Timezone::system()), $message);
    }

    public function destroy(Request $request, ScheduledJob $scheduledJob): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $scheduledJob->delete();

        return ApiResponse::success(null, "“{$scheduledJob->name}” deleted.");
    }

    /** Queues the job's commands now, as if it were due. Same guards as a scheduled run. */
    public function run(Request $request, ScheduledJob $scheduledJob, ScheduledJobRunner $runner): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $runner->run($scheduledJob);
        AuditLog::create([
            'tenant_id' => Tenancy::id(),
            'user_id' => $request->user()->id,
            'action' => 'scheduled_job.run',
            'auditable_type' => ScheduledJob::class,
            'auditable_id' => $scheduledJob->id,
            'changes' => ['result' => $scheduledJob->last_result],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        return ApiResponse::success($this->present($scheduledJob->fresh(), Timezone::system()), $scheduledJob->last_result);
    }

    /**
     * When an unsaved expression would fire. Computed here, with the library the
     * scheduler runs on, rather than by a cron parser in the browser that could disagree.
     */
    public function preview(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.view'), 403);

        $zone = Timezone::system();
        $expression = trim((string) $request->query('expression'));
        $error = $this->scheduleError($expression, $zone);

        return ApiResponse::success([
            'valid' => $error === null,
            'expression' => $expression,
            'timezone' => $zone,
            'error' => $error,
            'next_runs' => $error === null ? $this->nextRuns($expression, $zone, $zone, 3) : [],
        ], 'Schedule preview.');
    }

    private function validated(Request $request, ?ScheduledJob $job = null): array
    {
        $partial = $job !== null ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$partial, 'string', 'max:100'],
            'action' => [$partial, 'string', Rule::in(array_keys(ScheduledJob::ACTIONS))],
            'schedule' => [$partial, 'string', 'max:100'],
            'device_ids' => ['sometimes', 'nullable', 'array', 'min:1'],
            'device_ids.*' => ['integer', 'distinct', Rule::exists('devices', 'id')->where('tenant_id', Tenancy::id())],
            'is_enabled' => ['sometimes', 'boolean'],
        ], [
            'device_ids.min' => 'Choose at least one terminal, or all of them.',
            'device_ids.*.exists' => 'That terminal is not one of yours.',
        ]);

        if (isset($data['schedule'])) {
            $data['schedule'] = preg_replace('/\s+/', ' ', trim($data['schedule']));
            if ($error = $this->scheduleError($data['schedule'], Timezone::system())) {
                throw ValidationException::withMessages(['schedule' => [$error]]);
            }
        }
        if (array_key_exists('name', $data)) {
            $data['name'] = trim($data['name']);
        }
        if (array_key_exists('device_ids', $data) && $data['device_ids'] !== null) {
            $data['device_ids'] = array_values(array_map('intval', $data['device_ids']));
        }

        return $data;
    }

    /** null when the scheduler can run it; otherwise a sentence for the person who typed it. */
    private function scheduleError(string $expression, string $zone): ?string
    {
        if ($expression === '' || ! CronExpression::isValidExpression($expression)) {
            return "'{$expression}' is not a valid cron expression. Five fields, e.g. '0 3 * * *' for 03:00 daily.";
        }

        // Syntactically valid but impossible ('0 0 30 2 *', 30 February) throws when
        // the library searches for the first match — a sentence, not a 500.
        $runs = $this->nextRuns($expression, $zone, $zone, 12);
        if ($runs === []) {
            return 'This expression can never match a real date, so it would never run.';
        }

        // Every run of a read moves a terminal's WHOLE attendance table, and the clock
        // has no business being set every minute either.
        for ($i = 1; $i < count($runs); $i++) {
            $gap = (strtotime($runs[$i]) - strtotime($runs[$i - 1])) / 60;
            if ($gap < ScheduledJob::MIN_INTERVAL_MINUTES) {
                return sprintf('Runs %d minutes apart. Leave at least %d minutes between runs — every read transfers each terminal\'s whole attendance table.', $gap, ScheduledJob::MIN_INTERVAL_MINUTES);
            }
        }

        return null;
    }

    /** @return list<string> the next $count run times, in $showIn, ISO 8601 */
    private function nextRuns(string $expression, string $evaluateIn, string $showIn, int $count): array
    {
        try {
            // The zone belongs to getNextRunDate(), NOT to the constructor — its second
            // argument is a FieldFactory.
            $cron = new CronExpression($expression);
            $cursor = new DateTimeImmutable('now', new DateTimeZone($evaluateIn));
            $out = [];
            for ($i = 0; $i < $count; $i++) {
                $cursor = DateTimeImmutable::createFromMutable($cron->getNextRunDate($cursor, 0, false, $evaluateIn));
                $out[] = $cursor->setTimezone(new DateTimeZone($showIn))->format(DATE_ATOM);
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private function present(ScheduledJob $job, string $zone): array
    {
        return [
            'id' => $job->id,
            'name' => $job->name,
            'action' => $job->action,
            'action_label' => ScheduledJob::ACTIONS[$job->action]['label'] ?? $job->action,
            'schedule' => $job->schedule,
            'device_ids' => $job->device_ids,
            'is_enabled' => $job->is_enabled,
            'next_runs' => $job->is_enabled ? $this->nextRuns($job->schedule, $zone, $zone, 2) : [],
            'last_run_at' => $job->last_run_at?->toIso8601String(),
            'last_result' => $job->last_result,
        ];
    }
}
