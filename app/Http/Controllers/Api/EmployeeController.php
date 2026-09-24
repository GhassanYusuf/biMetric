<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Employee\StoreEmployeeRequest;
use App\Http\Requests\Api\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Person;
use App\Services\Attendance\AttendanceSummaryService;
use App\Services\Devices\DeviceUserSyncService;
use App\Services\Devices\EnrollmentOrchestratorService;
use App\Services\Employees\EmployeeDevicePropagator;
use App\Services\Employees\EmployeeMergeService;
use App\Services\Employees\FingerprintVault;
use App\Support\ApiResponse;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Promoseven\Biometric\Exception\DeviceException;
use Promoseven\Biometric\Exception\UnsupportedCommandException;

class EmployeeController extends Controller
{
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

        $employees = Employee::query()
            ->withCount('attendanceLogs')
            // `deviceMaps` answers "enrolled on which terminal?" — the flat
            // enrollment columns on employees cannot, once there is more than
            // one device.
            ->with(['department:id,name', 'deviceMaps.device:id,name', 'person', 'accessLevels:id', ...self::copyRelations()])
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('employee_code', 'like', $term)
                        ->orWhere('department', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            /*
             * IDENTITY FILTERS. The one that matters operationally is
             * has_national_id=0: without a national id a person cannot be exported to
             * the HRMS, and after importing a roster that is most of them. Filtering
             * to exactly those is how an operator works through the backlog instead of
             * hunting for gaps in a list of three hundred.
             */
            ->when($request->filled('has_national_id'), function ($q) use ($request) {
                $request->boolean('has_national_id')
                    ? $q->whereHas('person', fn ($p) => $p->whereNotNull('national_id_hash'))
                    : $q->where(fn ($sub) => $sub
                        ->whereNull('person_id')
                        ->orWhereHas('person', fn ($p) => $p->whereNull('national_id_hash')));
            })
            // Enrolled on a specific terminal, via the live mapping — not the flat
            // snapshot columns, which cannot say WHICH device once there are two.
            ->when($request->filled('device_id'), fn ($q) => $q->whereHas(
                'deviceMaps',
                fn ($m) => $m->where('device_id', $request->integer('device_id'))->whereNull('valid_to'),
            ))
            // On no terminal at all: they cannot clock in anywhere.
            ->when($request->boolean('unenrolled'), fn ($q) => $q->whereDoesntHave(
                'deviceMaps',
                fn ($m) => $m->whereNull('valid_to'),
            ))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return ApiResponse::success(
            EmployeeResource::collection($employees),
            'Employees retrieved.',
        );
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

        $taken = Employee::query()
            ->pluck('employee_code')
            ->filter(fn ($code) => is_string($code) && ctype_digit($code))
            ->map(fn ($code) => (int) $code)
            ->filter(fn (int $code) => $code > 0)
            ->unique()
            ->sort()
            ->values();

        $suggestion = 1;
        foreach ($taken as $code) {
            if ($code > $suggestion) {
                break; // found the gap
            }
            $suggestion = $code + 1;
        }

        return ApiResponse::success(
            [
                'code' => (string) $suggestion,
                'taken_count' => $taken->count(),
            ],
            "Next free employee code is {$suggestion}.",
        );
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $this->authorize('create', Employee::class);

        $data = $request->safe()->except(['photo', 'access_level_ids', 'national_id']);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('employees', 'public');
        }

        $employee = DB::transaction(function () use ($request, $data) {
            $employee = Employee::create($data);
            $this->syncAccessLevels($request, $employee);
            $this->applyNationalIdFromForm($request, $employee);

            return $employee;
        });

        return ApiResponse::created(new EmployeeResource($employee->fresh()->load(['accessLevels:id', 'person', ...self::copyRelations()])), 'Employee added.');
    }

    /**
     * Resolves a national id to the employment that holds it, in THIS company.
     *
     * The one read the national id has, and deliberately a narrow one: the caller
     * must already know the number, and what comes back never contains one. Nothing
     * here decrypts anything — Person::findByNationalId() matches on the blind
     * index, so the ciphertext is not touched.
     *
     * EXACT MATCH ONLY, and it cannot be otherwise: a hash has no prefix, so there
     * is no "starts with" or "contains" search on a national id and there will not
     * be one — that would mean storing the document searchable in plaintext.
     * Punctuation and case do not matter, because the index is over the normalised
     * value (see Person::hashNationalId()).
     *
     * The intended caller is the Attendance screen: a national id is what an
     * operator is holding when somebody asks why their hours are short, and it is
     * the one key that is the same document across sister companies.
     */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $data = $request->validate([
            'national_id' => ['required', 'string', 'min:4', 'max:64'],
        ]);

        $person = Person::findByNationalId(trim($data['national_id']));

        /*
         * Person is NOT tenant-scoped — that is the whole point of it, one human
         * across sister companies. The EMPLOYMENT is, so resolving through the
         * relation is what stops this company's operator turning a number into a
         * name at another company. Do not "helpfully" widen this with
         * withoutGlobalScopes().
         *
         * Ordered by is_active so a rehire resolves to the current employment rather
         * than whichever row was written first.
         */
        $employee = $person?->employments()
            ->with(['department:id,name', 'deviceMaps.device:id,name', 'person'])
            ->withCount('attendanceLogs')
            ->orderByDesc('is_active')
            ->first();

        $this->auditLookup($request, $employee);

        if ($employee === null) {
            return ApiResponse::error(
                'No employee in this company holds that national ID. It has to match the whole number — '
                .'check it, or search by name or code on the Employees screen.',
                status: 404,
            );
        }

        return ApiResponse::success(new EmployeeResource($employee), "Found {$employee->name}.");
    }

    /**
     * Records that somebody resolved a national id — hit AND miss.
     *
     * The miss is the one that matters. Somebody guessing numbers against this
     * endpoint looks exactly like a run of misses from one user, and that pattern
     * only exists in the audit if the misses are written down. The throttle bounds
     * the rate; this is what makes the attempt visible afterwards.
     *
     * Neither the number NOR its hash is stored. An audit row is readable by more
     * people than the employees table is, and a hash you can compare a guess
     * against is still the document. Who asked, when, from where, and whether it
     * matched is the whole useful content.
     */
    private function auditLookup(Request $request, ?Employee $employee): void
    {
        AuditLog::create([
            'tenant_id' => Tenancy::id(),
            'user_id' => $request->user()?->id,
            'action' => 'employee.national_id_lookup',
            'auditable_type' => Employee::class,
            'auditable_id' => $employee?->id,
            'changes' => ['matched' => $employee !== null],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }

    /**
     * Sets or replaces this employment's national id — the HRMS join key.
     *
     * WRITE-ONLY BY DESIGN. There is no endpoint that reads a national id back; the
     * list returns only whether one exists and a masked tail. It is government id,
     * and a screen that can display them all is a screen that can leak them all.
     *
     * THE SAME ID ALREADY ON FILE IS A MATCH, NOT A CLASH. It means the same human is
     * also employed by a sister company, which is exactly what the Person layer exists
     * to express: they become one Person with two Employments, and punches at either
     * company's terminal resolve to one attendance account.
     */
    public function setNationalId(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        $data = $request->validate([
            'national_id' => ['required', 'string', 'min:4', 'max:64'],
        ]);

        if (($refusal = $this->assignNationalId($employee, trim($data['national_id']))) !== null) {
            return ApiResponse::error($refusal, status: 422);
        }

        return ApiResponse::success(
            new EmployeeResource($employee->fresh()->load(['department:id,name', 'deviceMaps.device:id,name', 'person'])),
            "National ID saved for {$employee->name}.",
        );
    }

    /**
     * Attaches a national id to the employee's Person, or says why not.
     *
     * One implementation for the three ways it arrives: the roster's inline editor
     * (setNationalId) and the add / edit form (store, update — 2026-09-24, where it
     * rides the same request so a refusal lands on the field and the whole save rolls
     * back with it). Returns null on success, the operator-facing refusal otherwise.
     */
    private function assignNationalId(Employee $employee, string $nationalId): ?string
    {
        $person = $employee->person;
        $existing = Person::findByNationalId($nationalId);

        /*
         * The same document on a SECOND employment within the SAME company is a typo,
         * not a discovery. One human cannot be employed twice by one employer, and
         * letting it through quietly shares a Person between two unrelated people —
         * after which the next edit to either rewrites the document for both.
         *
         * Across DIFFERENT companies it is the sister-company case and is exactly what
         * the Person layer is for, so that stays allowed.
         */
        if ($existing !== null && $existing->id !== $person?->id) {
            $clash = $existing->employments()
                ->withoutGlobalScopes()
                ->where('tenant_id', $employee->tenant_id)
                ->where('id', '!=', $employee->id)
                ->first();

            if ($clash !== null) {
                return "That national ID is already on {$clash->name} (code {$clash->employee_code}) in this company. "
                    .'Two employees here cannot be the same person — check the number, or correct the other record.';
            }
        }

        if ($person === null) {
            // Either attach to the human already holding this document — they work for a
            // sister company too — or create them.
            $person = $existing ?? Person::create([
                'full_name' => $employee->name,
                'status' => Person::UNVERIFIED,
            ]);

            $employee->forceFill(['person_id' => $person->id])->save();
        } elseif ($existing !== null && $existing->id !== $person->id) {
            // Two records we already believed were different humans turn out to be one.
            // Merging them moves employments and attendance history, so it is not
            // something to do silently as a side effect of typing in a field.
            return "That national ID already belongs to {$existing->full_name}. "
                .'If they are the same person, merge the two records rather than reassigning the ID.';
        }

        /*
         * Changing the ID on a Person who holds SEVERAL employments is a document
         * renewal and correctly applies to all of them — it is one human. The old
         * number is superseded, not overwritten, so payroll exports made under it still
         * reconcile (see Person::setNationalId()).
         */
        $person->setNationalId($nationalId);

        return null;
    }

    public function show(Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        $employee->loadCount('attendanceLogs')->load(['department:id,name', 'deviceMaps.device:id,name']);

        return ApiResponse::success(new EmployeeResource($employee), 'Employee retrieved.');
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        $data = $request->safe()->except(['photo', 'access_level_ids', 'national_id']);

        if ($request->hasFile('photo')) {
            if ($employee->photo_path) {
                Storage::disk('public')->delete($employee->photo_path);
            }
            $data['photo_path'] = $request->file('photo')->store('employees', 'public');
        }

        DB::transaction(function () use ($request, $employee, $data) {
            $employee->update($data);
            $this->syncAccessLevels($request, $employee);
            $this->applyNationalIdFromForm($request, $employee);
        });

        return ApiResponse::success(new EmployeeResource($employee->fresh()->load(['accessLevels:id', 'person', ...self::copyRelations()])), 'Employee updated.');
    }

    /**
     * Access levels are stored in THIS DATABASE ONLY (2026-09-23) — nothing is
     * written to a C3 panel. Omitted means unchanged, so an older client that does
     * not know about the tab cannot wipe someone's levels by saving.
     */
    /**
     * The form's national id, when one was typed. Omitted or blank means unchanged:
     * the stored value is never sent to the browser, so an untouched field must not
     * clear it. A refusal throws a field error, which rolls back the surrounding save.
     */
    private function applyNationalIdFromForm(Request $request, Employee $employee): void
    {
        $nationalId = trim((string) $request->validated('national_id'));

        if ($nationalId === '') {
            return;
        }

        if (($refusal = $this->assignNationalId($employee, $nationalId)) !== null) {
            throw ValidationException::withMessages(['national_id' => $refusal]);
        }
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

        return ApiResponse::success(new EmployeeResource($employee->fresh()->load('accessLevels:id')), 'Photo saved.');
    }

    public function removePhoto(Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        if ($employee->photo_path) {
            Storage::disk('public')->delete($employee->photo_path);
            $employee->forceFill(['photo_path' => null])->save();
        }

        return ApiResponse::success(new EmployeeResource($employee->fresh()->load('accessLevels:id')), 'Photo removed.');
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

        try {
            $result = $this->users->pushEmployee($device, $employee);

            return ApiResponse::success($result['raw'], $result['message']);
        } catch (DeviceException $e) {
            return ApiResponse::error("Failed to push to device: {$e->getMessage()}", [], 502);
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
