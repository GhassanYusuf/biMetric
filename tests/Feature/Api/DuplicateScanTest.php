<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Scan button (2026-10-06, owner's request): a screen that finds duplicate
 * employment records of every kind and suggests a fold that loses nothing.
 *
 * The properties worth pinning down:
 *
 *  - it is a READ. Anyone who can see the roster can run it, and the permission
 *    to actually merge does not arrive with it — that is the whole point of
 *    finding a duplicate before you are allowed to fix one.
 *  - it stays inside the company. A name, card or email identical to another
 *    tenant's record must not surface here, and an employee id from another
 *    company must not be nameable in a preview.
 *  - the preview is honest: what would come across AND what would be lost
 *    because the survivor already holds something different.
 */
class DuplicateScanTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $adminToken;

    private string $operatorToken;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($this->tenant);
        Tenancy::clear();

        $this->adminToken = $this->tokenFor('admin@acme.test', 'tenant-admin');
        $this->operatorToken = $this->tokenFor('operator@acme.test', 'operator');

        Tenancy::set($this->tenant->id);
    }

    /** ----------------------------------------------------------------- scans */

    public function test_the_scan_route_is_reachable_and_returns_its_documented_shape(): void
    {
        $this->employee(['card_number' => '778899']);

        // `duplicate-groups` is a fixed path sitting in front of the
        // `employees/{employee}` binding. If route order ever regresses this
        // becomes a 404 on the model binding instead of a 200, which is exactly
        // the kind of silent breakage this test exists to catch.
        $response = $this->scan()->assertOk();

        $this->assertIsArray($response->json('data.groups'));
        $this->assertArrayHasKey('counts', $response->json('data'));

        foreach (['strong', 'possible', 'do_not_merge', 'total'] as $key) {
            $this->assertArrayHasKey($key, $response->json('data.counts'), "counts.{$key}");
        }
    }

    public function test_two_records_holding_the_same_card_are_grouped_as_strong(): void
    {
        $a = $this->employee(['name' => 'Ali Jaafar', 'card_number' => '778899']);
        $b = $this->employee(['name' => 'Ali Jaafar', 'card_number' => '778899']);

        // Only ONE of the two has ever been used. The keeper is the record
        // carrying the history, so the fold moves the least — and the punches
        // are then on the survivor instead of on a row that is deleted.
        AttendanceLog::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $b->id,
            'device_id' => $this->device()->id,
            'device_user_id' => '778899',
            'punched_at' => now()->subHour(),
            'verify_method' => 1,
            'status' => 0,
        ]);

        $data = $this->scan()->assertOk()->json('data');

        $this->assertSame(1, $data['counts']['strong']);
        $this->assertSame(1, $data['counts']['total']);

        $group = $data['groups'][0];
        $this->assertSame('strong', $group['tier']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($group['records'], 'id'));

        // The evidence is named in the words it came in, not summarised into a
        // confidence score the operator never supplied.
        $cardSignal = collect($group['signals'])->first(fn (string $s) => str_contains($s, 'Same card'));
        $this->assertNotNull($cardSignal, 'the shared card must be given as the reason');
        $this->assertStringContainsString('778899', $cardSignal);

        $this->assertSame($b->id, $group['keep']);
        $this->assertSame(1, $group['records'][0]['punches']);
        $this->assertSame(0, $group['records'][1]['punches']);
    }

    public function test_the_scan_stays_inside_the_company(): void
    {
        $other = Tenant::create(['name' => 'Rival', 'slug' => 'rival']);
        Tenancy::set($other->id);
        $foreign = [
            $this->employee(['name' => 'Ali Jaafar', 'card_number' => '778899']),
            $this->employee(['name' => 'Ali Jaafar', 'card_number' => '778899']),
        ];

        Tenancy::set($this->tenant->id);
        $mine = [
            $this->employee(['name' => 'Ali Jaafar', 'card_number' => '778899']),
            $this->employee(['name' => 'Ali Jaafar', 'card_number' => '778899']),
        ];

        $data = $this->scan()->assertOk()->json('data');

        // One suggestion, made of this company's rows only. The identical pair
        // in the other company is invisible — they are not duplicates of ours.
        $this->assertSame(1, $data['counts']['total']);
        $this->assertEqualsCanonicalizing(
            array_column($mine, 'id'),
            array_column($data['groups'][0]['records'], 'id'),
        );

        $scannedIds = array_column($data['groups'][0]['records'], 'id');
        foreach ($foreign as $row) {
            $this->assertNotContains($row->id, $scannedIds);
        }
    }

    public function test_scanning_one_employee_returns_only_their_own_groups(): void
    {
        $pair = [
            $this->employee(['name' => 'First Pair', 'card_number' => '111111']),
            $this->employee(['name' => 'First Pair', 'card_number' => '111111']),
        ];
        $other = [
            $this->employee(['name' => 'Second Pair', 'card_number' => '222222']),
            $this->employee(['name' => 'Second Pair', 'card_number' => '222222']),
        ];

        $groups = $this->withToken($this->adminToken)
            ->getJson("/api/employees/{$pair[0]->id}/duplicates")
            ->assertOk()
            ->json('data.groups');

        $this->assertCount(1, $groups);
        $this->assertEqualsCanonicalizing(
            array_column($pair, 'id'),
            array_column($groups[0]['records'], 'id'),
        );

        // ...and the row menu on the other record answers about that record.
        $otherGroups = $this->withToken($this->adminToken)
            ->getJson("/api/employees/{$other[0]->id}/duplicates")
            ->assertOk()
            ->json('data.groups');

        $this->assertCount(1, $otherGroups);
        $this->assertEqualsCanonicalizing(
            array_column($other, 'id'),
            array_column($otherGroups[0]['records'], 'id'),
        );
    }

    public function test_an_employee_with_no_duplicates_gets_an_empty_scan_rather_than_an_error(): void
    {
        $this->employee(['name' => 'Sara Ahmed', 'card_number' => '900001']);
        $lonely = $this->employee(['name' => 'Yusuf Khalid', 'card_number' => '900002']);

        $response = $this->withToken($this->adminToken)
            ->getJson("/api/employees/{$lonely->id}/duplicates")
            ->assertOk();

        $this->assertSame([], $response->json('data.groups'));
        $this->assertSame(0, $response->json('data.counts.total'));
    }

    public function test_a_bare_name_shared_by_three_records_is_not_offered(): void
    {
        // Measured on the live roster: "Ahmed" matched seven records with seven
        // different codes. Three people sharing a surname is a common name, not
        // a duplicate — offering it would pool their hours.
        $this->employee(['name' => 'Mohammed Ahmed', 'card_number' => '300001']);
        $this->employee(['name' => 'Mohammed Ahmed', 'card_number' => '300002']);
        $this->employee(['name' => 'Mohammed Ahmed', 'card_number' => '300003']);

        $data = $this->scan()->assertOk()->json('data');

        $this->assertSame(0, $data['counts']['total']);
        $this->assertSame([], $data['groups']);
    }

    public function test_two_records_sharing_a_name_are_offered_as_possible_not_strong(): void
    {
        $this->employee(['name' => 'Mona Hassan', 'card_number' => '400001']);
        $this->employee(['name' => 'Mona Hassan', 'card_number' => '400002']);

        $data = $this->scan()->assertOk()->json('data');

        $this->assertSame(1, $data['counts']['possible']);
        $this->assertSame(0, $data['counts']['strong']);
        $this->assertContains('Same name', $data['groups'][0]['signals']);
    }

    public function test_a_read_only_user_can_scan_but_still_cannot_merge(): void
    {
        $a = $this->employee(['name' => 'Ali Jaafar', 'card_number' => '778899']);
        $b = $this->employee(['name' => 'Ali Jaafar', 'card_number' => '778899']);

        // The operator holds employees.view (so they can see the roster) but
        // neither employees.update nor employees.delete.
        $this->withToken($this->operatorToken)
            ->getJson('/api/employees/duplicate-groups')
            ->assertOk();

        $this->withToken($this->operatorToken)
            ->getJson("/api/employees/{$a->id}/duplicates")
            ->assertOk();

        $this->withToken($this->operatorToken)
            ->postJson('/api/employees/merge/preview', [
                'keep_id' => $a->id,
                'merge_ids' => [$b->id],
            ])
            ->assertOk();

        // ...and the write behind it is refused, as it was before Scan existed.
        $this->withToken($this->operatorToken)
            ->postJson('/api/employees/merge', [
                'keep_id' => $a->id,
                'merge_ids' => [$b->id],
            ])
            ->assertForbidden();
    }

    public function test_a_user_who_cannot_see_employees_cannot_scan(): void
    {
        Tenancy::clear();
        $outsider = User::withoutGlobalScopes()->create([
            'name' => 'Outsider',
            'email' => 'outsider@acme.test',
            'password' => Hash::make('test-password'),
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        // A role with no employees.* permissions at all.
        $outsider->syncRoles([]);
        Tenancy::clear();

        $token = $this->postJson('/api/auth/login', [
            'email' => 'outsider@acme.test',
            'password' => 'test-password',
        ])->json('data.token');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/employees/duplicate-groups')->assertForbidden();

        Tenancy::set($this->tenant->id);
    }

    public function test_another_companys_employee_id_cannot_be_previewed(): void
    {
        Tenancy::set(Tenant::create(['name' => 'Rival', 'slug' => 'rival'])->id);
        $foreignKeeper = $this->employee(['name' => 'Ali Jaafar', 'card_number' => '555555']);
        $foreignFolded = $this->employee(['name' => 'Ali Jaafar', 'card_number' => '555555']);

        Tenancy::set($this->tenant->id);
        $mine = $this->employee(['name' => 'Somebody Else', 'card_number' => '666666']);

        // Naming another company's record in a preview is refused rather than
        // answered — otherwise the screen would be a way to read it.
        $this->withToken($this->adminToken)
            ->postJson('/api/employees/merge/preview', [
                'keep_id' => $mine->id,
                'merge_ids' => [$foreignKeeper->id],
            ])
            ->assertNotFound();

        $this->withToken($this->adminToken)
            ->postJson('/api/employees/merge/preview', [
                'keep_id' => $foreignKeeper->id,
                'merge_ids' => [$foreignFolded->id],
            ])
            ->assertNotFound();
    }

    /** -------------------------------------------------------------- preview */

    public function test_the_preview_lists_every_field_the_survivor_is_missing(): void
    {
        $keeper = $this->employee([
            'name' => 'Ali Jaafar',
            'card_number' => '778899',
            'email' => null,
            'mobile_phone' => null,
            'job_title' => null,
            'home_address' => null,
        ]);
        $donor = $this->employee([
            'name' => 'Ali Jaafar',
            'card_number' => '778899',
            'email' => 'ali@example.com',
            'mobile_phone' => '+973 3344 5566',
            'job_title' => 'Driver',
            'home_address' => 'Road 24, Manama',
        ]);

        $preview = $this->preview($keeper, [$donor]);

        $labels = array_column($preview['filled'], 'label');
        foreach (['Email', 'Mobile phone', 'Job title', 'Home address'] as $expected) {
            $this->assertContains($expected, $labels, "the donor's {$expected} should be offered to a survivor missing it");
        }

        // Nothing to disagree about, so nothing is at risk of being dropped.
        $this->assertSame([], $preview['conflicts']);

        // The screen's actual promise: `fill` is the change set applied if they merge.
        $this->assertSame('ali@example.com', $preview['fill']['email']);
        $this->assertSame('Driver', $preview['fill']['job_title']);
    }

    public function test_the_preview_names_a_conflicting_value_instead_of_swallowing_it(): void
    {
        $keeper = $this->employee([
            'name' => 'Ali Jaafar',
            'card_number' => '778899',
            'job_title' => 'Driver',
            'mobile_phone' => '+973 1111 2222',
        ]);
        $donor = $this->employee([
            'name' => 'Ali Jaafar',
            'card_number' => '778899',
            'job_title' => 'Supervisor',
            'mobile_phone' => '+973 3333 4444',
        ]);

        $preview = $this->preview($keeper, [$donor]);

        $conflicts = collect($preview['conflicts'])->keyBy('field');
        $this->assertArrayHasKey('job_title', $conflicts);
        $this->assertSame('Driver', $conflicts['job_title']['keep']);
        $this->assertSame('Supervisor', $conflicts['job_title']['other']);
        $this->assertSame($donor->id, $conflicts['job_title']['other_id']);
        $this->assertSame($donor->name, $conflicts['job_title']['other_name']);

        $this->assertArrayHasKey('mobile_phone', $conflicts);

        // The survivor's values are NOT put in the change set — naming a conflict
        // and overwriting it with the same action would be two different promises.
        $this->assertArrayNotHasKey('job_title', $preview['fill']);
        $this->assertArrayNotHasKey('mobile_phone', $preview['fill']);
    }

    public function test_the_preview_changes_when_the_survivor_changes(): void
    {
        $withHistory = $this->employee([
            'name' => 'Ali Jaafar',
            'card_number' => '778899',
            'email' => 'kept@example.com',
            'job_title' => null,
        ]);
        $withDetails = $this->employee([
            'name' => 'Ali Jaafar',
            'card_number' => '778899',
            'email' => 'other@example.com',
            'job_title' => 'Driver',
        ]);

        $first = $this->preview($withHistory, [$withDetails]);
        $second = $this->preview($withDetails, [$withHistory]);

        // The record WITHOUT the title is the survivor, so the donor's title
        // comes across...
        $this->assertSame('Driver', $first['fill']['job_title']);

        // ...and when the record holding it survives, there is nothing to bring.
        // The screen must say so by changing, not keep showing the same promise.
        $this->assertArrayNotHasKey('job_title', $second['fill']);

        // The email differs either way, so it is a conflict either way — and the
        // survivor's value is the one reported as `keep` each time.
        $this->assertSame(
            'kept@example.com',
            collect($first['conflicts'])->firstWhere('field', 'email')['keep'],
        );
        $this->assertSame(
            'other@example.com',
            collect($second['conflicts'])->firstWhere('field', 'email')['keep'],
        );
    }

    /** ---------------------------------------------------------------- merge */

    public function test_a_merge_brings_across_everything_the_preview_promised(): void
    {
        $keeper = $this->employee([
            'name' => 'Ali Jaafar',
            'card_number' => '778899',
            'email' => null,
            'mobile_phone' => null,
            'job_title' => null,
            'home_phone' => null,
            'office' => null,
        ]);
        $donor = $this->employee([
            'name' => 'Ali Jaafar',
            'card_number' => '778899',
            'email' => 'ali@example.com',
            'mobile_phone' => '+973 3344 5566',
            'job_title' => 'Driver',
            'home_phone' => '+973 5555 6666',
            'office' => 'Manama',
        ]);

        $preview = $this->preview($keeper, [$donor]);
        $this->assertNotEmpty($preview['filled']);

        $this->withToken($this->adminToken)
            ->postJson('/api/employees/merge', [
                'keep_id' => $keeper->id,
                'merge_ids' => [$donor->id],
            ])
            ->assertOk();

        $survivor = Employee::withoutGlobalScopes()->findOrFail($keeper->id);
        $this->assertDatabaseHas('employees', ['id' => $keeper->id]);
        $this->assertDatabaseMissing('employees', ['id' => $donor->id]);

        foreach (['email' => 'ali@example.com', 'job_title' => 'Driver', 'office' => 'Manama'] as $column => $value) {
            $this->assertSame($value, $survivor->{$column}, "the donor's {$column} should have come across");
        }

        // Every field the preview promised is now filled — no promises made on
        // the screen that the merge does not keep.
        foreach ($preview['fill'] as $column => $value) {
            $this->assertSame($value, $survivor->{$column}, "{$column} was in the preview's change set");
        }
    }

    public function test_a_merge_records_what_it_could_not_bring_across(): void
    {
        $keeper = $this->employee([
            'name' => 'Ali Jaafar',
            'card_number' => '778899',
            'job_title' => 'Driver',
        ]);
        $donor = $this->employee([
            'name' => 'Ali Jaafar',
            'card_number' => '778899',
            'job_title' => 'Supervisor',
            'office' => 'Muharraq',
        ]);

        $this->withToken($this->adminToken)
            ->postJson('/api/employees/merge', [
                'keep_id' => $keeper->id,
                'merge_ids' => [$donor->id],
            ])
            ->assertOk();

        $entry = \App\Models\AuditLog::withoutGlobalScopes()
            ->where('action', 'employee.merged')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($keeper->id, $entry->auditable_id);

        $conflicts = collect($entry->changes['field_conflicts'])->keyBy('field');
        $this->assertArrayHasKey('job_title', $conflicts, 'the value that could not be preserved must be on the record');
        $this->assertSame('Driver', $conflicts['job_title']['keep']);
        $this->assertSame('Supervisor', $conflicts['job_title']['other']);
        $this->assertSame($donor->id, $conflicts['job_title']['other_id']);

        // What DID come across is recorded too, so the entry answers both
        // questions without a second query.
        $filled = array_column($entry->changes['fields_filled'], 'field');
        $this->assertContains('office', $filled);
    }

    /** -------------------------------------------------------------- helpers */

    private function scan(): TestResponse
    {
        return $this->withToken($this->adminToken)->getJson('/api/employees/duplicate-groups');
    }

    private function preview(Employee $keep, array $mergeIds): array
    {
        return $this->withToken($this->adminToken)
            ->postJson('/api/employees/merge/preview', [
                'keep_id' => $keep->id,
                'merge_ids' => array_map(fn (Employee $e) => $e->id, $mergeIds),
            ])
            ->assertOk()
            ->json('data');
    }

    private function employee(array $attrs = []): Employee
    {
        $this->seq++;

        return Employee::create(array_merge([
            'tenant_id' => Tenancy::id(),
            'name' => "Employee {$this->seq}",
            'employee_code' => "SC{$this->seq}",
            'is_active' => true,
        ], $attrs));
    }

    /** A terminal to hang attendance off — never contacted, only recorded against. */
    private function device(): Device
    {
        $this->seq++;

        return Device::create([
            'tenant_id' => Tenancy::id(),
            'name' => "Test Terminal {$this->seq}",
            'ip_address' => '192.168.7.'.random_int(2, 250),
            'port' => 4370,
            'protocol' => Device::PROTOCOL_BINARY,
            'is_active' => true,
        ]);
    }

    private function tokenFor(string $email, string $role): string
    {
        Tenancy::set($this->tenant->id);
        $user = User::withoutGlobalScopes()->create([
            'name' => $role,
            'email' => $email,
            'password' => Hash::make('test-password'),
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->syncRoles([$role]);
        Tenancy::clear();

        $token = $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'test-password',
        ])->json('data.token');

        $this->app['auth']->forgetGuards();

        return $token;
    }
}
