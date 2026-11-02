<?php

namespace App\Services\Reports;

use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Services\Employees\EmployeeRosterQuery;
use App\Support\OrgScope;
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
                'company:id,name',
                'department:id,name,company_id',
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
     * Company and department as the screen's two columns (two columns since 2026-11-03).
     * Someone in no company shows the old free-text `department` as the company.
     *
     * @return array{0:?string, 1:?string}
     */
    private function org(Employee $employee): array
    {
        // `department` is also a free-text column; read the relations, not the attribute.
        $company = $employee->getRelation('company');
        $department = $employee->getRelation('department');
        if ($company === null) {
            return [$employee->getAttribute('department') ?: null, null];
        }

        return [$company->name, $department?->name];
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

        $lines[] = OrgScope::from($request->query())->label() ?? 'All employees';

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
