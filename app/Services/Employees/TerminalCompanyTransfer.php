<?php

namespace App\Services\Employees;

use App\Models\Company;
use App\Models\Department;
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
 *
 * Optionally a department of that company (owner, 2026-11-15: "in Move terminal to company add
 * department also"): everyone moved goes into it, and so does anyone already in the company with
 * no department. Someone already in ANOTHER department of the company is left there.
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
    public function preview(Device $device, Company $company, ?Department $department = null): array
    {
        $people = $this->people($device);
        $inCompany = $people->where('company_id', $company->id);

        return [
            'total' => $people->count(),
            'unassigned' => $people->whereNull('company_id')->count(),
            'already' => $inCompany->count(),
            // With a department: of those already in the company, how many it would place
            // (no department yet) and how many stay in another department of it.
            'already_unplaced' => $department ? $inCompany->whereNull('department_id')->count() : 0,
            'already_in_department' => $department ? $inCompany->where('department_id', $department->id)->count() : 0,
            'elsewhere' => $people
                ->filter(fn (Employee $e) => $e->company_id !== null && (int) $e->company_id !== (int) $company->id)
                ->groupBy(fn (Employee $e) => $e->company?->name ?? "#{$e->company_id}")
                ->map(fn ($group, $name) => ['company' => $name, 'count' => $group->count()])
                ->sortBy('company')->values()->all(),
        ];
    }

    /** @return array{moved:int, skipped:int, placed:int} */
    public function move(Device $device, Company $company, bool $includeAssigned, ?Department $department = null): array
    {
        $moved = 0;
        $skipped = 0;
        $placed = 0; // already in the company, now put in the department

        DB::transaction(function () use ($device, $company, $includeAssigned, $department, &$moved, &$skipped, &$placed) {
            foreach ($this->people($device) as $employee) {
                if ((int) $employee->company_id === (int) $company->id) {
                    // Already there: only someone with no department is placed in it.
                    if ($department && $employee->department_id === null) {
                        $employee->department_id = $department->id;
                        $employee->save();
                        $placed++;
                    }

                    continue;
                }
                if ($employee->company_id !== null && ! $includeAssigned) {
                    $skipped++;

                    continue;
                }
                $employee->company_id = $company->id;
                // The department is set with the company, so the model's rule (a department
                // decides the company) keeps both in step.
                $employee->department_id = $department?->id;
                $employee->save();
                $moved++;
            }
        });

        return ['moved' => $moved, 'skipped' => $skipped, 'placed' => $placed];
    }
}
