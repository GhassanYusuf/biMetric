<?php

namespace App\Http\Requests\Api\Holiday;

use App\Models\Holiday;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A holiday for every company (company_ids empty or absent) or for the ticked companies (2026-11-22).
 * Two holidays may share a date only if no company would have both — the old "one holiday per date" rule,
 * kept per company.
 */
class StoreHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:255'],
            'company_ids' => ['sometimes', 'array'],
            'company_ids.*' => [
                'integer', 'distinct',
                Rule::exists('companies', 'id')->where('tenant_id', Tenancy::id())->whereNull('deleted_at'),
            ],
        ];
    }

    public function messages(): array
    {
        return ['company_ids.*.exists' => 'One of the chosen companies no longer exists.'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            // A user limited to some companies sets holidays for those only — never for every company.
            $allowed = $this->user()?->companyIds();
            if ($allowed !== null && ($this->companyIds() === [] || array_diff($this->companyIds(), $allowed) !== [])) {
                $validator->errors()->add('company_ids', 'Choose only your own companies. A holiday for every company is set by someone with access to all of them.');

                return;
            }
            $clash = Holiday::clashOn((string) $this->input('date'), $this->companyIds(), $this->ignoredId());
            if ($clash !== null) {
                $validator->errors()->add('date', "That day already has \"{$clash->name}\" for ".($clash->companies->isEmpty() ? 'every company' : 'one of these companies').'.');
            }
        }];
    }

    /** @return list<int> empty = every company */
    public function companyIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->input('company_ids', []))));
    }

    protected function ignoredId(): ?int
    {
        return null;
    }
}
