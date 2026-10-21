<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Employee\StoreEmployeeRequest;
use App\Http\Requests\Api\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Device;
use App\Models\Employee;
use App\Services\Attendance\AttendanceSummaryService;
use App\Services\Attendance\EmployeeReportService;
use App\Services\Devices\DeviceUserSyncService;
use App\Services\Devices\EnrollmentOrchestratorService;
use App\Services\Employees\EmployeeCodeAllocator;
use App\Services\Employees\EmployeeDevicePropagator;
use App\Services\Employees\EmployeeMergeService;
use App\Services\Employees\EmployeeRosterQuery;
use App\Services\Employees\FingerprintPusher;
use App\Services\Employees\FingerprintVault;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Promoseven\Biometric\Exception\DeviceException;
use Promoseven\Biometric\Exception\UnsupportedCommandException;

class EmployeeController extends Controller
{
    /** The department and its ancestors, so the resource can name the company. */
    private const DEPARTMENT = ['department:id,name,parent_id', 'department.parent:id,name,parent_id'];

    /**
     * The device actions below run INLINE rather than through DeviceCommandBus,
     * because each is triggered by an operator standing at the terminal with the
     * employee and watching for the result — a queue receipt is useless to them.
     * The queued equivalents exist for automation and bulk work; see
     * DeviceEnrollmentController.
     */
    public function __construct(
        private readonly DeviceUserSyncService $users,
        private readonly EnrollmentOrchestratorService $enrollment,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $employees = EmployeeRosterQuery::build($request)
            ->withCount('attendanceLogs')
            // `deviceMaps` answers "enrolled on which terminal?" — the flat
            // enrollment columns on employees cannot, once there is more than
            // one device.
            ->with([...self::DEPARTMENT, 'deviceMaps.device:id,name', 'accessLevels:id', ...self::copyRelations()])
            // The filters themselves live in EmployeeRosterQuery, shared with the
            // roster's PDF / Excel export so the file lists who the screen lists.
            ->paginate($this->perPage($request));

        return ApiResponse::success(
            EmployeeResource::collection($employees),
            'Employees retrieved.',
        );
    }

    /**
     * Page size for the roster list, bounded at both ends.
     *
     * The cap matters: this query eager-loads department, device maps, access
     * levels and every copy relation, so `?per_page=100000` would ask the server
     * to hydrate the entire company — and its relations — into one JSON
     * response. The roster is browsed page by page, so a large request buys the
     * caller nothing they could not simply page through.
     */
    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 25), 1), 200);
    }

    /**
     * Suggests the lowest unused employee code — the first gap in the numeric
     * codes already registered, or one past the highest when there is no gap.
     *
     * Gap-filling rather than always incrementing: codes get freed when people
     * leave, and the terminal keys attendance by this code, so reusing a free low
     * number keeps them short and typeable at the keypad.
     *
     * Only numeric codes are considered. Non-numeric ones (e.g. "TEMP-4") are
     * ignored rather than guessed at, so they never collide with a suggestion.
     */
    public function nextCode(): JsonResponse
    {
        $this->authorize('create', Employee::class);

        ['code' => $suggestion, 'taken' => $taken] = app(EmployeeCodeAllocator::class)->next();

        return ApiResponse::success(
            [
                'code' => (string) $suggestion,
                'taken_count' => $taken,
            ],
            "Next free employee code is {$suggestion}.",
        );
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $this->authorize('create', Employee::class);

        $data = $request->safe()->except(['photo', 'access_level_ids']);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('employees', 'public');
        }

        $employee = DB::transaction(function () use ($request, $data) {
            $employee = Employee::create($data);
            $this->syncAccessLevels($request, $employee);

            return $employee;
        });

        return ApiResponse::created(new EmployeeResource($employee->fresh()->load([...self::DEPARTMENT, 'accessLevels:id', ...self::copyRelations()])), 'Employee added.');
    }

    public function show(Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        $employee->loadCount('attendanceLogs')->load([...self::DEPARTMENT, 'deviceMaps.device:id,name', ...self::copyRelations()]);

        return ApiResponse::success(new EmployeeResource($employee), 'Employee retrieved.');
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        $data = $request->safe()->except(['photo', 'access_level_ids']);

        if ($request->hasFile('photo')) {
            if ($employee->photo_path) {
                Storage::disk('public')->delete($employee->photo_path);
            }
            $data['photo_path'] = $request->file('photo')->store('employees', 'public');
        }

        DB::transaction(function () use ($request, $employee, $data) {
            $employee->update($data);
            $this->syncAccessLevels($request, $employee);
        });

        return ApiResponse::success(new EmployeeResource($employee->fresh()->load([...self::DEPARTMENT, 'accessLevels:id', ...self::copyRelations()])), 'Employee updated.');
    }

    /**
     * The stored-fingerprint slots for the resource, slim columns only (never the
     * ciphertext). Empty until the 2026_09_24_100000 migration has run, so the roster
     * keeps working on a database that has not been migrated yet — the resource then
     * simply omits `stored_fingerprint_slots`.
     *
     * @return array<int,string>
     */
    private static function copyRelations(): array
    {
        static $ready = null;
        $ready ??= Schema::hasColumn('biometric_templates', 'template_sha1');

        return $ready ? ['biometricTemplates:id,employee_id,finger_slot,template_sha1'] : [];
    }

    /**
     * Access levels are stored in THIS DATABASE ONLY (2026-09-23) — nothing is
     * written to a C3 panel. Omitted means unchanged, so an older client that does
     * not know about the tab cannot wipe someone's levels by saving.
     */
    private function syncAccessLevels(Request $request, Employee $employee): void
    {
        if ($request->has('access_level_ids')) {
            $employee->accessLevels()->sync($request->validated('access_level_ids') ?? []);
        }
    }

    /**
     * Sets or replaces the photo on its own, so the JSON save keeps working as it did
     * — a file cannot ride a JSON PUT, and PHP does not parse multipart PUT bodies.
     * Stored in this app only; no terminal receives it.
     */
    public function uploadPhoto(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        $request->validate(['photo' => ['required', 'image', 'max:4096']]);

        if ($employee->photo_path) {
            Storage::disk('public')->delete($employee->photo_path);
        }
        $employee->forceFill(['photo_path' => $request->file('photo')->store('employees', 'public')])->save();

        return ApiResponse::success(new EmployeeResource($employee->fresh()->load([...self::DEPARTMENT, 'accessLevels:id'])), 'Photo saved.');
    }

    public function removePhoto(Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        if ($employee->photo_path) {
            Storage::disk('public')->delete($employee->photo_path);
            $employee->forceFill(['photo_path' => null])->save();
        }

        return ApiResponse::success(new EmployeeResource($employee->fresh()->load([...self::DEPARTMENT, 'accessLevels:id'])), 'Photo removed.');
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $this->authorize('delete', $employee);

        $employee->delete();

        return ApiResponse::success(null, 'Employee removed.');
    }

    /** Per-day attendance breakdown + payroll hours for one employee. */
    public function attendanceSummary(Request $request, Employee $employee, AttendanceSummaryService $summary): JsonResponse
    {
        $this->authorize('view', $employee);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $data = $summary->summary($employee, $validated['from'] ?? null, $validated['to'] ?? null);

        return ApiResponse::success($data, 'Attendance summary generated.');
    }

    /**
     * The employee report screen's data (2026-09-27): one period plus the one before
     * it, and the last year for the calendar. Read-only; see EmployeeReportService.
     */
    public function report(Request $request, Employee $employee, EmployeeReportService $reports): JsonResponse
    {
        $this->authorize('view', $employee);

        $validated = $request->validate([
            'period' => ['nullable', 'integer', 'in:'.implode(',', EmployeeReportService::PERIODS)],
            'to' => ['nullable', 'date'],
        ]);

        return ApiResponse::success(
            $reports->report($employee, (int) ($validated['period'] ?? 30), $validated['to'] ?? null),
            'Employee report generated.',
        );
    }

    /**
     * Writes this employee onto SEVERAL terminals, IMMEDIATELY.
     *
     * The counterpart to saving them. `update()` deliberately still only touches the
     * database — a name correction is not a hardware operation and must not silently
     * become one — so the screen calls this straight afterwards with the terminals
     * the operator chose.
     *
     * SYNCHRONOUS BY INSTRUCTION (2026-09-15). Rule 11 queues device writes, and its
     * reasons hold: retries, audit rows, no request timeout. What a queue cannot give
     * is an answer while the operator is standing at the screen, and that is the whole
     * point of pressing Push. The time limit is raised because this now waits on
     * hardware; the propagator attempts every device in its own try, so one
     * unreachable terminal cannot cancel the writes to the others.
     *
     * The response separates written / skipped / failed rather than a pass-fail, and
     * the message says all three. A partial result is the normal case here — two of
     * this estate's six terminals are C3 panels that cannot write users at all — and
     * a sentence naming only the successes would be a lie by omission.
     */
    public function propagate(Request $request, Employee $employee, EmployeeDevicePropagator $propagator): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        // A resigned employee is kept off the terminals (2026-10-12). Clear the
        // resignation date first to put them back.
        if ($employee->isResigned()) {
            return ApiResponse::error("{$employee->name} has resigned, so is not put on terminals. Clear the resignation date first.", [], 409);
        }

        $data = $request->validate([
            'device_ids' => ['required', 'array', 'min:1'],
            /*
             * Existence is checked against the tenant-scoped model below rather than
             * with Rule::exists: `devices` carries a tenant scope, and a bare exists
             * rule would confirm another company's terminal id as valid.
             */
            'device_ids.*' => ['integer'],
            // Also make each terminal's user ID equal the employee code. Explicit, never
            // implied by a push: it changes the number attendance is recorded under.
            'to_employee_code' => ['sometimes', 'boolean'],
        ]);

        $devices = Device::query()->whereIn('id', $data['device_ids'])->orderBy('name')->get();

        if ($devices->isEmpty()) {
            return ApiResponse::error('None of those terminals exist here.', ['device_ids' => ['Unknown device.']], 422);
        }

        // Each device is a session, a read and a write. Four of them on a remote
        // terminal outlive the default limit, and timing out mid-write is the one
        // outcome worth paying to avoid.
        set_time_limit(180);

        $result = $propagator->push($employee, $devices, (bool) ($data['to_employee_code'] ?? false));

        return ApiResponse::success($result, $this->describePropagation($employee, $result));
    }

    /**
     * One sentence covering all three outcomes, because a partial result is the
     * normal case and a message mentioning only the successes would mislead.
     *
     * @param  array{written:list<array<string,mixed>>, skipped:list<array<string,mixed>>, failed:list<array<string,mixed>>}  $result
     */
    private function describePropagation(Employee $employee, array $result): string
    {
        $written = count($result['written']);

        $sentence = $written === 0
            ? "{$employee->name} was not written to any terminal."
            : sprintf(
                'Wrote %s to %d terminal%s: %s.',
                $employee->name,
                $written,
                $written === 1 ? '' : 's',
                implode(', ', array_column($result['written'], 'device_name')),
            );

        // Failures first: they are the half somebody has to act on.
        if ($result['failed'] !== []) {
            $sentence .= ' FAILED on '.implode('; ', array_map(
                fn (array $f) => "{$f['device_name']} — {$f['error']}",
                $result['failed'],
            )).'.';
        }

        if ($result['skipped'] !== []) {
            $sentence .= ' Skipped '.implode('; ', array_map(
                fn (array $s) => "{$s['device_name']} — {$s['reason']}",
                $result['skipped'],
            )).'.';
        }

        return $sentence;
    }

    /**
     * Folds duplicate employment records into one.
     *
     * WHY THIS IS MANUAL. `DuplicateEmployeeFinder` catches the import artefact — the
     * same name at the SAME PIN on several terminals — and that is the only shape it
     * can be sure about. The commoner human case is one person enrolled under
     * DIFFERENT pins on different devices, which no evidence in this system can
     * distinguish from two colleagues who share a name. Only somebody who knows the
     * people can say, so the endpoint takes their decision and does not second-guess
     * it.
     *
     * NO DEVICE IS CONTACTED. Each terminal keeps its own user, PIN, fingerprints and
     * card exactly as they are; what moves is which employee row those per-device
     * mappings point at. That is what makes it safe to run against terminals that are
     * read-only or unadopted, and why a wrong merge destroys no enrollment.
     *
     * IT IS NOT UNDOABLE from inside the app — the folded rows are deleted and their
     * attendance now points elsewhere. Authorised as `delete`, because that is what it
     * ultimately does to the records being folded in.
     */
    public function merge(Request $request, EmployeeMergeService $merge): JsonResponse
    {
        $data = $request->validate([
            'keep_id' => ['required', 'integer'],
            'merge_ids' => ['required', 'array', 'min:1'],
            'merge_ids.*' => ['integer'],
        ]);

        // Resolved through the tenant-scoped model, so another company's employee id
        // cannot be named here — the service refuses a cross-tenant merge as well,
        // but this refuses it before anything is loaded.
        $keeper = Employee::findOrFail($data['keep_id']);
        $folded = Employee::whereIn('id', array_diff($data['merge_ids'], [$keeper->id]))->get();

        $this->authorize('update', $keeper);

        foreach ($folded as $employee) {
            $this->authorize('delete', $employee);
        }

        if ($folded->isEmpty()) {
            return ApiResponse::error(
                'Nothing to merge — pick at least one other record.',
                ['merge_ids' => ['No other employee was named.']],
                422,
            );
        }

        $result = $merge->merge($keeper->id, $folded->pluck('id')->all(), $request->user()?->id);

        $moved = ($result['rows']['attendance_logs'] ?? 0);

        return ApiResponse::success(
            $result + ['keep' => new EmployeeResource($keeper->fresh()->loadCount('attendanceLogs')->load('deviceMaps.device'))],
            sprintf(
                'Merged %d record%s into %s. %s punch%s and %d terminal mapping%s now belong to them.',
                $result['merged'],
                $result['merged'] === 1 ? '' : 's',
                $keeper->name,
                number_format($moved),
                $moved === 1 ? '' : 'es',
                $result['rows']['employee_device_maps'] ?? 0,
                ($result['rows']['employee_device_maps'] ?? 0) === 1 ? '' : 's',
            ),
        );
    }

    public function pushToDevice(Employee $employee, Device $device): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        // A resigned employee is kept off the terminals (2026-10-12). Clear the
        // resignation date first to put them back.
        if ($employee->isResigned()) {
            return ApiResponse::error("{$employee->name} has resigned, so is not put on terminals. Clear the resignation date first.", [], 409);
        }

        try {
            $result = $this->users->pushEmployee($device, $employee);

            return ApiResponse::success($result['raw'], $result['message']);
        } catch (DeviceException $e) {
            return ApiResponse::error("Failed to push to device: {$e->getMessage()}", [], 502);
        }
    }

    /**
     * Administrator or normal user on one terminal (2026-10-18). Synchronous like the
     * other per-terminal writes on this screen — one user record, read back — so the
     * operator sees the confirmed result on the button they pressed.
     */
    public function setDevicePrivilege(Request $request, Employee $employee, Device $device): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        $data = $request->validate(['admin' => ['required', 'boolean']]);

        try {
            $result = $this->users->setPrivilege($device, $employee, (bool) $data['admin']);

            return ApiResponse::success($result['raw'], $result['message']);
        } catch (DeviceException $e) {
            return ApiResponse::error("Could not change the privilege: {$e->getMessage()}", [], 502);
        } catch (\DomainException $e) {
            return ApiResponse::error($e->getMessage(), [], 409);
        }
    }

    public function removeFromDevice(Employee $employee, Device $device): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        try {
            $result = $this->users->removeEmployee($device, $employee);

            return ApiResponse::success($result['raw'], $result['message']);
        } catch (DeviceException $e) {
            return ApiResponse::error("Failed to remove from device: {$e->getMessage()}", [], 502);
        } catch (\DomainException $e) {
            // Refused on purpose — e.g. their fingerprints could not be saved first.
            return ApiResponse::error($e->getMessage(), [], 409);
        }
    }

    /**
     * Frees a user ID held on the terminal by a record no employee is linked to, and
     * gives it to this employee (DeviceUserSyncService::releaseUnlinkedNumber). The
     * button on a red "already in use" push row; the record and its fingerprints are
     * saved before anything is deleted. Synchronous like the push it unblocks.
     */
    public function releaseNumber(Request $request, Employee $employee, Device $device): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        $data = $request->validate([
            'device_user_id' => ['required', 'string', 'max:24'],
            // The operator says the record IS this employee: its fingers and punches
            // become theirs before it is removed (2026-10-11, "ali-it" was Ali).
            'same_person' => ['sometimes', 'boolean'],
        ]);

        set_time_limit(120);

        try {
            $result = $this->users->releaseUnlinkedNumber($device, $data['device_user_id'], $employee, (bool) ($data['same_person'] ?? false));

            return ApiResponse::success(['snapshot' => $result['snapshot']], $result['message']);
        } catch (DeviceException $e) {
            return ApiResponse::error("Could not free the number on {$device->name}: {$e->getMessage()}", [], 502);
        } catch (\DomainException $e) {
            return ApiResponse::error($e->getMessage(), [], 409);
        }
    }

    /**
     * Reads this employee's fingerprints from the terminals they are on INTO THE
     * SYSTEM (FingerprintVault), and nothing else (owner's request, 2026-09-24).
     * Read-only on the terminals; one row per terminal in the reply.
     */
    public function readFingerprints(Request $request, Employee $employee, FingerprintVault $vault): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        $data = $request->validate([
            'device_ids' => ['sometimes', 'array'],
            'device_ids.*' => ['integer'],
        ]);

        set_time_limit(120);

        try {
            $rows = $vault->captureEmployee($employee, $data['device_ids'] ?? null);
        } catch (\DomainException $e) {
            return ApiResponse::error($e->getMessage(), [], 503);
        }

        $read = array_filter($rows, fn ($r) => $r['status'] === 'read');
        $slots = collect($read)->flatMap(fn ($r) => $r['slots'])->unique()->count();
        $message = $rows === []
            ? "{$employee->name} is not on any terminal, so there is nothing to read."
            : "Read {$employee->name}'s fingerprints from ".count($read).' of '.count($rows)
                ." terminal(s): {$slots} finger(s) now stored in the system.";

        return ApiResponse::success([
            'terminals' => $rows,
            'stored_fingerprint_slots' => $vault->storedSlots($employee->id),
        ], $message);
    }

    /**
     * Writes this employee's stored fingerprints to terminals they are on where those
     * fingers are missing (FingerprintPusher, 2026-09-24). Add-only: a finger a
     * terminal already holds is never overwritten. One row per terminal.
     */
    public function pushFingerprints(Request $request, Employee $employee, FingerprintPusher $pusher): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        $data = $request->validate([
            'device_ids' => ['required', 'array', 'min:1'],
            'device_ids.*' => ['integer'],
        ]);

        set_time_limit(180);

        $devices = Device::query()->whereIn('id', $data['device_ids'])->orderBy('name')->get();

        try {
            $rows = $pusher->push($employee, $devices);
        } catch (\DomainException $e) {
            return ApiResponse::error($e->getMessage(), [], 503);
        }

        $written = array_filter($rows, fn ($r) => $r['status'] === 'written');
        $failed = array_filter($rows, fn ($r) => $r['status'] === 'failed');
        $fingers = collect($written)->sum(fn ($r) => count($r['slots']));

        $message = $written !== []
            ? "Wrote {$fingers} finger(s) for {$employee->name} to ".count($written).' terminal(s).'
            : "No fingerprints needed writing for {$employee->name}.";
        if ($failed !== []) {
            $message .= ' Failed on '.implode(', ', array_column($failed, 'device_name')).'.';
        }

        return ApiResponse::success(['terminals' => $rows], $message);
    }

    /**
     * Live read of this employee's enrollment on a terminal — which finger slots
     * are filled and whether a card is registered. Powers the hand picker, so it
     * runs on opening the enrollment dialog rather than on any list render.
     */
    public function enrollment(Employee $employee, Device $device): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        set_time_limit(60);

        try {
            return ApiResponse::success(
                $this->users->readEnrollment($device, $employee),
                "Read {$employee->name}'s enrollment from {$device->name}.",
            );
        } catch (DeviceException $e) {
            return ApiResponse::error("Could not read enrollment: {$e->getMessage()}", [], 502);
        }
    }

    /**
     * Puts the terminal into live fingerprint-capture mode for this employee.
     * The device beeps as soon as the trigger lands (so the person standing at
     * it knows to place a finger), then this request blocks until a template is
     * captured or the device times out — hence the raised time limit.
     */
    public function enrollFingerprint(Request $request, Employee $employee, Device $device): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        $data = $request->validate([
            'finger_index' => ['required', 'integer', 'min:0', 'max:9'],
        ]);

        // The service waits ~30s for a finger; don't let PHP kill it first.
        set_time_limit(90);

        try {
            $result = $this->enrollment->captureFingerprint($device, $employee, (int) $data['finger_index']);

            // A capture that did not produce a template is the DEVICE'S answer —
            // nobody presented a finger, or it could not be read — not a transport
            // fault. 422 with the service's own wording, never a 5xx.
            if (! ($result['raw']['success'] ?? false)) {
                return ApiResponse::error($result['message'], $result['raw'], 422);
            }

            return ApiResponse::success($result['raw'], $result['message']);
        } catch (DeviceException $e) {
            return ApiResponse::error("Fingerprint enrollment failed: {$e->getMessage()}", [], 502);
        }
    }

    /**
     * Clears the employee's card on the terminal, keeping their record and
     * fingerprints. The card lives in one field on the user record, so this
     * rewrites that record with card 0 rather than deleting anything.
     */
    public function deleteCard(Employee $employee, Device $device): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        set_time_limit(90);

        try {
            $result = $this->enrollment->clearCard($device, $employee);

            // No card to clear is a 422 with the service's wording: the request was
            // understood, there was simply nothing to do.
            if (($result['raw']['previous_card'] ?? null) === null) {
                return ApiResponse::error($result['message'], $result['raw'], 422);
            }

            return ApiResponse::success($result['raw'], $result['message']);
        } catch (DeviceException $e) {
            return ApiResponse::error("Could not remove the card: {$e->getMessage()}", [], 502);
        }
    }

    /**
     * Writes a typed card number onto the employee's record on the terminal —
     * the alternative to Scan card when the number is printed on the card.
     */
    public function assignCard(Request $request, Employee $employee, Device $device): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        $data = $request->validate([
            // Digits only: the record holds one unsigned 32-bit number. Leading zeros
            // as printed on the card ("0002857525") are the same number.
            'card_number' => ['required', 'string', 'regex:/^\d{1,10}$/'],
        ], [
            'card_number.regex' => 'Enter the card number as digits only, up to 10.',
        ]);

        set_time_limit(90);

        try {
            $result = $this->enrollment->assignCard($device, $employee, (int) $data['card_number']);

            return ApiResponse::success($result['raw'], $result['message']);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return ApiResponse::error($e->getMessage(), ['card_number' => [$e->getMessage()]], 422);
        } catch (UnsupportedCommandException $e) {
            return ApiResponse::error($e->getMessage(), [], 422);
        } catch (DeviceException $e) {
            return ApiResponse::error("Could not write the card: {$e->getMessage()}", [], 502);
        }
    }

    /**
     * Deletes one fingerprint from the terminal, keeping the employee's other
     * fingers and their card. Destructive: the person must re-enroll that finger.
     */
    public function deleteFingerprint(Employee $employee, Device $device, int $slot): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        // A bad slot is the caller's mistake, not the terminal's — 422, not 502.
        if ($slot < 0 || $slot > 9) {
            return ApiResponse::error('Invalid finger slot.', ['slot' => ['The slot must be between 0 and 9.']], 422);
        }

        set_time_limit(90);

        try {
            $result = $this->enrollment->deleteFingerprint($device, $employee, $slot);

            return ApiResponse::success($result['raw'], $result['message']);
        } catch (DeviceException $e) {
            return ApiResponse::error("Could not delete the fingerprint: {$e->getMessage()}", [], 502);
        }
    }

    /**
     * Puts the terminal into live card-read mode for this employee. Beeps on
     * trigger, then blocks until a card is swiped, writing the card number onto
     * their existing device record.
     */
    public function enrollCard(Employee $employee, Device $device): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        // The service waits ~20s for a card swipe.
        set_time_limit(90);

        try {
            $result = $this->enrollment->captureCard($device, $employee);

            if (! ($result['raw']['success'] ?? false)) {
                // Nobody tapped a card within the window. The device answered; it
                // just answered "nothing happened".
                return ApiResponse::error($result['message'], $result['raw'], 422);
            }

            return ApiResponse::success($result['raw'], $result['message']);
        } catch (DeviceException $e) {
            return ApiResponse::error("Card enrollment failed: {$e->getMessage()}", [], 502);
        }
    }
}
