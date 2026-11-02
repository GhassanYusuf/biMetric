<?php

namespace App\Services\Employees;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The Employees screen's filters, as one query.
 *
 * Shared by the roster list (EmployeeController::index) and its PDF / Excel export
 * (ExportController::employees*), so an export is exactly the people the screen shows
 * with the same tree node, search, terminal and status picked (owner, 2026-10-21:
 * "all employees" exports everyone, a company exports that company). Two copies of
 * these rules would drift, and a file that lists different people from the screen it
 * was exported from is worse than no file.
 *
 * Tenant scoping comes from Employee's global scope, as everywhere else.
 */
class EmployeeRosterQuery
{
    /** The query-string keys this reads, for a controller's validate(). */
    public const RULES = [
        'search' => ['nullable', 'string', 'max:100'],
        // Company and department (two columns since 2026-11-03). company_id=none is the
        // tree's "Unassigned" node: people in no company.
        'company_id' => ['nullable', 'regex:/^(none|\d+)$/'],
        'department_id' => ['nullable', 'integer'],
        'is_active' => ['nullable', 'boolean'],
        'resigned' => ['nullable', 'boolean'],
        'device_id' => ['nullable', 'integer'],
        'unenrolled' => ['nullable', 'boolean'],
    ];

    public static function build(Request $request): Builder
    {
        return Employee::query()
            /*
             * Every word must match somewhere (2026-10-11). The list shows first and
             * last name together, so "ali mohamed" has to find ALI / MOHAMED JAAFAR
             * even though the two words live in different columns. Word by word rather
             * than a CONCAT, which SQL Server and SQLite spell differently.
             */
            ->when($request->filled('search'), function ($q) use ($request) {
                $words = preg_split('/\s+/', $request->string('search')->trim()->toString(), -1, PREG_SPLIT_NO_EMPTY);
                foreach ($words as $word) {
                    $term = '%'.$word.'%';
                    $q->where(function ($sub) use ($term) {
                        $sub->where('name', 'like', $term)
                            ->orWhere('last_name', 'like', $term)
                            ->orWhere('employee_code', 'like', $term)
                            ->orWhere('department', 'like', $term)
                            ->orWhere('job_title', 'like', $term)
                            ->orWhere('email', 'like', $term);
                    });
                }
            })
            // A company lists everyone in it, its departments included (the row carries
            // company_id). `none` is the tree's "Unassigned" node — a real state, not an id.
            ->when($request->query('company_id') === 'none', fn ($q) => $q->whereNull('company_id'))
            ->when(
                $request->filled('company_id') && $request->query('company_id') !== 'none',
                fn ($q) => $q->where('company_id', $request->integer('company_id')),
            )
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            // Resigned (2026-10-12): `1` only them, `0` everyone still employed. Absent =
            // both, so other callers (search, merge) still find a resigned person.
            ->when($request->filled('resigned') && Employee::hasEmploymentColumns(), fn ($q) => $request->boolean('resigned')
                ? $q->whereNotNull('resigned_at')
                : $q->whereNull('resigned_at'))
            // Enrolled on a specific terminal, via the live mapping — not the flat
            // snapshot columns, which cannot say WHICH device once there are two.
            ->when($request->filled('device_id'), fn ($q) => $q->whereHas(
                'deviceMaps',
                fn ($m) => $m->where('device_id', $request->integer('device_id'))->whereNull('valid_to'),
            ))
            // On no terminal at all: they cannot clock in anywhere.
            ->when($request->boolean('unenrolled'), fn ($q) => $q->whereDoesntHave(
                'deviceMaps',
                fn ($m) => $m->whereNull('valid_to'),
            ))
            ->orderBy('name');
    }
}
