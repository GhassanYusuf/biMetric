<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Department;
use App\Services\Attendance\PersonnelIdReplacer;
use App\Support\ApiResponse;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Attendance → Personnel IDs on punches (owner, 2026-11-05: "build it as a feature in the
 * attendance page"). The CPR into device_user_id on a company's or department's stored
 * punches, the terminal's number kept; the rules are PersonnelIdReplacer's, shared with
 * `attendance:personnel-ids`.
 *
 * Needs employees.update as well as attendance.view: whoever may change a Personnel ID may
 * change which number that person's punches go out under.
 *
 * Runs in the request: it touches the database only (no terminal, rule 11), and the largest
 * company so far (JJ'S, 6,232 punches) took a few seconds.
 */
class AttendancePersonnelIdController extends Controller
{
    public function __construct(private readonly PersonnelIdReplacer $replacer) {}

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeIt($request);
        [$scope, $from] = $this->input($request);

        return ApiResponse::success($this->replacer->preview($scope, $from, Tenancy::id()), 'Preview ready.');
    }

    public function apply(Request $request): JsonResponse
    {
        $this->authorizeIt($request);
        [$scope, $from, $label] = $this->input($request);

        $result = $this->replacer->apply($scope, $from, Tenancy::id(), $label, $request->user()->id);

        return ApiResponse::success(
            $result,
            $result['changed'] === 0
                ? "Nothing to change in {$label}."
                : number_format($result['changed']).' punch'.($result['changed'] === 1 ? '' : 'es')." in {$label} now carry the Personnel ID.",
        );
    }

    /** The last runs, newest first, with whether each was undone. */
    public function runs(Request $request): JsonResponse
    {
        $this->authorizeIt($request);

        $undone = AuditLog::query()->where('action', PersonnelIdReplacer::UNDONE)->get()
            ->map(fn ($a) => $a->changes['applied_audit_id'] ?? null)->filter()->flip();

        $runs = AuditLog::query()->with('user:id,name')
            ->where('action', PersonnelIdReplacer::APPLIED)
            ->latest('id')->limit(30)->get()
            ->map(fn (AuditLog $a) => [
                'id' => $a->id,
                'at' => $a->created_at?->toIso8601String(),
                'by' => $a->user?->name,
                'scope' => $a->changes['scope'] ?? null,
                'from' => $a->changes['from'] ?? null,
                'punches' => (int) ($a->changes['punches'] ?? 0),
                'employees' => (int) ($a->changes['employees'] ?? 0),
                'undone' => isset($undone[$a->id]),
            ]);

        return ApiResponse::success($runs, 'Changes retrieved.');
    }

    public function undo(Request $request, AuditLog $run): JsonResponse
    {
        $this->authorizeIt($request);
        abort_unless($run->action === PersonnelIdReplacer::APPLIED, 404);

        $already = AuditLog::query()->where('action', PersonnelIdReplacer::UNDONE)->get()
            ->contains(fn ($a) => (int) ($a->changes['applied_audit_id'] ?? 0) === $run->id);
        if ($already) {
            return ApiResponse::error('That change was already undone.', [], 409);
        }

        try {
            $r = $this->replacer->undo((string) $run->changes['backup'], Tenancy::id(), $request->user()->id, $run->id);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), [], 409);
        }

        return ApiResponse::success($r, number_format($r['restored']).' punch'.($r['restored'] === 1 ? '' : 'es').' in '.($run->changes['scope'] ?? 'that change').' have their terminal number back.');
    }

    private function authorizeIt(Request $request): void
    {
        $user = $request->user();
        // Its own permission since 2026-11-26 (System → Personnel IDs), was attendance.view + employees.update.
        abort_unless($user->can('system.personnel-ids'), 403, 'This action is unauthorized.');
    }

    /** @return array{0: array, 1: string, 2: string} scope, first day, label */
    private function input(Request $request): array
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')->where('tenant_id', Tenancy::id())->whereNull('deleted_at')],
            // A department of that company, or "none" for its staff with no department.
            'department_id' => ['nullable', function ($attr, $value, $fail) use ($request) {
                if ($value === null || $value === '' || $value === 'none') {
                    return;
                }
                $ok = ctype_digit((string) $value) && Department::query()->whereKey((int) $value)
                    ->where('company_id', (int) $request->input('company_id'))->exists();
                if (! $ok) {
                    $fail('Choose a department of that company.');
                }
            }],
            'from' => ['required', 'date_format:Y-m-d'],
        ]);

        $company = Company::query()->findOrFail((int) $data['company_id']);
        $department = $data['department_id'] ?? null;
        $label = $company->name;
        if ($department === 'none') {
            $label .= ' - no department';
        } elseif ($department !== null && $department !== '') {
            $label .= ' - '.Department::query()->whereKey((int) $department)->value('name');
            $department = (int) $department;
        } else {
            $department = null;
        }

        return [['company_id' => $company->id, 'department_id' => $department], $data['from'], $label];
    }
}
