<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /** Domain tables that carried data before multi-tenancy and need backfilling. */
    private const LEGACY_TABLES = ['employees', 'devices', 'attendance_logs', 'holidays', 'attendance_waivers'];

    /*
    | The one and only login. Seeding is destructive for users by design: every
    | run wipes the users table and recreates exactly this account, so the
    | credentials below are always valid after `db:seed`. No demo/placeholder
    | logins are created — they were a source of confusion.
    |
    | NOTE: this password is committed to the repository. Anyone with repo access
    | has it. Override it per-environment with OWNER_PASSWORD in .env.
    */
    private const OWNER_EMAIL = 'g.yusuf@promoseven.com';
    private const OWNER_NAME = 'Ghassan Yusuf';
    private const OWNER_PASSWORD = 'Gha$$an1983';

    public function run(): void
    {
        // 1. Permissions + role definitions.
        $this->call(RolesAndPermissionsSeeder::class);
        Tenancy::clear();

        // 2. Wipe every existing account. `notifications` cascade on user delete
        //    and `audit_logs.user_id` nulls out, so no orphans are left behind.
        $this->purgeAllAccounts();

        // 3. Default tenant: adopts all pre-existing domain data.
        $default = Tenant::firstOrCreate(
            ['slug' => 'default'],
            ['name' => 'Default Company', 'timezone' => config('attendance.timezone', 'Asia/Bahrain')],
        );

        foreach (self::LEGACY_TABLES as $table) {
            DB::table($table)->whereNull('tenant_id')->update(['tenant_id' => $default->id]);
        }

        RolesAndPermissionsSeeder::seedTenantRoles($default);

        // 4. The owner account — always recreated, always with the password above.
        $this->seedOwnerAccount($default);

        // 5. A second demo tenant with realistic domain data (no logins).
        $this->seedDemoTenant();

        Tenancy::clear();
    }

    /**
     * Removes all users plus anything keyed to them that has no cascade rule of
     * its own (API tokens, role/permission pivots, sessions).
     */
    private function purgeAllAccounts(): void
    {
        Tenancy::clear();

        $userIds = DB::table('users')->pluck('id');

        if ($userIds->isNotEmpty()) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $userIds)
                ->delete();

            DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
            DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
        }

        if (DB::getSchemaBuilder()->hasTable('sessions')) {
            DB::table('sessions')->delete();
        }

        // Eloquent delete (not truncate) so the notifications cascade fires.
        User::query()->withoutGlobalScopes()->delete();

        $this->command?->info(sprintf('Purged %d existing account(s).', $userIds->count()));
    }

    /**
     * The owner is a tenant-admin on the default tenant rather than a platform
     * super-admin: a super-admin resolves to no tenant (ResolveTenant spans all
     * tenants), which leaves the SPA without a workspace or live MQTT topic.
     * tenant-admin carries every domain permission and keeps the app coherent.
     */
    private function seedOwnerAccount(Tenant $default): void
    {
        Tenancy::set($default);

        // updateOrCreate, not firstOrCreate — the password must be reset on every
        // run, otherwise "always works" would only hold on a fresh database.
        $owner = User::withoutGlobalScopes()->updateOrCreate(
            ['email' => self::OWNER_EMAIL],
            [
                'name' => self::OWNER_NAME,
                'password' => Hash::make(env('OWNER_PASSWORD', self::OWNER_PASSWORD)),
                'tenant_id' => $default->id,
                'is_super_admin' => false,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $owner->syncRoles(['tenant-admin']);

        $this->command?->info(sprintf('Owner account ready: %s', self::OWNER_EMAIL));
    }

    /**
     * A second tenant carrying realistic domain data (departments, employees) so
     * multi-tenant scoping is exercisable. Deliberately has NO user accounts —
     * the owner account is the only login in the system.
     */
    private function seedDemoTenant(): void
    {
        $acme = Tenant::firstOrCreate(
            ['slug' => 'acme'],
            ['name' => 'Acme Industries', 'timezone' => 'Asia/Bahrain'],
        );
        RolesAndPermissionsSeeder::seedTenantRoles($acme);

        Tenancy::set($acme);

        $engineering = Department::firstOrCreate(['tenant_id' => $acme->id, 'name' => 'Engineering'], ['code' => 'ENG']);
        $operations = Department::firstOrCreate(['tenant_id' => $acme->id, 'name' => 'Operations'], ['code' => 'OPS']);

        $employees = [
            ['name' => 'Sara Ahmed', 'employee_code' => '1001', 'department_id' => $engineering->id],
            ['name' => 'Yusuf Khalid', 'employee_code' => '1002', 'department_id' => $engineering->id],
            ['name' => 'Mona Hassan', 'employee_code' => '2001', 'department_id' => $operations->id],
        ];
        foreach ($employees as $data) {
            Employee::firstOrCreate(
                ['tenant_id' => $acme->id, 'employee_code' => $data['employee_code']],
                $data + ['is_active' => true],
            );
        }
    }
}
