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

        $departments = Department::query()
            ->withCount('employees')
            ->with('manager:id,name')
            ->orderBy('name')
            ->get();

        // The tree's "Unassigned" node. Nobody else can count it: the employee list is
        // paginated and filtered, and a department count by definition excludes them.
        return ApiResponse::success(DepartmentResource::collection($departments), 'Departments retrieved.', [
            'unassigned_count' => Employee::query()->whereNull('department_id')->count(),
        ]);
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $this->authorize('create', Department::class);

        $department = Department::create($request->validated())->loadCount('employees');

        return ApiResponse::created(new DepartmentResource($department), 'Department created.');
    }

    public function show(Department $department): JsonResponse
    {
        $this->authorize('view', $department);

        $department->loadCount('employees')->load('manager:id,name');

        return ApiResponse::success(new DepartmentResource($department), 'Department retrieved.');
    }

    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $this->authorize('update', $department);

        $oldName = $department->name;
        $department->update($request->validated());

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

        $department->loadCount('employees');

        return ApiResponse::success(new DepartmentResource($department), 'Department updated.');
    }

    public function destroy(Department $department): JsonResponse
    {
        $this->authorize('delete', $department);

        /*
         * Refused while anything is still in it (2026-10-12). Departments are
         * soft-deleted, so the people would keep pointing at a node no screen lists,
         * and a company's departments would lose their company. Move them first.
         */
        $people = Employee::query()->where('department_id', $department->id)->count();
        $children = Department::query()->where('parent_id', $department->id)->count();
        if ($people > 0 || $children > 0) {
            $what = array_filter([
                $people > 0 ? sprintf('%d employee%s', $people, $people === 1 ? '' : 's') : null,
                $children > 0 ? sprintf('%d department%s', $children, $children === 1 ? '' : 's') : null,
            ]);

            return ApiResponse::error(
                sprintf('%s still has %s. Move them elsewhere first.', $department->name, implode(' and ', $what)),
                [],
                409,
            );
        }

        $department->delete();

        return ApiResponse::success(null, 'Department removed.');
    }
}
