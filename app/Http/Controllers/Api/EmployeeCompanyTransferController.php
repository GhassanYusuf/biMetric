<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Department;
use App\Models\Device;
use App\Services\Employees\TerminalCompanyTransfer;
use App\Support\ApiResponse;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Employees → "Move terminal to company" (owner, 2026-11-02). See TerminalCompanyTransfer. */
class EmployeeCompanyTransferController extends Controller
{
    public function __construct(private readonly TerminalCompanyTransfer $transfer) {}

    /** What the move would do. Changes nothing. */
    public function preview(Request $request): JsonResponse
    {
        [$device, $company, $department] = $this->targets($request);

        return ApiResponse::success($this->transfer->preview($device, $company, $department), 'Preview.');
    }

    public function move(Request $request): JsonResponse
    {
        $request->validate(['include_assigned' => ['sometimes', 'boolean']]);
        [$device, $company, $department] = $this->targets($request);

        $result = $this->transfer->move($device, $company, $request->boolean('include_assigned'), $department);

        $where = $department ? "{$company->name} › {$department->name}" : $company->name;
        $message = match (true) {
            $result['moved'] === 0 && $result['placed'] === 0 => "Nobody moved: everyone on {$device->name} is already in {$where} or in another company.",
            $result['moved'] === 0 => '',
            default => sprintf('Moved %d %s from %s into %s.', $result['moved'], $result['moved'] === 1 ? 'person' : 'people', $device->name, $where),
        };
        if ($result['placed'] > 0) {
            $message = trim($message.' '.sprintf('%d already in %s %s put in %s.', $result['placed'], $company->name, $result['placed'] === 1 ? 'was' : 'were', $department->name));
        }
        if ($result['skipped'] > 0) {
            $message .= sprintf(' %d already in another company %s left where they are.', $result['skipped'], $result['skipped'] === 1 ? 'was' : 'were');
        }

        return ApiResponse::success($result, $message);
    }

    /** @return array{0: Device, 1: Company, 2: ?Department} */
    private function targets(Request $request): array
    {
        // Its own permission since 2026-11-26 (System → Move terminal to company), was employees.update.
        abort_unless($request->user()->can('system.move-terminal'), 403, 'This action is unauthorized.');

        $data = $request->validate([
            'device_id' => ['required', 'integer', Rule::exists('devices', 'id')->where('tenant_id', Tenancy::id())],
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')->where('tenant_id', Tenancy::id())->whereNull('deleted_at')],
            // Optional (2026-11-15): a department OF THAT company.
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')
                ->where('tenant_id', Tenancy::id())->where('company_id', $request->integer('company_id'))->whereNull('deleted_at')],
        ], ['department_id.exists' => 'Choose a department of that company.']);

        return [
            Device::query()->findOrFail($data['device_id']),
            Company::query()->findOrFail($data['company_id']),
            ! empty($data['department_id']) ? Department::query()->findOrFail($data['department_id']) : null,
        ];
    }
}
