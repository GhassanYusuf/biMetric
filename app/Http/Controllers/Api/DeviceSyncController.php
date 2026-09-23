<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceSyncRunResource;
use App\Http\Resources\DeviceCommandResource;
use App\Http\Resources\DeviceStatusLogResource;
use App\Models\AttendanceRawLog;
use App\Models\AttendanceSyncRun;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\Devices\DeviceCommandBus;
use App\Services\Devices\SyncProgressRecorder;
use App\Support\ApiResponse;
use App\Support\AttendanceWindow;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Promoseven\Biometric\Capability\Capability;

/**
 * Sync history, the command queue, and health history.
 *
 * Every device action here is QUEUED, never performed in the request. The response
 * carries a command id; the outcome arrives over MQTT or by polling the command.
 * That is the whole point of the queue — a fingerprint capture blocks for 30 seconds
 * and must not hold a web worker.
 */
class DeviceSyncController extends Controller
{
    public function __construct(private readonly DeviceCommandBus $bus) {}

    /** Sync run history, newest first. Optionally filtered to one device. */
    public function runs(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Device::class);

        $request->validate([
            'device_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $runs = AttendanceSyncRun::query()
            ->with('device:id,name')
            ->when($request->filled('device_id'), fn ($q) => $q->where('device_id', $request->integer('device_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('started_at')
            ->paginate($request->integer('per_page', 25));

        return ApiResponse::success(AttendanceSyncRunResource::collection($runs), 'Sync runs retrieved.');
    }

    /**
     * Deletes sync run history for the current filter.
     *
     * WHY THIS IS A DELETE AND NOT A HIDE: the history table is the only thing that
     * grows without bound here — one row per run per device, plus every raw payload
     * that run archived. A workspace that syncs four terminals every ten minutes
     * writes ~24,000 rows a month, and none of it is needed once it has been read.
     *
     * WHAT GOES WITH IT: `attendance_raw_logs` rows belonging to the cleared runs.
     * They are the pre-parse byte archive that exists so an undecodable punch can be
     * reparsed after a decoder fix (see the create migration), so this is a real
     * loss and the UI says so before asking. They are deleted explicitly rather than
     * left to the foreign key, because the count is part of the answer and because
     * SQLite only cascades when the connection has foreign keys enabled.
     *
     * WHAT DOES NOT GO WITH IT:
     *   - Imported attendance. Punches live in their own table and are untouched.
     *   - The incremental watermark. It lives on `devices.attendance_watermark`,
     *     not on the newest run, so clearing history cannot make the next sync
     *     re-read or — far worse — skip anything.
     *   - Runs that are still in progress. Deleting one mid-transfer would leave
     *     the progress poller and the recorder writing to a row that is gone, so
     *     they are always excluded and reported separately.
     */
    public function clearRuns(Request $request): JsonResponse
    {
        $this->authorize('clearHistory', Device::class);

        $request->validate([
            'device_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
        ]);

        $scoped = fn () => AttendanceSyncRun::query()
            ->when($request->filled('device_id'), fn ($q) => $q->where('device_id', $request->integer('device_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

        $running = $scoped()->where('status', AttendanceSyncRun::STATUS_RUNNING)->count();

        $ids = $scoped()
            ->where('status', '!=', AttendanceSyncRun::STATUS_RUNNING)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return ApiResponse::success(
                ['runs_deleted' => 0, 'raw_logs_deleted' => 0, 'runs_kept_running' => $running],
                $running > 0
                    ? 'Nothing cleared. A sync that is still running is never deleted.'
                    : 'There was no sync history to clear.',
            );
        }

        $rawDeleted = 0;

        DB::transaction(function () use ($ids, &$rawDeleted) {
            // Chunked so the IN list stays inside every driver's placeholder limit.
            foreach (array_chunk($ids, 500) as $chunk) {
                $rawDeleted += AttendanceRawLog::query()->whereIn('attendance_sync_run_id', $chunk)->delete();
                AttendanceSyncRun::query()->whereIn('id', $chunk)->delete();
            }
        });

        /*
         * Audited by hand: AttendanceSyncRun is not an observed model, and a mass
         * delete bypasses model events regardless. Who erased the history is
         * exactly the kind of thing somebody asks about later.
         */
        AuditLog::create([
            'tenant_id' => Tenancy::id(),
            'user_id' => $request->user()?->id,
            'action' => 'syncrun.cleared',
            'auditable_type' => AttendanceSyncRun::class,
            'auditable_id' => null,
            'changes' => [
                'runs_deleted' => count($ids),
                'raw_logs_deleted' => $rawDeleted,
                'filter_device_id' => $request->filled('device_id') ? $request->integer('device_id') : null,
                'filter_status' => $request->filled('status') ? (string) $request->string('status') : null,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        $message = sprintf(
            'Cleared %s sync run%s and %s archived payload%s.',
            number_format(count($ids)),
            count($ids) === 1 ? '' : 's',
            number_format($rawDeleted),
            $rawDeleted === 1 ? '' : 's',
        );

        if ($running > 0) {
            $message .= sprintf(' %d run still in progress was kept.', $running);
        }

        return ApiResponse::success([
            'runs_deleted' => count($ids),
            'raw_logs_deleted' => $rawDeleted,
            'runs_kept_running' => $running,
        ], $message);
    }

    /**
     * The live state of a device's sync, for a progress bar to poll.
     *
     * WHY BY DEVICE AND NOT BY RUN ID: the sync endpoint runs the read inline and
     * does not answer until it is finished, so the browser never learns the run id
     * until the thing it wanted to watch is over. It knows the device, so this
     * looks up the newest run for that device instead.
     *
     * Deliberately cheap: one indexed row, no eager loads, no pagination. It is
     * polled about once a second per visible device while a sync is running, and
     * anything heavier would compete with the sync for the same database.
     *
     * Returns the most recent run whether or not it is still going, so a poller
     * that arrives late still sees how it ended rather than a bare 404.
     */
    public function syncProgress(Device $device): JsonResponse
    {
        $this->authorize('view', $device);

        $run = AttendanceSyncRun::query()
            ->where('device_id', $device->id)
            ->latest('started_at')
            ->first();

        if ($run === null) {
            return ApiResponse::success(
                ['running' => false, 'run' => null, 'progress' => null],
                'This device has never been synced.',
            );
        }

        /*
         * The LIVE reading comes from the file cache, not the row.
         *
         * While a transfer is in flight the recorder deliberately does not touch
         * SQLite — a badly-timed write there costs datagrams and fails the sync
         * outright (see SyncProgressRecorder's docblock). The row is written at
         * phase boundaries only, so it lags during the read phase. Prefer the
         * published reading and fall back to the row, which is what remains after
         * the cache entry expires or the run has finished.
         */
        $live = SyncProgressRecorder::published($device->id);
        $progress = ($live !== null && ($live['run_id'] ?? null) === $run->id)
            ? Arr::except($live, 'run_id')
            : $run->progress();

        if ($progress !== null && ($progress['total'] ?? 0) > 0) {
            $progress['percent'] = (int) round(min(1.0, $progress['read'] / $progress['total']) * 100);
        } elseif ($progress !== null) {
            // Null, never 0 — rule 10. "We were not told the size" is not "empty".
            $progress['percent'] = null;
        }

        return ApiResponse::success([
            'running' => $run->status === AttendanceSyncRun::STATUS_RUNNING,
            'run_id' => $run->id,
            'status' => $run->status,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),

            // Null once the run is over. The final counts below are the authority
            // from that point on.
            'progress' => $progress,

            'counts' => [
                'read' => $run->records_read,
                'imported' => $run->records_imported,
                'duplicate' => $run->records_duplicate,
                'undecodable' => $run->records_undecodable,
                'unmatched' => $run->records_unmatched,
            ],
            'message' => $run->message,
        ], 'Sync progress retrieved.');
    }

    public function run(AttendanceSyncRun $run): JsonResponse
    {
        $this->authorize('viewAny', Device::class);

        return ApiResponse::success(
            new AttendanceSyncRunResource($run->load('device:id,name')),
            'Sync run retrieved.',
        );
    }

    /** Command queue history. */
    public function commands(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Device::class);

        $request->validate([
            'device_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
            'command' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $commands = DeviceCommand::query()
            ->with(['device:id,name', 'requester:id,name'])
            ->when($request->filled('device_id'), fn ($q) => $q->where('device_id', $request->integer('device_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('command'), fn ($q) => $q->where('command', $request->string('command')))
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        return ApiResponse::success(DeviceCommandResource::collection($commands), 'Device commands retrieved.');
    }

    public function command(DeviceCommand $command): JsonResponse
    {
        $this->authorize('viewAny', Device::class);

        return ApiResponse::success(
            new DeviceCommandResource($command->load(['device:id,name', 'requester:id,name'])),
            'Command retrieved.',
        );
    }

    /**
     * Queues an operation on one device.
     *
     * Capability-gated: a command the firmware cannot do is refused with 422 before
     * anything is queued, so the queue never fills with work that must fail.
     */
    public function dispatchCommand(Request $request, Device $device): JsonResponse
    {
        $this->authorize('sync', $device);

        $data = $request->validate([
            'command' => ['required', 'string', Rule::in([
                DeviceCommand::SYNC_ATTENDANCE,
                DeviceCommand::SYNC_ENROLLMENT,
                DeviceCommand::IMPORT_EMPLOYEES,
                DeviceCommand::RECONCILE_USERS,
                DeviceCommand::HEALTH_CHECK,
                DeviceCommand::READ_INFO,
                DeviceCommand::PROBE_CAPABILITIES,
                DeviceCommand::SYNC_TIME,
            ])],
            'full' => ['boolean'],
            // Only SYNC_ATTENDANCE reads them, and the payload is inert to every
            // other command — but they are validated here so a mistyped date is a
            // 422 at the door rather than a run that quietly imports nothing.
        ] + AttendanceWindow::rules($request));

        if ($error = $this->capabilityRefusal($device, $data['command'])) {
            return ApiResponse::error($error, ['command' => [$error]], 422);
        }

        $window = AttendanceWindow::fromValidated($data);

        $command = $this->bus->dispatch(
            $device,
            $data['command'],
            $window->toPayload((bool) ($data['full'] ?? false)),
        );

        return ApiResponse::success(
            new DeviceCommandResource($command),
            $window->isBounded()
                ? "Queued {$data['command']} on {$device->name} for {$window->describe()}. Watch the command for its outcome."
                : "Queued {$data['command']} on {$device->name}. Watch the command for its outcome.",
        );
    }

    /** Re-queues a failed command, reusing the same row so history stays in one place. */
    public function retry(DeviceCommand $command): JsonResponse
    {
        $this->authorize('sync', $command->device);

        try {
            $this->bus->retry($command);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), [], 422);
        }

        return ApiResponse::success(new DeviceCommandResource($command->fresh()), 'Command re-queued.');
    }

    public function cancel(DeviceCommand $command): JsonResponse
    {
        $this->authorize('sync', $command->device);

        try {
            $this->bus->cancel($command);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), [], 422);
        }

        return ApiResponse::success(new DeviceCommandResource($command->fresh()), 'Command cancelled.');
    }

    /** Health transition history for one device. */
    public function statusHistory(Request $request, Device $device): JsonResponse
    {
        $this->authorize('view', $device);

        $logs = $device->statusLogs()
            ->latest('observed_at')
            ->paginate($request->integer('per_page', 50));

        return ApiResponse::success(DeviceStatusLogResource::collection($logs), 'Status history retrieved.');
    }

    /**
     * Maps a command to the capability it needs.
     *
     * Returns an operator-facing refusal, or null when the device can do it.
     */
    private function capabilityRefusal(Device $device, string $command): ?string
    {
        $required = match ($command) {
            DeviceCommand::SYNC_ATTENDANCE => Capability::ATTENDANCE_READ,
            DeviceCommand::SYNC_ENROLLMENT,
            DeviceCommand::IMPORT_EMPLOYEES,
            DeviceCommand::RECONCILE_USERS => Capability::USER_READ,
            DeviceCommand::SYNC_TIME => Capability::SET_TIME,
            default => null, // health/info/probe are always available
        };

        if ($required === null) {
            return null;
        }

        $capabilities = $device->capabilitySet();

        if ($capabilities->supports($required)) {
            return null;
        }

        return sprintf(
            '%s does not support %s: %s',
            $device->name,
            $required,
            Capability::describe($capabilities->level($required)),
        );
    }
}
