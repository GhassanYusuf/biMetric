<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Department\StoreDepartmentRequest;
use App\Http\Requests\Api\Department\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Models\Employee;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class DepartmentController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Department::class);

        // Departments only, each with its company (2026-11-03). Companies, and the count of
        // people in no company, come from CompanyController.
        $departments = Department::query()
            ->withCount('employees')
            ->with(['manager:id,name', 'company:id,name'])
            ->when(request()->filled('company_id'), fn ($q) => $q->where('company_id', request()->integer('company_id')))
            ->orderBy('name')
            ->get();

        return ApiResponse::success(DepartmentResource::collection($departments), 'Departments retrieved.');
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $this->authorize('create', Department::class);

        $department = Department::create($request->validated())->loadCount('employees')->load('company:id,name');

        return ApiResponse::created(new DepartmentResource($department), 'Department created.');
    }

    public function show(Department $department): JsonResponse
    {
        $this->authorize('view', $department);

        $department->loadCount('employees')->load(['manager:id,name', 'company:id,name']);

        return ApiResponse::success(new DepartmentResource($department), 'Department retrieved.');
    }

    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $this->authorize('update', $department);

        $oldName = $department->name;
        $oldCompany = (int) $department->company_id;
        $department->update($request->validated());

        // Moved to another company: its people move with it (a department decides the company).
        if ((int) $department->company_id !== $oldCompany) {
            Employee::query()->where('department_id', $department->id)->update(['company_id' => $department->company_id]);
        }

        /*
         * A rename reaches the employees' free-text `department` column too — reports,
         * exports and the punch list print it (2026-10-12). Only where it still holds
         * the OLD name: an import that wrote its own text there keeps it.
         */
        if ($department->name !== $oldName) {
            Employee::query()
                ->where('department_id', $department->id)
                ->where('department', $oldName)
                ->update(['department' => $department->name]);
        }

        $department->loadCount('employees')->load('company:id,name');

        return ApiResponse::success(new DepartmentResource($department), 'Department updated.');
    }

    public function destroy(Department $department): JsonResponse
    {
        $this->authorize('delete', $department);

        /*
         * Refused while anything is still in it (2026-10-12). Departments are
         * soft-deleted, so the people would keep pointing at a department no screen
         * lists. Move them first.
         */
        $people = Employee::query()->where('department_id', $department->id)->count();
        if ($people > 0) {
            return ApiResponse::error(
                sprintf('%s still has %d employee%s. Move them elsewhere first.', $department->name, $people, $people === 1 ? '' : 's'),
                [],
                409,
            );
        }

        $department->delete();

        return ApiResponse::success(null, 'Department removed.');
    }
}
