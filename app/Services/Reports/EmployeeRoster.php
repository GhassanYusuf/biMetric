<?php

namespace App\Services\Reports;

use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Services\Employees\EmployeeRosterQuery;
use App\Support\OrgScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
     *   rows: list<array{no:int, code:?string, name:string, initials:string,
     *         photo:?array{path:string, w:float, h:float, x:float, y:float}, resigned:bool, active:bool,
     *         company:?string, department:?string,
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
                'initials' => $this->initials($employee->fullName()),
                'photo' => $this->photo($employee),
                'resigned' => $employee->resigned_at !== null,
                'active' => (bool) $employee->is_active,
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

    /**
     * The person's photo for the PDF (owner, 2026-11-15: "make the export have the avatar"), as
     * a file path and the box it is drawn in. JPEG only: dompdf embeds a JPEG as it is, but needs
     * the GD extension for PNG and WebP, and this server has no GD (a PNG threw "The PHP GD
     * extension is required" on 2026-11-15). A PNG, a WebP or a file missing on disk prints the
     * initials instead. Photos taken through the cropper are JPEG already.
     *
     * Drawn to cover a square, like the avatar on screen: the box is the image scaled so its
     * short side fills the square, offset to centre it; the template clips the rest.
     *
     * @return array{path:string, w:float, h:float, x:float, y:float}|null
     */
    private function photo(Employee $employee, float $size = 26.0): ?array
    {
        if (! $employee->photo_path) {
            return null;
        }
        $path = Storage::disk('public')->path($employee->photo_path);
        $info = is_file($path) ? @getimagesize($path) : false;
        if (! $info || $info[2] !== IMAGETYPE_JPEG || ! $info[0] || ! $info[1]) {
            return null;
        }
        $scale = $size / min($info[0], $info[1]);
        $w = round($info[0] * $scale, 2);
        $h = round($info[1] * $scale, 2);

        return ['path' => $path, 'w' => $w, 'h' => $h, 'x' => round(($size - $w) / 2, 2), 'y' => round(($size - $h) / 2, 2)];
    }

    /** "Ghassan Yusuf" → "GY", for a person with no photo. */
    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = $parts[0] ?? '?';
        $last = count($parts) > 1 ? $parts[count($parts) - 1] : '';

        return mb_strtoupper(mb_substr($first, 0, 1).mb_substr($last, 0, 1));
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

        if ($request->query('terminal_role') === 'admin') {
            $lines[] = 'Administrators on a terminal';
        } elseif ($request->query('terminal_role') === 'user') {
            $lines[] = 'Normal users on the terminals';
        }
        if ($request->query('punches') === 'none') {
            $lines[] = 'No punches';
        } elseif ($request->query('punches') === 'some') {
            $lines[] = 'Has punches';
        }
        if ($request->query('device_user_id') === 'same') {
            $lines[] = 'Terminal ID = Personnel ID';
        } elseif ($request->query('device_user_id') === 'different') {
            $lines[] = 'Terminal ID ≠ Personnel ID';
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
