<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreEmployeeRequest;
use App\Http\Requests\Api\V1\UpdateEmployeeRequest;
use App\Http\Resources\DeviceCommandResource;
use App\Http\Resources\EmployeeResource;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Services\Devices\DeviceCommandBus;
use App\Services\Integration\EmployeeDevicePusher;
use App\Services\Integration\EmployeeOffboarding;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Employees for outside systems (integration API v1).
 *
 * Differs from the SPA's controller in four ways an integration needs: a caller-supplied
 * `external_id` with an idempotent upsert, partial updates, device work that is QUEUED
 * and reported as command ids (rule 11), and a delete that can also clear terminals.
 * Everything else — validation, policies, resources — is the SPA's, so the two surfaces
 * cannot disagree about what an employee is (rule 13).
 */
class EmployeeController extends Controller
{
    private const WITH = ['department:id,name,parent_id', 'department.parent:id,name,parent_id', 'deviceMaps.device:id,name', 'accessLevels:id'];

    public function __construct(
        private readonly EmployeeDevicePusher $pusher,
        private readonly EmployeeOffboarding $offboarding,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $request->validate([
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $employees = Employee::query()
            ->with(self::WITH)
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $q->where(fn ($s) => $s->where('name', 'like', $term)
                    ->orWhere('employee_code', 'like', $term)
                    ->orWhere('external_id', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->when($request->filled('external_id'), fn ($q) => $q->where('external_id', $request->string('external_id')))
            ->when($request->filled('employee_code'), fn ($q) => $q->where('employee_code', $request->string('employee_code')))
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('resigned') && Employee::hasEmploymentColumns(), fn ($q) => $request->boolean('resigned')
                ? $q->whereNotNull('resigned_at')
                : $q->whereNull('resigned_at'))
            ->when($request->filled('employment_type') && Employee::hasEmploymentColumns(), fn ($q) => $q->where('employment_type', $request->string('employment_type')))
            ->when($request->filled('device_id'), fn ($q) => $q->whereHas(
                'deviceMaps',
                fn ($m) => $m->where('device_id', $request->integer('device_id'))->whereNull('valid_to'),
            ))
            // Incremental sync: everything touched since the caller last looked.
            ->when($request->filled('updated_since'), fn ($q) => $q->where('updated_at', '>=', $request->date('updated_since')->utc()))
            // Stable under inserts, so paging a changing table does not skip or repeat rows.
            ->orderBy('id')
            ->paginate($request->integer('per_page', 50));

        return ApiResponse::success(EmployeeResource::collection($employees), 'Employees retrieved.');
    }

    public function show(Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        return ApiResponse::success(new EmployeeResource($employee->load(self::WITH)), 'Employee retrieved.');
    }

    public function showByExternalId(string $externalId): JsonResponse
    {
        $employee = Employee::query()->where('external_id', $externalId)->firstOrFail();

        return $this->show($employee);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $this->authorize('create', Employee::class);

        $employee = $this->create($request, $request->employeeData());

        return ApiResponse::created(
            new EmployeeResource($employee->load(self::WITH)),
            'Employee added.',
            $this->devicePart($employee, $request->input('push_to_device_ids', [])),
        );
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        $wasResigned = $employee->isResigned();
        $this->save($request, $employee, $request->employeeData());

        return ApiResponse::success(
            new EmployeeResource($employee->fresh()->load(self::WITH)),
            'Employee updated.',
            $this->savedDevicePart($employee, $wasResigned, $request->input('push_to_device_ids', []), $request->boolean('push_to_mapped_devices')),
        );
    }

    /**
     * Create-or-update keyed on the CALLER'S id — what makes a nightly "push everyone"
     * job safe to run twice. The URL's id wins over any `external_id` in the body.
     */
    public function upsert(Request $request, string $externalId): JsonResponse
    {
        $request->merge(['external_id' => $externalId]);
        $employee = Employee::query()->where('external_id', $externalId)->first();

        if ($employee === null) {
            $this->authorize('create', Employee::class);
            $form = $this->resolveForm(StoreEmployeeRequest::class, $request);
            $created = $this->create($form, $form->employeeData());

            return ApiResponse::created(
                new EmployeeResource($created->load(self::WITH)),
                'Employee added.',
                ['created' => true] + $this->devicePart($created, $request->input('push_to_device_ids', [])),
            );
        }

        $this->authorize('update', $employee);

        // The update rules look the employee up on the route (to ignore its own code in
        // the uniqueness check), and this route is keyed by external id — so hand it over.
        $request->route()->setParameter('employee', $employee);
        $form = $this->resolveForm(UpdateEmployeeRequest::class, $request);
        $wasResigned = $employee->isResigned();
        $this->save($form, $employee, $form->employeeData());

        return ApiResponse::success(
            new EmployeeResource($employee->fresh()->load(self::WITH)),
            'Employee updated.',
            ['created' => false] + $this->savedDevicePart($employee, $wasResigned, $request->input('push_to_device_ids', []), $request->boolean('push_to_mapped_devices')),
        );
    }

    /**
     * Deletes the record. With `remove_from_devices=true` it ALSO queues removal from
     * every terminal they are on and answers 202: the record is deleted only once those
     * removals succeed (see EmployeeOffboarding for why that order, and what happens when
     * one fails).
     */
    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('delete', $employee);

        return $this->offboard($employee, $request->boolean('remove_from_devices'));
    }

    /**
     * Delete by Personnel ID (`employee_code`), from the system AND every terminal
     * (owner, 2026-10-21: "an endpoint to delete an employee from the system and from
     * the biometric terminals … for the integration with another system"). Also routed
     * as the SPA's `DELETE /api/employees/by-code/{code}`, so the two cannot differ.
     *
     * Always takes the person off the terminals. It is not optional here because a
     * record-only delete by code did exactly the wrong thing on 2026-10-21 (casebook
     * DC-025): the record went, the terminals kept user 1000, and the system had no
     * way left to find it. Removing the record alone remains `DELETE /employees/{id}`.
     *
     * The code is matched EXACTLY, as text: "000659843" and "659843" are different
     * people's codes. Unique per company and tenant-scoped, so it finds at most one
     * person, in the caller's own company.
     */
    public function destroyByCode(string $code): JsonResponse
    {
        $employee = Employee::query()->where('employee_code', $code)->first();

        if ($employee === null) {
            return ApiResponse::error("No employee has Personnel ID {$code}.", [], 404);
        }

        $this->authorize('delete', $employee);

        return $this->offboard($employee, true);
    }

    /**
     * Delete, optionally taking the person off their terminals first (EmployeeOffboarding).
     * 200 when the row is gone; 202 when it is kept, inactive, until every terminal
     * confirms — or because a terminal could not be written to. `data` names who it was:
     * the caller addressed them by a number, and the row may no longer exist to look up.
     */
    private function offboard(Employee $employee, bool $fromDevices): JsonResponse
    {
        $who = ['id' => $employee->id, 'employee_code' => $employee->employee_code, 'name' => $employee->fullName()];

        $outcome = $this->offboarding->remove($employee, $fromDevices);

        $meta = [
            'deleted' => $outcome['deleted'],
            'device_commands' => array_map(fn ($c) => (new DeviceCommandResource($c))->resolve(), $outcome['commands']),
            'skipped_devices' => $outcome['skipped'],
        ];

        if ($outcome['deleted']) {
            return ApiResponse::success($who, 'Employee removed.', $meta);
        }

        $message = $outcome['pending']
            ? 'Removal from the terminals is queued. The employee is deactivated now and deleted once every terminal confirms.'
            : 'No terminal could be written to, so the employee was deactivated but NOT deleted — they may still be enrolled on a terminal.';

        return ApiResponse::success($who, $message, $meta, 202);
    }

    /** Queues one employee onto one terminal. */
    public function pushToDevice(Employee $employee, Device $device): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        $result = $this->pusher->push($employee, [$device]);

        if ($result['commands'] === []) {
            return ApiResponse::error($result['skipped'][0]['reason'], ['device_id' => [$result['skipped'][0]['reason']]], 422);
        }

        return ApiResponse::success(
            new DeviceCommandResource($result['commands'][0]),
            "Queued {$employee->name} for {$device->name}. Follow the command for its outcome.",
            [],
            202,
        );
    }

    /** Queues removal of one employee from one terminal. The record is kept. */
    public function removeFromDevice(Employee $employee, Device $device, DeviceCommandBus $bus): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        if ($refusal = $device->userWriteRefusal()) {
            return ApiResponse::error($refusal, ['device_id' => [$refusal]], 422);
        }

        $command = $bus->dispatch($device, DeviceCommand::REMOVE_USER, ['employee_id' => $employee->id]);

        return ApiResponse::success(
            new DeviceCommandResource($command),
            "Queued removal of {$employee->name} from {$device->name}.",
            [],
            202,
        );
    }

    /** @param array<string,mixed> $data */
    private function create(Request $request, array $data): Employee
    {
        return DB::transaction(function () use ($request, $data) {
            $employee = Employee::create($data);
            $this->syncAccessLevels($request, $employee);

            return $employee;
        });
    }

    /** @param array<string,mixed> $data */
    private function save(Request $request, Employee $employee, array $data): void
    {
        DB::transaction(function () use ($request, $employee, $data) {
            $employee->update($data);
            $this->syncAccessLevels($request, $employee);
        });
    }

    /** Omitted means unchanged — the same rule as the SPA's form. */
    private function syncAccessLevels(Request $request, Employee $employee): void
    {
        if ($request->has('access_level_ids')) {
            $employee->accessLevels()->sync($request->input('access_level_ids') ?? []);
        }
    }

    /**
     * The device half of an update. A save that RESIGNS the employee (2026-10-12) takes
     * them off every terminal instead of pushing — the owner's rule is that a person who
     * has resigned is taken off the system — and any push asked for in the same request
     * is ignored rather than undoing it. An already-resigned employee is never pushed.
     *
     * @param  list<int>  $deviceIds
     */
    private function savedDevicePart(Employee $employee, bool $wasResigned, array $deviceIds, bool $mapped): array
    {
        $employee->refresh();

        if (! $employee->isResigned()) {
            return $this->devicePart($employee, $deviceIds, $mapped);
        }

        $result = $wasResigned
            ? ['commands' => [], 'skipped' => []]
            : $this->offboarding->takeOffTerminals($employee);

        return [
            'device_commands' => array_map(fn ($c) => (new DeviceCommandResource($c))->resolve(), $result['commands']),
            'skipped_devices' => $result['skipped'],
        ];
    }

    /**
     * Queues the device writes a save asked for and describes them, for `meta`.
     *
     * @param  list<int>  $deviceIds
     * @return array{device_commands:list<array<string,mixed>>, skipped_devices:list<array<string,mixed>>}
     */
    private function devicePart(Employee $employee, array $deviceIds, bool $mapped = false): array
    {
        $devices = collect();

        if ($deviceIds !== []) {
            $devices = $devices->merge(Device::query()->whereIn('id', $deviceIds)->get());
        }
        if ($mapped) {
            $devices = $devices->merge($this->pusher->mappedDevices($employee));
        }

        $result = $this->pusher->push($employee, $devices->unique('id'));

        return [
            'device_commands' => array_map(fn ($c) => (new DeviceCommandResource($c))->resolve(), $result['commands']),
            'skipped_devices' => $result['skipped'],
        ];
    }

    /**
     * Runs a FormRequest by hand, for a route that could not inject it (the upsert
     * decides between create and update only after looking the employee up).
     *
     * @template T of \Illuminate\Foundation\Http\FormRequest
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function resolveForm(string $class, Request $request)
    {
        $form = $class::createFrom($request);
        $form->setContainer(app())->setRedirector(app('redirect'));
        $form->setRouteResolver(fn () => $request->route());
        $form->validateResolved();

        return $form;
    }
}
