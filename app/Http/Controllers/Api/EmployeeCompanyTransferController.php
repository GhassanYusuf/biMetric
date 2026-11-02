<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
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
        [$device, $company] = $this->targets($request);

        return ApiResponse::success($this->transfer->preview($device, $company), 'Preview.');
    }

    public function move(Request $request): JsonResponse
    {
        $request->validate(['include_assigned' => ['sometimes', 'boolean']]);
        [$device, $company] = $this->targets($request);

        $result = $this->transfer->move($device, $company, $request->boolean('include_assigned'));

        $message = match (true) {
            $result['moved'] === 0 => "Nobody moved: everyone on {$device->name} is already in {$company->name} or in another company.",
            default => sprintf('Moved %d %s from %s into %s.', $result['moved'], $result['moved'] === 1 ? 'person' : 'people', $device->name, $company->name),
        };
        if ($result['skipped'] > 0) {
            $message .= sprintf(' %d already in another company %s left where they are.', $result['skipped'], $result['skipped'] === 1 ? 'was' : 'were');
        }

        return ApiResponse::success($result, $message);
    }

    /** @return array{0: Device, 1: Company} */
    private function targets(Request $request): array
    {
        abort_unless($request->user()->can('employees.update'), 403, 'This action is unauthorized.');

        $data = $request->validate([
            'device_id' => ['required', 'integer', Rule::exists('devices', 'id')->where('tenant_id', Tenancy::id())],
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')->where('tenant_id', Tenancy::id())->whereNull('deleted_at')],
        ]);

        return [Device::query()->findOrFail($data['device_id']), Company::query()->findOrFail($data['company_id'])];
    }
}
