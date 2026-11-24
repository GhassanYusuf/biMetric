<?php

namespace App\Http\Requests\Api\User;

use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * A user's company access may still name a company that has since been deleted (2026-11-24: the Seven Hotel's
 * finance user kept City One Hotel #27 after it was deleted and replaced). The edit form sent that id back,
 * the `exists` rule refused it under `company_ids.0`, and the form showed nothing — the save simply did not
 * happen. Deleted companies are now dropped from what is sent, so saving tidies the list.
 *
 * Never broadening: if dropping them would empty a list that was not empty, that is refused — an empty list
 * means EVERY company, and a user limited to a company that no longer exists must not end up seeing all.
 */
trait DropsDeletedCompanies
{
    private bool $onlyDeletedCompanies = false;

    protected function prepareForValidation(): void
    {
        $ids = $this->input('company_ids');
        if (! is_array($ids) || $ids === []) {
            return;
        }

        $deleted = DB::table('companies')->where('tenant_id', Tenancy::id())->whereNotNull('deleted_at')
            ->whereIn('id', array_filter($ids, 'is_numeric'))->pluck('id')->map(fn ($i) => (int) $i)->all();
        if ($deleted === []) {
            return;
        }

        $kept = array_values(array_filter($ids, fn ($i) => ! in_array((int) $i, $deleted, true)));
        $this->onlyDeletedCompanies = $kept === [];
        $this->merge(['company_ids' => $kept]);
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->onlyDeletedCompanies) {
                $validator->errors()->add('company_ids', 'The only company ticked has been deleted. Tick at least one company, or choose every company.');
            }
        }];
    }
}
