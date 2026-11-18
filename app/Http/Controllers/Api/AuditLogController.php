<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\CompanyAccess;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Audit log screen (owner, 2026-11-09: "build two new features: audit logs and error
 * logs"). audit_logs is written already — AuditObserver on models, and explicit rows for
 * actions no column records (levels, passwords, wipes, Personnel-ID runs); this reads it.
 *
 * By default only what PEOPLE did: of 64,000 rows on 2026-11-09, 61,000 were the system's own
 * bookkeeping (a terminal's last-seen time, a sync's watermark). `who=all` shows those too,
 * `who=system` only those, `who=<user id>` one person.
 *
 * Needs audit.view. The log covers every company, so a user limited to some companies
 * (CompanyAccess) is refused rather than shown other companies' names.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('audit.view')) {
            return ApiResponse::error('This action is unauthorized.', [], 403);
        }
        if (CompanyAccess::limited()) {
            return ApiResponse::error('The audit log covers every company, so a user limited to some companies cannot see it.', [], 403);
        }

        $f = $request->validate([
            'who' => ['nullable', 'string', 'max:20'],          // people (default) | all | system | <user id>
            'action' => ['nullable', 'string', 'max:100'],      // exact, or a prefix ending in "." ("employee.")
            'subject_type' => ['nullable', 'string', 'max:50'], // Employee, Device, User, …
            'subject_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $tz = config('attendance.timezone');
        $who = $f['who'] ?? 'people';

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when($who === 'people', fn ($q) => $q->whereNotNull('user_id'))
            ->when($who === 'system', fn ($q) => $q->whereNull('user_id'))
            ->when(ctype_digit($who), fn ($q) => $q->where('user_id', (int) $who))
            ->when(! empty($f['action']), fn ($q) => str_ends_with($f['action'], '.')
                ? $q->where('action', 'like', $f['action'].'%')
                : $q->where('action', $f['action']))
            ->when(! empty($f['subject_type']), fn ($q) => $q->where('auditable_type', 'App\\Models\\'.preg_replace('/\W/', '', $f['subject_type'])))
            ->when(! empty($f['subject_id']), fn ($q) => $q->where('auditable_id', (int) $f['subject_id']))
            ->when(! empty($f['from']), fn ($q) => $q->where('created_at', '>=', Carbon::parse($f['from'], $tz)->startOfDay()->utc()))
            ->when(! empty($f['to']), fn ($q) => $q->where('created_at', '<=', Carbon::parse($f['to'], $tz)->endOfDay()->utc()))
            ->when(! empty($f['search']), fn ($q) => $q->where(fn ($w) => $w
                ->where('action', 'like', '%'.$f['search'].'%')
                ->orWhere('changes', 'like', '%'.$f['search'].'%')
                ->orWhere('ip_address', 'like', '%'.$f['search'].'%')))
            ->latest('id')
            ->paginate((int) ($f['per_page'] ?? 30));

        $this->attachSubjectNames($logs->getCollection());
        $this->attachDiffs($logs->getCollection());

        return ApiResponse::success(AuditLogResource::collection($logs), 'Audit log retrieved.', [
            // For the screen's filters: who has ever acted, and every action recorded.
            'users' => User::query()->where('is_super_admin', false)->orderBy('name')->get(['id', 'name']),
            'actions' => AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
        ]);
    }

    /**
     * Each entry as a field list: before, after, and how the "before" is known (owner,
     * 2026-11-09: "show what the record was before updating").
     *
     *   recorded      written with the change ({field: [before, after]}, since 2026-11-09)
     *   from_history  an older row kept only the new value; the value before is the latest
     *                 earlier row of the same record that set that field. Right as long as
     *                 nothing changed it outside the system, which is why it is labelled.
     *   unknown       nothing earlier in the trail
     *   new           a created record has no before
     *
     * A *_id field also gets the name it pointed at (company, department, …), at both ends.
     */
    private function attachDiffs($logs): void
    {
        $refs = ['company_id' => 'companies', 'department_id' => 'departments', 'employee_id' => 'employees', 'device_id' => 'devices', 'manager_id' => 'employees', 'user_id' => 'users'];
        $wanted = [];
        $diffs = [];

        foreach ($logs as $log) {
            $changes = is_array($log->changes) ? $log->changes : [];
            $verb = substr((string) strrchr($log->action, '.'), 1);
            $paired = $changes !== [] && collect($changes)->every(fn ($v) => is_array($v) && array_is_list($v) && count($v) === 2);
            $rows = [];

            if ($paired) {
                foreach ($changes as $field => [$before, $after]) {
                    $rows[] = ['field' => $field, 'before' => $before, 'after' => $after, 'before_known' => 'recorded'];
                }
            } elseif ($verb === 'created') {
                foreach ($changes as $field => $value) {
                    $rows[] = ['field' => $field, 'before' => null, 'after' => $value, 'before_known' => 'new'];
                }
            } elseif ($verb === 'updated' && $log->auditable_id && $changes !== []) {
                $history = $this->earlier($log, array_keys($changes));
                foreach ($changes as $field => $value) {
                    $known = array_key_exists($field, $history);
                    $rows[] = ['field' => $field, 'before' => $known ? $history[$field] : null, 'after' => $value, 'before_known' => $known ? 'from_history' : 'unknown'];
                }
            } else {
                // Explicit rows with their own shape (counts, scopes, file names): as they are.
                foreach ($changes as $field => $value) {
                    $rows[] = ['field' => $field, 'before' => null, 'after' => $value, 'before_known' => 'none'];
                }
            }

            foreach ($rows as $r) {
                if (isset($refs[$r['field']])) {
                    foreach ([$r['before'], $r['after']] as $id) {
                        if (is_numeric($id)) {
                            $wanted[$refs[$r['field']]][(int) $id] = true;
                        }
                    }
                }
            }
            $diffs[$log->id] = $rows;
        }

        $names = [];
        foreach ($wanted as $table => $ids) {
            $names[$table] = DB::table($table)->whereIn('id', array_keys($ids))
                ->when(\App\Support\Tenancy::id(), fn ($q, $t) => $q->where('tenant_id', $t))
                ->pluck('name', 'id')->all();
        }

        foreach ($logs as $log) {
            $rows = array_map(function ($r) use ($refs, $names) {
                if (isset($refs[$r['field']])) {
                    $t = $refs[$r['field']];
                    $r['before_name'] = is_numeric($r['before']) ? ($names[$t][(int) $r['before']] ?? null) : null;
                    $r['after_name'] = is_numeric($r['after']) ? ($names[$t][(int) $r['after']] ?? null) : null;
                }

                return $r;
            }, $diffs[$log->id] ?? []);
            $log->setAttribute('diff', $rows);
        }
    }

    /**
     * For an old-style row (new values only): each field's value just before it, from the
     * latest earlier row of the same record that set the field.
     *
     * @return array<string, mixed>
     */
    private function earlier(AuditLog $log, array $fields): array
    {
        $found = [];
        $earlier = AuditLog::query()
            ->where('auditable_type', $log->auditable_type)
            ->where('auditable_id', $log->auditable_id)
            ->where('id', '<', $log->id)
            ->latest('id')->limit(300)->get(['id', 'action', 'changes']);

        foreach ($earlier as $row) {
            foreach ((array) $row->changes as $field => $value) {
                if (! in_array($field, $fields, true) || array_key_exists($field, $found)) {
                    continue;
                }
                // A [before, after] row's "after", or an old row's plain new value.
                $found[$field] = is_array($value) && array_is_list($value) && count($value) === 2 ? $value[1] : $value;
            }
            if (count($found) === count($fields)) {
                break;
            }
        }

        return $found;
    }

    /**
     * "Employee #1429" means nothing on screen; its name does. Looked up in bulk per type,
     * without the tenant's other scopes hiding a deleted or soft-deleted subject (its name is
     * still what happened), and only within this company account.
     */
    private function attachSubjectNames($logs): void
    {
        $tables = [
            'App\\Models\\Employee' => ['employees', fn ($r) => $r->name.($r->employee_code !== null ? " ({$r->employee_code})" : '')],
            'App\\Models\\Device' => ['devices', fn ($r) => $r->name],
            'App\\Models\\User' => ['users', fn ($r) => $r->name],
            'App\\Models\\Company' => ['companies', fn ($r) => $r->name],
            'App\\Models\\Department' => ['departments', fn ($r) => $r->name],
        ];
        foreach ($logs->groupBy('auditable_type') as $type => $rows) {
            if (! isset($tables[$type])) {
                continue;
            }
            [$table, $label] = $tables[$type];
            $columns = $table === 'employees' ? ['id', 'name', 'employee_code'] : ['id', 'name'];
            $names = DB::table($table)->whereIn('id', $rows->pluck('auditable_id')->filter()->unique()->values())
                ->when(\App\Support\Tenancy::id(), fn ($q, $t) => $q->where('tenant_id', $t))
                ->get($columns)->mapWithKeys(fn ($r) => [$r->id => $label($r)]);
            foreach ($rows as $row) {
                $row->setAttribute('subject_name', $names[$row->auditable_id] ?? null);
            }
        }
    }
}
