<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Department\StoreDepartmentRequest;
use App\Http\Requests\Api\Department\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
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

        return ApiResponse::success(DepartmentResource::collection($departments), 'Departments retrieved.');
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $this->authorize('create', Department::class);

        $department = Department::create($request->validated());

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

        $department->update($request->validated());

        return ApiResponse::success(new DepartmentResource($department), 'Department updated.');
    }

    public function destroy(Department $department): JsonResponse
    {
        $this->authorize('delete', $department);

        $department->delete();

        return ApiResponse::success(null, 'Department removed.');
    }
}
