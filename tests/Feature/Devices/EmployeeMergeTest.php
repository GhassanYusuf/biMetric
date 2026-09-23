<?php

namespace Tests\Feature\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\Tenant;
use App\Services\Employees\DuplicateEmployeeFinder;
use App\Services\Employees\EmployeeMergeService;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Merging the duplicate employments a per-terminal roster import leaves behind.
 *
 * The property that makes this safe, and the one most worth pinning down: the merge
 * touches NO DEVICE. The terminals keep their users at their PINs with their
 * fingerprints; only the employee row those mappings point at changes. The fake driver
 * throws on every call, so any device contact fails the test rather than passing
 * quietly.
 */
class EmployeeMergeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        Tenancy::set($this->tenant);

        // Any device call at all is a failure here.
        $this->app->instance(BiometricDeviceDriver::class, new class extends FakeBiometricDriver {
            public function readUsers(Device $d, bool $withFingerprints = false): array
            {
                throw new \LogicException('Merging must never contact a device.');
            }
        });
    }

    public function test_one_person_on_four_terminals_becomes_one_record_keeping_every_mapping(): void
    {
        $devices = collect(['IT Room', 'Glass Door', 'Entrance', 'On The Road'])
            ->map(fn ($n) => $this->device($n));

        // The import's output: the same human, once per terminal, same PIN, same card.
        $employees = $devices->map(fn (Device $d, $i) => $this->enrolled($d, 'ALI JAAFAR', '654', 7511517, punches: [120, 40, 8, 2][$i]));

        $groups = app(DuplicateEmployeeFinder::class)->find();
        $this->assertCount(1, $groups);
        $this->assertSame(DuplicateEmployeeFinder::CONFIRMED, $groups[0]['verdict']);

        // The record holding the most history is kept, so the least attendance moves.
        $keep = $employees[0];
        $this->assertSame($keep->id, $groups[0]['keep']);

        app(EmployeeMergeService::class)->merge($groups[0]['keep'], $groups[0]['merge']);

        $this->assertSame(1, Employee::count());
        $this->assertSame(170, AttendanceLog::where('employee_id', $keep->id)->count());

        // Four live mappings on one employee — one per terminal. That is the normal
        // shape, not a conflict: being enrolled on four devices is what happened.
        $maps = EmployeeDeviceMap::where('employee_id', $keep->id)->live()->get();
        $this->assertCount(4, $maps);
        $this->assertSame(['654'], $maps->pluck('device_user_id')->unique()->values()->all());
        $this->assertSame(4, $maps->pluck('device_id')->unique()->count());
    }

    public function test_a_shared_first_name_at_different_pins_is_never_grouped(): void
    {
        $a = $this->device('Glass Door');
        $b = $this->device('Entrance');

        // Two different men called Mohammed, each on both panels.
        $this->enrolled($a, 'MOHAMMAD', '283', 111);
        $this->enrolled($b, 'MOHAMMAD', '283', 111);
        $this->enrolled($a, 'MOHAMMAD', '285', 222);
        $this->enrolled($b, 'MOHAMMAD', '285', 222);

        $groups = app(DuplicateEmployeeFinder::class)->find();

        // Two groups, one per PIN — never one group of four.
        $this->assertCount(2, $groups);
        $this->assertSame(['283', '285'], $groups->pluck('pin')->sort()->values()->all());
        foreach ($groups as $g) {
            $this->assertCount(1, $g['merge']);
        }
    }

    public function test_a_shared_pin_namespace_makes_the_pin_the_person(): void
    {
        $a = $this->device('IT Room');
        $b = $this->device('Entrance');
        $this->declareNamespace([$a, $b]);

        // No card, no template — nothing that previously counted as evidence.
        $this->enrolled($a, 'Deepak', '437');
        $this->enrolled($b, 'Deepak', '437');

        $group = app(DuplicateEmployeeFinder::class)->find()->firstWhere('pin', '437');

        /*
         * One ZKAccess install maintains a single employee list and pushes the same
         * badge number to every terminal it manages, so inside that set a PIN IS the
         * person — by construction, not by resemblance. Confirmed against the vendor
         * database on 2026-09-15: 320 PINs, all present in USERINFO, 669 names
         * agreeing exactly.
         */
        $this->assertSame(DuplicateEmployeeFinder::NAMESPACED, $group['verdict']);
    }

    public function test_a_group_reaching_outside_the_namespace_falls_back_to_weaker_evidence(): void
    {
        $managed = $this->device('Entrance');
        $outside = $this->device('Camelot');           // a separate vendor install
        $this->declareNamespace([$managed]);

        $this->enrolled($managed, 'Monir', '230');
        $this->enrolled($outside, 'Monir', '230');

        $group = app(DuplicateEmployeeFinder::class)->find()->firstWhere('pin', '230');

        // The same PIN on an unmanaged terminal is a DIFFERENT namespace, and on this
        // estate that regularly means a different person. The strong claim must not
        // leak across the boundary.
        $this->assertSame(DuplicateEmployeeFinder::UNCONFIRMED, $group['verdict']);
    }

    public function test_a_record_outside_the_namespace_cannot_veto_the_records_inside_it(): void
    {
        $glass = $this->device('Glass Door');
        $entrance = $this->device('Entrance');
        $camelot = $this->device('Camelot');          // a separate vendor install
        $this->declareNamespace([$glass, $entrance]);

        $a = $this->enrolled($glass, 'Monir', '230', punches: 5);
        $b = $this->enrolled($entrance, 'Monir', '230', punches: 40);
        $c = $this->enrolled($camelot, 'Monir', '230');

        $groups = app(DuplicateEmployeeFinder::class)->find()->where('pin', '230')->values();

        /*
         * THE BUG THIS PINS DOWN, which cost four real people a merge: the group spans
         * the namespace AND a separate install, and judging it as one unit let the
         * outsider drag the whole thing to "unconfirmed" — leaving Glass Door and
         * Entrance duplicated, where the evidence was never in doubt.
         *
         * The two managed records must be merged; the Camelot record must be left
         * alone, because there a shared PIN really can be somebody else.
         */
        $this->assertCount(1, $groups, 'only the namespaced pair is a duplicate');
        $this->assertSame(DuplicateEmployeeFinder::NAMESPACED, $groups[0]['verdict']);
        $this->assertSame($b->id, $groups[0]['keep']);
        $this->assertSame([$a->id], $groups[0]['merge']);
        $this->assertNotContains($c->id, $groups[0]['merge']);

        app(EmployeeMergeService::class)->merge($groups[0]['keep'], $groups[0]['merge']);

        // Camelot's Monir survives as a separate person.
        $this->assertNotNull(Employee::find($c->id));
        $this->assertSame(2, Employee::count());
    }

    public function test_an_identical_template_outranks_disagreeing_cards(): void
    {
        $a = $this->device('IT Room');
        $b = $this->device('On The Road');

        // "Ragesh" PIN 261, as read off the real estate on 2026-09-15: a different card
        // at each site, but the SAME 1,054-byte template blob on both terminals.
        $one = $this->enrolled($a, 'Ragesh', '261', 10530436);
        $two = $this->enrolled($b, 'Ragesh', '261', 7459039);

        $shared = '3:840cfbda340c377f13d2c5b0a3e0a1e8c9d4b7a2';
        EmployeeDeviceMap::where('employee_id', $one->id)->update(['template_digests' => json_encode([$shared])]);
        EmployeeDeviceMap::where('employee_id', $two->id)->update(['template_digests' => json_encode([$shared])]);

        $group = app(DuplicateEmployeeFinder::class)->find()->firstWhere('pin', '261');

        /*
         * A card is reissued when one is lost; a fingerprint is not. So the template
         * beats the card rather than the disagreement blocking the merge — and this
         * exact person is why the rule exists.
         */
        $this->assertSame(DuplicateEmployeeFinder::PROVEN, $group['verdict']);
    }

    public function test_a_template_digest_on_only_one_terminal_proves_nothing(): void
    {
        $a = $this->device('IT Room');
        $b = $this->device('Entrance');

        $one = $this->enrolled($a, 'Deepak', '437');
        $this->enrolled($b, 'Deepak', '437');

        // Present on one side only — the C3 panel cannot report templates at all.
        EmployeeDeviceMap::where('employee_id', $one->id)
            ->update(['template_digests' => json_encode(['3:aaaabbbbccccddddeeeeffff0000111122223333'])]);

        $group = app(DuplicateEmployeeFinder::class)->find()->firstWhere('pin', '437');

        $this->assertSame(DuplicateEmployeeFinder::UNCONFIRMED, $group['verdict']);
    }

    public function test_different_digests_never_count_as_evidence_of_different_people(): void
    {
        $a = $this->device('IT Room');
        $b = $this->device('On The Road');

        $one = $this->enrolled($a, 'Ranjith', '232', 777);
        $two = $this->enrolled($b, 'Ranjith', '232', 777);

        // Two separate enrolments of one finger produce different templates. There is
        // no matcher here, so a mismatch must fall back to the card, not contradict it.
        EmployeeDeviceMap::where('employee_id', $one->id)->update(['template_digests' => json_encode(['3:'.str_repeat('a', 40)])]);
        EmployeeDeviceMap::where('employee_id', $two->id)->update(['template_digests' => json_encode(['3:'.str_repeat('b', 40)])]);

        $group = app(DuplicateEmployeeFinder::class)->find()->firstWhere('pin', '232');

        $this->assertSame(DuplicateEmployeeFinder::CONFIRMED, $group['verdict']);
        $this->assertSame('same card on every terminal', $group['reason']);
    }

    public function test_disagreeing_cards_are_flagged_and_not_merged(): void
    {
        $a = $this->device('IT Room');
        $b = $this->device('Camelot');

        $this->enrolled($a, 'Ragesh', '261', 10530436);
        $this->enrolled($b, 'Ragesh', '261', 7459039);

        $groups = app(DuplicateEmployeeFinder::class)->find();

        $this->assertSame(DuplicateEmployeeFinder::CONTRADICTED, $groups[0]['verdict']);

        $this->artisan('employees:merge', ['--verdict' => 'all', '--dry-run' => true])
            ->expectsOutputToContain('contradicted by the cards')
            ->assertSuccessful();

        // Nothing selected it, so both records survive.
        $this->assertSame(2, Employee::count());
    }

    public function test_a_card_that_two_pins_share_is_not_treated_as_evidence(): void
    {
        $a = $this->device('IT Room');
        $b = $this->device('Entrance');

        // One card number against two different PINs: it identifies nobody.
        $this->enrolled($a, 'Ahmed', '648', 833557);
        $this->enrolled($b, 'Ahmed', '648', 833557);
        $this->enrolled($a, 'Asharaf', '655', 833557);

        $group = app(DuplicateEmployeeFinder::class)->find()->firstWhere('pin', '648');

        $this->assertSame(DuplicateEmployeeFinder::UNCONFIRMED, $group['verdict']);
        $this->assertSame('card is shared by another PIN', $group['reason']);
    }

    public function test_employments_at_two_companies_are_refused(): void
    {
        $other = Tenant::create(['name' => 'Globex', 'slug' => 'globex']);

        $here = $this->employee('Ali Jaafar', 'E-1', $this->tenant->id);
        $there = $this->employee('Ali Jaafar', 'B-1', $other->id);

        $this->expectExceptionMessageMatches('/different companies/');

        // One human at two employers holds TWO employments. Merging them would erase
        // that; the Person layer is what links them.
        app(EmployeeMergeService::class)->merge($here->id, [$there->id]);
    }

    // ------------------------------------------------------------------- helpers

    /** Declares that one vendor tool keeps these terminals in step. */
    private function declareNamespace(array $devices): void
    {
        $group = \App\Models\DeviceGroup::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Managed '.uniqid(),
            'shared_pin_namespace' => true,
            'pin_namespace_evidence' => 'test',
        ]);

        foreach ($devices as $d) {
            \DB::table('device_group_members')->insert([
                'device_group_id' => $group->id,
                'device_id' => $d->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function device(string $name): Device
    {
        return Device::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'ip_address' => '192.168.7.'.random_int(2, 250),
            'port' => 4370,
            'protocol' => Device::PROTOCOL_BINARY,
            'is_active' => true,
        ]);
    }

    private function employee(string $name, string $code, int $tenantId): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'employee_code' => $code,
            'is_active' => true,
        ]);
    }

    /** One employee, as the per-device roster import would have created them. */
    private function enrolled(Device $device, string $name, string $pin, ?int $card = null, int $punches = 0): Employee
    {
        $employee = $this->employee($name, "{$pin}-{$device->id}", $this->tenant->id);

        EmployeeDeviceMap::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $employee->id,
            'device_id' => $device->id,
            'device_user_id' => $pin,
            'card_number' => $card,
            'valid_from' => null,
        ]);

        for ($i = 0; $i < $punches; $i++) {
            AttendanceLog::create([
                'tenant_id' => $this->tenant->id,
                'employee_id' => $employee->id,
                'device_id' => $device->id,
                'device_user_id' => $pin,
                'punched_at' => now()->subMinutes($i + 1),
                'verify_method' => 1,
                'status' => 0,
            ]);
        }

        return $employee;
    }
}
