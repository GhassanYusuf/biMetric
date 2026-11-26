<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Holiday\StoreHolidayRequest;
use App\Http\Requests\Api\Holiday\UpdateHolidayRequest;
use App\Http\Resources\HolidayResource;
use App\Models\Company;
use App\Models\CompanyAttendanceRule;
use App\Models\Holiday;
use App\Services\Settings\TenantSettings;
use App\Support\ApiResponse;
use App\Support\AttendanceRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HolidayController extends Controller
{
    /**
     * Every holiday with the companies it applies to (none = every company). A user limited to some
     * companies sees the every-company ones and their own companies' — not another company's day off.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Holiday::class);

        $holidays = Holiday::query()->with('companies:id,name')->orderBy('date')->get();
        $allowed = $request->user()->companyIds();
        if ($allowed !== null) {
            $holidays = $holidays->filter(fn (Holiday $h) => $h->companies->isEmpty() || $h->companies->pluck('id')->intersect($allowed)->isNotEmpty())->values();
        }

        return ApiResponse::success(HolidayResource::collection($holidays), 'Holidays retrieved.');
    }

    public function store(StoreHolidayRequest $request): JsonResponse
    {
        $this->authorize('create', Holiday::class);

        $holiday = DB::transaction(function () use ($request) {
            $holiday = Holiday::create($request->safe()->only(['date', 'name']));
            $holiday->companies()->sync($request->companyIds());

            return $holiday;
        });

        return ApiResponse::created(new HolidayResource($holiday->load('companies:id,name')), 'Holiday added.');
    }

    public function update(UpdateHolidayRequest $request, Holiday $holiday): JsonResponse
    {
        $this->authorize('update', $holiday);
        $this->assertMayChange($request, $holiday);

        DB::transaction(function () use ($request, $holiday) {
            $holiday->update($request->safe()->only(['date', 'name']));
            if ($request->has('company_ids')) {
                $holiday->companies()->sync($request->companyIds());
            }
            $holiday->touch(); // a change of companies alone still moves the live stamp (rule 18)
        });

        return ApiResponse::success(new HolidayResource($holiday->load('companies:id,name')), 'Holiday updated.');
    }

    /**
     * The attendance rules: the weekend, the shift, and the thresholds behind Late, Left early and Left
     * late. They live on the Holidays screen because they answer the same question a holiday does — "when
     * is this person expected at work?" — with the same permissions. See App\Support\AttendanceRules.
     *
     * `default` is the system default; `companies` lists every company the user can see, each with its own
     * rules or null (= uses the default) (2026-11-22).
     */
    public function rules(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Holiday::class);

        return ApiResponse::success($this->rulesPayload($request), 'Attendance rules retrieved.');
    }

    /** Any subset of the default rules; what is sent is saved, the rest is left as it was. */
    public function updateRules(Request $request, TenantSettings $settings): JsonResponse
    {
        $this->authorize('create', Holiday::class);
        if ($request->user()->companyIds() !== null) {
            abort(403, 'The default rules apply to every company. They are changed by someone with access to all of them.');
        }

        $data = $request->validate(...self::ruleValidation(false));

        // Checked against what will be stored, not only what was sent: moving just the
        // start past the existing end is as wrong as sending both backwards.
        $current = AttendanceRules::current();
        self::assertShiftOrder($data['shift_start'] ?? $current->shiftStart, $data['shift_end'] ?? $current->shiftEnd);

        // Open 24 hours is per company only (company_attendance_rules.no_fixed_hours), not a default setting.
        $writes = collect($data)->except(['weekend_days', 'no_fixed_hours'])->mapWithKeys(fn ($v, $k) => ["attendance.{$k}" => $v])->all();
        if (array_key_exists('weekend_days', $data)) {
            $writes['attendance.work_days'] = array_values(array_diff(range(0, 6), $data['weekend_days']));
        }

        foreach ($writes as $key => $value) {
            try {
                $settings->set($key, $value);
            } catch (\InvalidArgumentException $e) {
                $field = $key === 'attendance.work_days' ? 'weekend_days' : substr($key, strlen('attendance.'));
                throw ValidationException::withMessages([$field => [$e->getMessage()]]);
            }
        }

        return ApiResponse::success($this->rulesPayload($request), 'Attendance rules saved. Every company on the default now counts against them.');
    }

    /** A company's own rules — all of them, so the row never half-inherits the default. */
    public function updateCompanyRules(Request $request, Company $company): JsonResponse
    {
        $this->authorize('create', Holiday::class);
        $this->assertCompanyAllowed($request, $company);

        $data = $request->validate(...self::ruleValidation(true));
        self::assertShiftOrder($data['shift_start'], $data['shift_end']);
        $data['no_fixed_hours'] = filter_var($data['no_fixed_hours'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($data['no_fixed_hours']) {
            // Grace minutes mean nothing without a shift to be late for.
            $data['late_grace_minutes'] = $data['early_leave_grace_minutes'] = $data['leave_late_after_minutes'] = 0;
        }

        CompanyAttendanceRule::query()->updateOrCreate(['company_id' => $company->id], [
            'shift_start' => $data['shift_start'],
            'shift_end' => $data['shift_end'],
            'work_days' => array_values(array_diff(range(0, 6), $data['weekend_days'])),
            'late_grace_minutes' => $data['late_grace_minutes'],
            'early_leave_grace_minutes' => $data['early_leave_grace_minutes'],
            'leave_late_after_minutes' => $data['leave_late_after_minutes'],
            'no_fixed_hours' => $data['no_fixed_hours'],
        ]);

        return ApiResponse::success($this->rulesPayload($request), "{$company->name} now has its own rules.");
    }

    /** Back to the default: the company's own row is removed. */
    public function resetCompanyRules(Request $request, Company $company): JsonResponse
    {
        $this->authorize('create', Holiday::class);
        $this->assertCompanyAllowed($request, $company);

        CompanyAttendanceRule::query()->where('company_id', $company->id)->get()->each->delete();

        return ApiResponse::success($this->rulesPayload($request), "{$company->name} now uses the default rules.");
    }

    public function destroy(Request $request, Holiday $holiday): JsonResponse
    {
        $this->authorize('delete', $holiday);
        $this->assertMayChange($request, $holiday);

        $holiday->delete();

        return ApiResponse::success(null, 'Holiday removed.');
    }

    /** @return array{default:array, may_edit_default:bool, companies:list<array{id:int,name:string,rules:?array}>} the default's own fields too */
    private function rulesPayload(Request $request): array
    {
        $own = CompanyAttendanceRule::query()->get()->keyBy('company_id');
        $companies = Company::query()->orderBy('name')->get(['id', 'name']); // CompanyScope limits a limited user

        $default = AttendanceRules::current()->toArray();

        // The default's fields also stay at the top level, as before 2026-11-22, for existing callers.
        return [
            ...$default,
            'default' => $default,
            'may_edit_default' => $request->user()->companyIds() === null,
            'companies' => $companies->map(fn (Company $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'rules' => isset($own[$c->id]) ? AttendanceRules::fromCompanyRow($own[$c->id])->toArray() : null,
            ])->values()->all(),
        ];
    }

    /** @return array{0:array,1:array} validate() arguments; $all = every field required */
    private static function ruleValidation(bool $all): array
    {
        $need = $all ? 'required' : 'sometimes';
        $minutes = [$need, 'integer', 'between:0,240'];

        return [[
            'weekend_days' => [$all ? 'present' : 'sometimes', 'array', 'max:6'],
            'weekend_days.*' => ['integer', 'between:0,6', 'distinct'],
            'shift_start' => [$need, 'date_format:H:i'],
            'shift_end' => [$need, 'date_format:H:i'],
            'late_grace_minutes' => $minutes,
            'early_leave_grace_minutes' => $minutes,
            'leave_late_after_minutes' => $minutes,
            'no_fixed_hours' => ['sometimes', 'boolean'],
        ], [
            'weekend_days.max' => 'At least one day of the week has to be a work day.',
            'shift_start.date_format' => 'Use 24-hour HH:MM, e.g. 07:30.',
            'shift_end.date_format' => 'Use 24-hour HH:MM, e.g. 16:30.',
        ]];
    }

    private static function assertShiftOrder(string $start, string $end): void
    {
        // An end before the start is an overnight shift (17:00–02:00, 2026-11-22). Only the same time is refused.
        if (AttendanceRules::minutes($end) === AttendanceRules::minutes($start)) {
            throw ValidationException::withMessages(['shift_end' => ['The shift cannot start and end at the same time.']]);
        }
    }

    private function assertCompanyAllowed(Request $request, Company $company): void
    {
        $allowed = $request->user()->companyIds();
        if ($allowed !== null && ! in_array((int) $company->id, $allowed, true)) {
            abort(403, 'That company is not one of yours.');
        }
    }

    /** A limited user changes only holidays that are for their companies alone. */
    private function assertMayChange(Request $request, Holiday $holiday): void
    {
        $allowed = $request->user()->companyIds();
        if ($allowed === null) {
            return;
        }
        $ids = $holiday->companies()->pluck('companies.id')->map(fn ($i) => (int) $i)->all();
        if ($ids === [] || array_diff($ids, $allowed) !== []) {
            abort(403, 'This holiday is also for companies that are not yours.');
        }
    }
}
