<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Company\StoreCompanyRequest;
use App\Http\Requests\Api\Company\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Models\Employee;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Companies (2026-11-03). Until then a company was a department with no parent and was
 * served by DepartmentController; the rules carried over: same permissions
 * (departments.view / departments.manage), and a company still holding people or
 * departments is not deleted.
 */
class CompanyController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Company::class);

        // employees_count is everyone (the delete guard needs it); current_count leaves out
        // the resigned, as the employee list does by default (2026-11-03: the tree said 117
        // beside PROMOSEVEN while its list said 111).
        $companies = Company::query()
            ->withCount(['departments', 'employees', 'employees as current_count' => fn ($q) => $q->whereNull('resigned_at')])
            ->with('manager:id,name')
            ->orderBy('name')
            ->get();

        // The tree's "Unassigned" node: people in no company.
        return ApiResponse::success(CompanyResource::collection($companies), 'Companies retrieved.', [
            'unassigned_count' => Employee::query()->whereNull('company_id')->count(),
            'unassigned_current_count' => Employee::query()->whereNull('company_id')->whereNull('resigned_at')->count(),
        ]);
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $this->authorize('create', Company::class);

        $company = Company::create($request->validated())->loadCount(['departments', 'employees']);

        return ApiResponse::created(new CompanyResource($company), 'Company created.');
    }

    public function show(Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        return ApiResponse::success(
            new CompanyResource($company->loadCount(['departments', 'employees'])->load('manager:id,name')),
            'Company retrieved.',
        );
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $this->authorize('update', $company);

        $oldName = $company->name;
        $company->update($request->validated());

        // The free-text `department` of people placed directly in the company follows the name.
        if ($company->name !== $oldName) {
            Employee::query()
                ->where('company_id', $company->id)
                ->whereNull('department_id')
                ->where('department', $oldName)
                ->update(['department' => $company->name]);
        }

        return ApiResponse::success(new CompanyResource($company->loadCount(['departments', 'employees'])), 'Company updated.');
    }

    public function destroy(Company $company): JsonResponse
    {
        $this->authorize('delete', $company);

        $people = $company->employees()->count();
        $departments = $company->departments()->count();
        if ($people > 0 || $departments > 0) {
            $what = array_filter([
                $people > 0 ? sprintf('%d employee%s', $people, $people === 1 ? '' : 's') : null,
                $departments > 0 ? sprintf('%d department%s', $departments, $departments === 1 ? '' : 's') : null,
            ]);

            return ApiResponse::error(
                sprintf('%s still has %s. Move them elsewhere first.', $company->name, implode(' and ', $what)),
                [],
                409,
            );
        }

        $company->delete();

        return ApiResponse::success(null, 'Company removed.');
    }
}
