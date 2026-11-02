<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** v_employee_company: company and department per employee (2026-11-02; over the companies table since 2026-11-03). */
class EmployeeCompanyViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_employee_has_their_company_and_department(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        Tenancy::set($tenant->id);
        $promoseven = Company::create(['name' => 'PROMOSEVEN']);
        $it = Department::create(['name' => 'IT', 'company_id' => $promoseven->id]);
        $helpdesk = Department::create(['name' => 'Helpdesk', 'company_id' => $promoseven->id]);
        $camelot = Company::create(['name' => 'Camelot']);
        Employee::create(['name' => 'Top', 'employee_code' => '1', 'company_id' => $promoseven->id]);
        Employee::create(['name' => 'Dept', 'employee_code' => '2', 'department_id' => $it->id]);
        Employee::create(['name' => 'Deep', 'employee_code' => '3', 'department_id' => $helpdesk->id]);
        Employee::create(['name' => 'Other', 'employee_code' => '4', 'company_id' => $camelot->id]);
        Employee::create(['name' => 'None', 'employee_code' => '5']);
        Tenancy::clear();

        $rows = collect(DB::table('v_employee_company')->get())->keyBy('name');

        $this->assertSame(['PROMOSEVEN', null], [$rows['Top']->company, $rows['Top']->department]);
        $this->assertSame(['PROMOSEVEN', 'IT'], [$rows['Dept']->company, $rows['Dept']->department]);
        $this->assertSame(['PROMOSEVEN', 'Helpdesk'], [$rows['Deep']->company, $rows['Deep']->department]);
        $this->assertSame(['Camelot', null], [$rows['Other']->company, $rows['Other']->department]);
        $this->assertSame([null, null], [$rows['None']->company, $rows['None']->department]);
        $this->assertSame(2, DB::table('v_employee_company')->where('company', 'PROMOSEVEN')->where('department', '!=', null)->count());
    }
}
