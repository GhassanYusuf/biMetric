<?php

namespace App\Services\Reports;

use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Services\Employees\EmployeeRosterQuery;
use Illuminate\Http\Request;

/**
 * The Employees screen as a list for a file (owner, 2026-10-21): "all employees" in
 * the tree exports everyone, a company exports that company. The PDF
 * (pdf.employee-roster) and the workbook (EmployeeRosterWorkbook) are both written
 * from build()'s output, so they cannot list different people or say different things.
 *
 * The people come from EmployeeRosterQuery, the same query as the screen. The columns
 * are the screen's, with one rule carried over from it (CLAUDE.md rule 10): a
 * terminal whose fingerprints were never read, or would not decode, prints "?" — never
 * 0, which would tell the reader the person has no fingerprint there.
 */
class EmployeeRoster
{
    /**
     * @return array{
     *   rows: list<array{no:int, code:?string, name:string, company:?string, department:?string,
     *         job_title:?string, status:string, card:?string,
     *         terminals: list<array{name:string, fingers:?int, card:bool}>}>,
     *   filterLines: list<string>,
     * }
     */
    public function build(Request $request): array
    {
        $employees = EmployeeRosterQuery::build($request)
            ->with([
                'department:id,name,parent_id',
                'department.parent:id,name,parent_id',
                'department.parent.parent:id,name,parent_id',
                // Live mappings only: a closed one is a PIN they USED to hold.
                'deviceMaps' => fn ($q) => $q->whereNull('valid_to')->with('device:id,name'),
            ])
            ->get();

        $rows = [];
        foreach ($employees as $i => $employee) {
            [$company, $department] = $this->org($employee);

            $rows[] = [
                'no' => $i + 1,
                'code' => $employee->employee_code,
                'name' => $employee->fullName(),
                'company' => $company,
                'department' => $department,
                'job_title' => $employee->job_title,
                'status' => $this->status($employee),
                'card' => $employee->card_number ? (string) $employee->card_number : null,
                'terminals' => $employee->deviceMaps
                    ->sortBy(fn (EmployeeDeviceMap $m) => $m->device?->name)
                    ->map(fn (EmployeeDeviceMap $m) => [
                        'name' => $m->device?->name ?? "Device {$m->device_id}",
                        'fingers' => $m->fingerprintCount(),
                        'card' => ! empty($m->card_number),
                    ])->values()->all(),
            ];
        }

        return ['rows' => $rows, 'filterLines' => $this->filterLines($request)];
    }

    /**
     * Company and department as the screen's two columns (employeeLabels.jsx orgOf): the
     * company is the top of the branch; a person placed on the company itself has no
     * department yet. Without a tree node the old free-text `department` is the company.
     *
     * @return array{0:?string, 1:?string}
     */
    private function org(Employee $employee): array
    {
        // `department` is also a free-text column; read the relation, not the attribute.
        $node = $employee->getRelation('department');
        if (! $node instanceof Department) {
            return [$employee->getAttribute('department') ?: null, null];
        }

        $company = $node->company();

        return [$company->name, (int) $node->id === (int) $company->id ? null : $node->name];
    }

    private function status(Employee $employee): string
    {
        if ($employee->resigned_at !== null) {
            return 'Resigned '.$employee->resigned_at->format('j M Y');
        }

        return $employee->is_active ? 'Active' : 'Inactive';
    }

    /** What the file covers, in words, for its header — a printout cannot be re-queried. */
    private function filterLines(Request $request): array
    {
        $lines = [];

        $node = $request->query('department_id');
        if ($node === 'none') {
            $lines[] = 'Unassigned (no company)';
        } elseif ($request->filled('department_id')) {
            $department = Department::find((int) $node);
            $lines[] = match (true) {
                $department === null => "Department #{$node}",
                $department->parent_id === null => 'Company: '.$department->name,
                default => 'Department: '.$department->path().' (and below)',
            };
        } else {
            $lines[] = 'All employees';
        }

        if ($request->filled('search')) {
            $lines[] = 'Search: “'.$request->string('search')->trim().'”';
        }
        if ($request->boolean('unenrolled')) {
            $lines[] = 'On no terminal';
        } elseif ($request->filled('device_id')) {
            $lines[] = 'Terminal: '.(Device::find($request->integer('device_id'))?->name ?? '#'.$request->integer('device_id'));
        }

        $lines[] = match (true) {
            $request->filled('resigned') && $request->boolean('resigned') => 'Resigned only',
            $request->filled('is_active') => $request->boolean('is_active') ? 'Active only' : 'Inactive only',
            $request->filled('resigned') => 'Current staff',
            default => 'Everyone, resigned included',
        };

        return $lines;
    }
}
