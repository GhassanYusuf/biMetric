<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Device\IdentifyDeviceRequest;
use App\Http\Requests\Api\Device\StoreDeviceRequest;
use App\Http\Requests\Api\Device\UpdateDeviceRequest;
use App\Http\Resources\DeviceCommandResource;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\Devices\AttendanceSyncService;
use App\Services\Devices\DeviceCommandBus;
use App\Services\Devices\DeviceHealthService;
use App\Services\Devices\DeviceUserSyncService;
use App\Support\ApiResponse;
use App\Support\AttendanceWindow;
use App\Support\DeviceErrorReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Promoseven\Biometric\Exception\DeviceException;

/**
 * Device CRUD plus the operator-triggered device reads.
 *
 * The read actions below still run INLINE, because the SPA shows their result
 * immediately and an operator pressing "Read from device" expects an answer, not a
 * queue receipt. They are reads: they cannot leave a terminal half-written, which
 * is what rule 11's "queue everything" exists to prevent. Anything that WRITES to a
 * device goes through DeviceCommandBus — see DeviceSyncController::dispatchCommand
 * and DeviceEnrollmentController.
 */
class DeviceController extends Controller
{
    public function __construct(
        private readonly DeviceHealthService $health,
        private readonly AttendanceSyncService $attendance,
        private readonly DeviceUserSyncService $users,
        private readonly DeviceErrorReporter $errors,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Device::class);

        // unclaimedRosterEntries is counted in the same statement rather than per
        // row: the list shows whether each terminal can be written to, and asking
        // once per device is the N+1 this codebase keeps having to remove.
        $devices = Device::query()
            ->withCount(['attendanceLogs', 'unclaimedRosterEntries'])
            ->latest()
            ->get();

        return ApiResponse::success(DeviceResource::collection($devices), 'Devices retrieved.');
    }

    public function store(StoreDeviceRequest $request): JsonResponse
    {
        $this->authorize('create', Device::class);

        $data = $request->validated();
        $device = Device::create($data + ['port' => $data['port'] ?? 4370]);

        return ApiResponse::created(new DeviceResource($device), 'Device added.');
    }

    /**
     * Reads a device's identity before it is saved, so the add-device form can
     * confirm the model instead of asking the operator to know it.
     *
     * Nothing is persisted — a transient Device is built purely to read identity.
     * It goes through the driver contract, so it works for every protocol the app
     * drives. A device that does not answer THIS protocol fails the read, and that
     * is a true "cannot identify" rather than a 500 — it comes back as a 502 with
     * an honest message. That failure is useful: trying the other protocol is how
     * you discover which family a device belongs to, which is exactly how the
     * TF1700 was found to be a C3 panel rather than a standalone terminal.
     */
    public function identify(IdentifyDeviceRequest $request): JsonResponse
    {
        $this->authorize('create', Device::class);

        $data = $request->validated();
        $device = new Device([
            'name' => 'identify-probe',
            'ip_address' => $data['ip_address'],
            'port' => $data['port'] ?? 4370,
            'protocol' => $data['protocol'],
            'comm_key' => $data['comm_key'] ?? '',
        ]);

        try {
            $info = $this->health->identify($device);
        } catch (\Throwable $e) {
            return ApiResponse::error(
                'No answer on the '.$data['protocol'].' protocol. Try another one — a model name '
                .'does not tell you the family: inBIO and C3 panels, and the TF1700, speak C3/PullSDK '
                .'while F- and K-series terminals speak the binary protocol.',
                ['detail' => $e->getMessage()],
                502,
            );
        }

        return ApiResponse::success(
            $info,
            $info['device_name']
                ? 'Device reports it is a '.$info['device_name'].'.'
                : 'Device answered but did not report a model.',
        );
    }

    public function show(Device $device): JsonResponse
    {
        $this->authorize('view', $device);

        $device->loadCount('attendanceLogs');

        return ApiResponse::success(new DeviceResource($device), 'Device retrieved.');
    }

    public function update(UpdateDeviceRequest $request, Device $device): JsonResponse
    {
        $this->authorize('update', $device);

        $data = $request->validated();

        // Secrets are never sent back to the client, so an empty submission
        // means "leave unchanged", not "clear it".
        foreach (['comm_key', 'web_password'] as $secret) {
            if (($data[$secret] ?? '') === '') {
                unset($data[$secret]);
            }
        }

        $device->update($data);

        return ApiResponse::success(new DeviceResource($device->fresh()), 'Device updated.');
    }

    public function destroy(Device $device): JsonResponse
    {
        $this->authorize('delete', $device);

        $device->delete();

        return ApiResponse::success(null, 'Device removed.');
    }

    /**
     * DeviceHealthService records the outcome either way — an unreachable terminal
     * is a health event worth persisting, not just a failed request — so it does not
     * throw. The stored status is what decides the HTTP code here: a device that did
     * not answer must not return 200, or the SPA shows a success toast for a
     * terminal that is offline.
     */
    public function testConnection(Device $device): JsonResponse
    {
        $this->authorize('sync', $device);

        $result = $this->health->check($device);

        if (($result['raw']['status'] ?? null) !== Device::HEALTH_ONLINE) {
            return ApiResponse::error($result['message'], $result['raw'], 502);
        }

        return ApiResponse::success($result['raw'], $result['message']);
    }

    /**
     * Reads the terminal's attendance table now and returns the result.
     *
     * `full=true` ignores the sync watermark and reconsiders every record the
     * terminal still holds. It does NOT change what comes off the device: this
     * protocol has no "punches since X", so ATTLOG_RRQ always transfers the whole
     * table and "incremental" is client-side filtering. The flag costs database
     * work, not network, and the unique punch index still prevents duplicates.
     *
     * It is the way to recover after a decoder fix, which is exactly when somebody
     * needs it from a script rather than from the device detail screen.
     */
    /**
     * QUEUES an attendance read; it never runs inline.
     *
     * A device read is not a web-request-shaped operation (rule 11). A full re-read
     * of a large terminal takes minutes — a public-IP F18 like Camelot is ~285 s —
     * and running it inside the HTTP request means the front proxy times the request
     * out (a 524) long before the device finishes, so the read looks like it failed
     * when it was still going. Queueing returns at once; the caller watches
     * `sync-progress` (already polled by the devices screen) for the outcome.
     *
     * WithoutOverlapping on the job keeps this to one read per device even if the
     * button is pressed twice, and the row owns the domain retries with backoff that
     * a flaky remote device needs (rule 11). A worker MUST be running — see the
     * queue-worker note in docs/PROJECT-STATE.md.
     */
    public function syncAttendance(Request $request, Device $device, DeviceCommandBus $bus): JsonResponse
    {
        $this->authorize('sync', $device);

        /*
         * `from`/`to` are DEVICE-LOCAL calendar dates, both inclusive, and either may
         * stand alone — "everything since 1 August" is as valid a request as a closed
         * window. They are not a device query: the protocol has no "punches since X",
         * so the terminal still sends its whole table and these bound what gets
         * IMPORTED. See AttendanceSyncService for why that is still worth having.
         */
        $data = $request->validate(['full' => ['boolean']] + AttendanceWindow::rules($request));

        $full = $request->boolean('full');
        $window = AttendanceWindow::fromValidated($data);

        $command = $bus->dispatch($device, DeviceCommand::SYNC_ATTENDANCE, $window->toPayload($full));

        return ApiResponse::success(
            new DeviceCommandResource($command),
            match (true) {
                $window->isBounded() => sprintf(
                    'Date-range read queued for %s (%s) — watch progress here.',
                    $device->name,
                    $window->describe(),
                ),
                $full => "Full re-read queued for {$device->name} — watch progress here.",
                default => "Attendance sync queued for {$device->name}.",
            },
        );
    }

    /**
     * The first fingerprint write on a terminal whose model never did one from this
     * app (FingerprintPusher::trialWrite, 2026-10-11). Add-only: one finger the system
     * holds goes to a person on the terminal who is missing it; nobody is deleted.
     * Offered on a red "cannot take fingerprints yet" push row. 409 when it did not
     * pass — the terminal is fine, the test is the answer.
     */
    public function fingerprintWriteTest(Device $device, \App\Services\Employees\FingerprintPusher $pusher): JsonResponse
    {
        $this->authorize('update', $device);

        set_time_limit(120);

        try {
            $result = $pusher->trialWrite($device);
        } catch (DeviceException $e) {
            return ApiResponse::error("The test could not reach {$device->name}: {$e->getMessage()}", [], 502);
        } catch (\DomainException $e) {
            return ApiResponse::error($e->getMessage(), [], 409);
        }

        return $result['ok']
            ? ApiResponse::success($result, $result['message'])
            : ApiResponse::error($result['message'], $result, 409);
    }

    /**
     * "Rebuild from system" preview (DeviceRebuildService::plan): what would change on
     * this terminal, read-only. One read of its users; can take ~30 s on a door panel.
     */
    public function rebuildPlan(Device $device, \App\Services\Devices\DeviceRebuildService $rebuild): JsonResponse
    {
        $this->authorize('update', $device);

        set_time_limit(120);

        try {
            return ApiResponse::success($rebuild->plan($device));
        } catch (DeviceException $e) {
            return ApiResponse::error("Could not read {$device->name}: {$e->getMessage()}", [], 502);
        }
    }

    /**
     * Starts the rebuild: queues the punch download and the backup-plan-fan-out chain.
     * Returns at once; progress is the terminal's command list (rule 11).
     */
    public function rebuild(Request $request, Device $device, \App\Services\Devices\DeviceRebuildService $rebuild): JsonResponse
    {
        $this->authorize('update', $device);

        $data = $request->validate(['remove_unlinked' => ['sometimes', 'boolean']]);

        set_time_limit(120);

        try {
            $commands = $rebuild->start($device, (bool) ($data['remove_unlinked'] ?? false));
        } catch (DeviceException $e) {
            return ApiResponse::error("Could not read {$device->name}: {$e->getMessage()}", [], 502);
        } catch (\DomainException $e) {
            return ApiResponse::error($e->getMessage(), [], 409);
        }

        return ApiResponse::success(
            ['command_ids' => array_map(fn ($c) => $c->id, $commands)],
            "Rebuild of {$device->name} queued: punches download first, then a full backup, then the changes, one person at a time.",
        );
    }

    /**
     * Refreshes fingerprint counts and card numbers for the WHOLE roster from this
     * terminal in ONE device conversation. That is what makes the employee list
     * device-sourced without opening a connection per row.
     *
     * The service's own message distinguishes "counts are unknown" from "counts are
     * zero" — see rule 10 — so it is passed through rather than rewritten here.
     */
    public function syncEnrollment(Device $device): JsonResponse
    {
        $this->authorize('sync', $device);

        set_time_limit(120);

        try {
            $result = $this->users->syncEnrollmentState($device);

            return ApiResponse::success($result['raw'], $result['message']);
        } catch (\Throwable $e) {
            return $this->deviceFailure($e, 'enrollment read', $device);
        }
    }

    public function importEmployees(Device $device): JsonResponse
    {
        $this->authorize('sync', $device);

        try {
            $result = $this->users->importFromDevice($device);

            return ApiResponse::success($result['raw'], $result['message']);
        } catch (\Throwable $e) {
            return $this->deviceFailure($e, 'employee import', $device);
        }
    }

    /**
     * Records a failed inline device read and turns it into the response.
     *
     * All three read actions failed identically and each spelled its own prefix,
     * so the messages drifted and none of them logged anything — a protocol bug
     * left nothing behind but a 502 and a sentence. This keeps the operator
     * message and the diagnostic entry in step, per rule 11.
     *
     * Catching \Throwable rather than only DeviceException is deliberate: a bug in
     * a codec is not a DeviceException, and it used to escape to the framework
     * handler as a bare 500 'Server error.'. It is still distinguished from a real
     * device failure — DeviceErrorReporter records the stack for one and the
     * protocol context() for the other, and says so on screen.
     */
    private function deviceFailure(\Throwable $e, string $operation, Device $device): JsonResponse
    {
        $message = $this->errors->report($e, $operation, $device);

        return ApiResponse::error(
            $message,
            $e instanceof DeviceException ? ['retryable' => $e->isRetryable()] : [],
            502,
        );
    }
}
