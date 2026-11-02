<?php

namespace App\Services\Employees;

use App\Models\Company;
use App\Models\Device;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Employees → "Move terminal to company" (owner, 2026-11-02): everyone a terminal holds,
 * into one company, in one go. Asked for after importing JJs's 157 people, who arrived in
 * no company and were moved into JJ'S by hand.
 *
 * "On the terminal" means a live link to it (employee_device_maps, valid_to null): the
 * people Import staff created, plus anyone pushed there.
 *
 * Careful by default: only people in NO company move unless the caller says so, so a
 * terminal shared by two companies cannot pull anyone out of the right one by accident.
 * Each person is saved through the model, so the change is audited, the free-text
 * `department` follows, and a department that is not in the new company is cleared
 * (Employee::booted). Nothing is written to any terminal.
 */
class TerminalCompanyTransfer
{
    /** @return Collection<int, Employee> */
    private function people(Device $device): Collection
    {
        return Employee::query()
            ->whereIn('id', $device->employeeMaps()->whereNull('valid_to')->select('employee_id'))
            ->with('company:id,name')
            ->get();
    }

    /**
     * What a move would do, before it is done.
     *
     * @return array{total:int, unassigned:int, already:int, elsewhere:list<array{company:string,count:int}>}
     */
    public function preview(Device $device, Company $company): array
    {
        $people = $this->people($device);

        return [
            'total' => $people->count(),
            'unassigned' => $people->whereNull('company_id')->count(),
            'already' => $people->where('company_id', $company->id)->count(),
            'elsewhere' => $people
                ->filter(fn (Employee $e) => $e->company_id !== null && (int) $e->company_id !== (int) $company->id)
                ->groupBy(fn (Employee $e) => $e->company?->name ?? "#{$e->company_id}")
                ->map(fn ($group, $name) => ['company' => $name, 'count' => $group->count()])
                ->sortBy('company')->values()->all(),
        ];
    }

    /** @return array{moved:int, skipped:int} */
    public function move(Device $device, Company $company, bool $includeAssigned): array
    {
        $moved = 0;
        $skipped = 0;

        DB::transaction(function () use ($device, $company, $includeAssigned, &$moved, &$skipped) {
            foreach ($this->people($device) as $employee) {
                if ((int) $employee->company_id === (int) $company->id) {
                    continue; // already there
                }
                if ($employee->company_id !== null && ! $includeAssigned) {
                    $skipped++;

                    continue;
                }
                $employee->company_id = $company->id;
                $employee->save();
                $moved++;
            }
        });

        return ['moved' => $moved, 'skipped' => $skipped];
    }
}
