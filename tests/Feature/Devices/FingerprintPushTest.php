<?php

namespace Tests\Feature\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\Tenant;
use App\Services\Employees\FingerprintPusher;
use App\Services\Employees\FingerprintVault;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Value\DeviceUser;
use Tests\TestCase;

/**
 * Pushing stored fingerprints to a terminal that lacks them (2026-09-24) — how a
 * finger enrolled on the F18 reaches a C3 panel, which cannot capture one.
 *
 * Under test: the copy from another terminal (another protocol family) is written
 * byte-exact; a finger the terminal already holds is never sent; a terminal the
 * person is not on, one without the capability, and a person with no stored copy
 * are skipped with a reason; the audit row carries slots, never bytes.
 */
class FingerprintPushTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $employee;

    private Device $f18;

    private Device $panel;

    private FakeBiometricDriver $driver;

    private string $blob;

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();

        $this->tenant = Tenant::where('slug', 'acme')->firstOrFail();
        Tenancy::set($this->tenant->id);

        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Aisha Rahman', 'employee_code' => '501', 'is_active' => true,
        ]);
        $this->f18 = $this->device('IT Room', 'F18', Device::PROTOCOL_BINARY);
        $this->panel = $this->device('Glass Door', 'inBIO460', Device::PROTOCOL_C3);
        $this->map($this->f18, '501');
        $this->map($this->panel, '501');

        $this->driver = new FakeBiometricDriver;
        $this->driver->capabilityOverrides = [Capability::TEMPLATE_UPLOAD => Capability::IMPLEMENTED];
        $this->app->instance(BiometricDeviceDriver::class, $this->driver);

        // A copy taken from the F18: a ZKFinger v10 blob, binary.
        $this->blob = "\x4A\x2DSS21".random_bytes(600);
        $this->driver->templates = [['user_id' => '501', 'uid' => 1, 'slot' => 6, 'valid' => 1, 'size' => 606, 'sha1' => sha1($this->blob), 'template' => $this->blob]];
        app(FingerprintVault::class)->captureDevice($this->f18);
        $this->driver->templates = [];

        $this->driver->roster = [new DeviceUser(uid: 40, userId: '501', name: 'Aisha Rahman', fingerSlots: [])];
    }

    private function device(string $name, string $model, string $protocol): Device
    {
        return Device::create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'model' => $model,
            'ip_address' => '192.0.2.'.random_int(10, 250), 'port' => 4370,
            'protocol' => $protocol, 'adoption_status' => Device::ADOPTED, 'is_active' => true,
        ]);
    }

    private function map(Device $device, string $pin): void
    {
        EmployeeDeviceMap::create([
            'tenant_id' => $this->tenant->id, 'device_id' => $device->id, 'employee_id' => $this->employee->id,
            'device_user_id' => $pin, 'valid_from' => now()->subYear(),
        ]);
    }

    public function test_an_f18_copy_is_written_to_the_panel_byte_exact(): void
    {
        $rows = app(FingerprintPusher::class)->push($this->employee, [$this->panel]);

        $this->assertSame('written', $rows[0]['status'], $rows[0]['message']);
        $this->assertSame([6], $rows[0]['slots']);
        $this->assertSame([['user_id' => '501', 'slot' => 6, 'template' => $this->blob]], $this->driver->uploads);

        // The per-terminal slots were re-read from the device, not assumed.
        $this->assertSame([6], EmployeeDeviceMap::query()->where('device_id', $this->panel->id)->first()->fingerprint_slots);

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'employee.fingerprints_pushed')->firstOrFail();
        $this->assertSame([6], $audit->changes['slots_written']);
        $this->assertStringNotContainsString(base64_encode($this->blob), json_encode($audit->changes));
    }

    public function test_a_finger_the_terminal_already_holds_is_never_sent(): void
    {
        $this->driver->roster = [new DeviceUser(uid: 40, userId: '501', name: 'Aisha Rahman', fingerSlots: [6])];

        $rows = app(FingerprintPusher::class)->push($this->employee, [$this->panel]);

        $this->assertSame('unchanged', $rows[0]['status']);
        $this->assertSame([], $this->driver->uploads);
    }

    public function test_a_terminal_the_person_is_not_on_is_skipped(): void
    {
        EmployeeDeviceMap::query()->where('device_id', $this->panel->id)->delete();

        $rows = app(FingerprintPusher::class)->push($this->employee, [$this->panel]);

        $this->assertSame('skipped', $rows[0]['status']);
        $this->assertStringContainsString('push the person first', $rows[0]['message']);
        $this->assertSame([], $this->driver->uploads);
    }

    public function test_a_terminal_without_template_upload_is_skipped_not_failed(): void
    {
        $this->driver->capabilityOverrides = [Capability::TEMPLATE_UPLOAD => Capability::NOT_IMPLEMENTED];

        $rows = app(FingerprintPusher::class)->push($this->employee, [$this->panel]);

        $this->assertSame('skipped', $rows[0]['status']);
        $this->assertSame([], $this->driver->uploads);
    }

    public function test_a_blob_that_is_not_zkfinger_v10_is_never_offered(): void
    {
        $other = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'employee_code' => '502', 'is_active' => true]);
        EmployeeDeviceMap::create([
            'tenant_id' => $this->tenant->id, 'device_id' => $this->f18->id, 'employee_id' => $other->id,
            'device_user_id' => '502', 'valid_from' => now()->subYear(),
        ]);
        $this->driver->templates = [['user_id' => '502', 'uid' => 2, 'slot' => 1, 'valid' => 1, 'size' => 9, 'sha1' => sha1('not-a-v10'), 'template' => 'not-a-v10']];
        app(FingerprintVault::class)->captureDevice($this->f18);

        $this->assertSame([], app(FingerprintVault::class)->copiesFor($other->id, $this->panel));
    }

    public function test_a_device_that_drops_the_write_is_not_reported_as_written(): void
    {
        $this->driver->dropUploads = true;

        $rows = app(FingerprintPusher::class)->push($this->employee, [$this->panel]);

        $this->assertNotSame('written', $rows[0]['status']);
    }

    /*
     * The Akram scenario, 2026-10-11: removed from IT Room, added back, and the
     * terminal showed 0 fingers and no card. The terminal had been the only place his
     * finger existed, the removal deleted it with no copy taken, and the re-add wrote
     * neither the card nor a finger. Now: the removal copies the finger first, the copy
     * survives the removal, and adding him back writes the card and the finger.
     */
    public function test_remove_then_add_back_brings_the_finger_and_card_with_them(): void
    {
        \App\Models\BiometricTemplate::query()->delete(); // no copy taken before the removal
        $this->employee->update(['card_number' => 7553651]);
        $this->driver->roster = [new DeviceUser(uid: 40, userId: '501', name: 'Aisha Rahman', cardNumber: 7553651, fingerSlots: [6])];
        $this->driver->templates = [['user_id' => '501', 'uid' => 40, 'slot' => 6, 'valid' => 1, 'size' => 606, 'sha1' => sha1($this->blob), 'template' => $this->blob]];

        $users = app(\App\Services\Devices\DeviceUserSyncService::class);
        $users->removeEmployee($this->f18, $this->employee);

        $this->assertSame(['501'], $this->driver->deletes);
        $this->assertSame([6], app(FingerprintVault::class)->storedSlots($this->employee->id), 'the removal must keep a copy');

        // The terminal now has nobody; a read of it must not drop the copy or the card.
        $this->driver->templates = [];
        app(\App\Services\Devices\DeviceIdentityResolver::class)->claim($this->f18, $this->employee->fresh());
        $result = $users->pushEmployee($this->f18, $this->employee->fresh());

        // Back on the number they had — it was only ever theirs (Akram got PIN 2).
        $pin = $this->driver->roster[0]->userId;
        $this->assertSame('501', $pin);
        $this->assertSame([[$pin, 7553651]], $this->driver->cardWrites);
        $this->assertSame([['user_id' => $pin, 'slot' => 6, 'template' => $this->blob]], $this->driver->uploads);
        $this->assertStringContainsString('Card #7553651 written', $result['message']);
        $this->assertStringContainsString('Fingerprint(s) 6 written from the system copy', $result['message']);
        $this->assertSame(7553651, (int) $this->employee->fresh()->card_number);
        $this->assertSame([6], app(FingerprintVault::class)->storedSlots($this->employee->id));
    }

    public function test_a_removal_that_cannot_save_the_fingers_first_deletes_nothing(): void
    {
        $this->driver->roster = [new DeviceUser(uid: 40, userId: '501', name: 'Aisha Rahman', fingerSlots: [6])];
        $this->driver->templatesUnsupported = true;

        try {
            app(\App\Services\Devices\DeviceUserSyncService::class)->removeEmployee($this->f18, $this->employee);
            $this->fail('Expected the removal to be refused.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('could not be saved to the system first', $e->getMessage());
        }

        $this->assertSame([], $this->driver->deletes);
    }

    public function test_adding_someone_to_a_terminal_that_cannot_take_fingers_says_so(): void
    {
        $this->driver->capabilityOverrides = [Capability::TEMPLATE_UPLOAD => Capability::NOT_IMPLEMENTED];
        $this->driver->roster = [];

        $result = app(\App\Services\Devices\DeviceUserSyncService::class)->pushEmployee($this->f18, $this->employee);

        $this->assertSame([], $this->driver->uploads);
        $this->assertStringContainsString('cannot take a fingerprint from this app', $result['message']);
    }

    public function test_a_number_someone_else_once_held_is_never_given_back(): void
    {
        $other = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Former holder', 'employee_code' => '777', 'is_active' => true]);
        EmployeeDeviceMap::query()->where('device_id', $this->f18->id)->update(['valid_to' => now()]);
        EmployeeDeviceMap::create([
            'tenant_id' => $this->tenant->id, 'device_id' => $this->f18->id, 'employee_id' => $other->id,
            'device_user_id' => '501', 'valid_from' => now()->subYears(2), 'valid_to' => now()->subYear(),
        ]);

        $map = app(\App\Services\Devices\DeviceIdentityResolver::class)->claim($this->f18, $this->employee);

        $this->assertNotSame('501', $map->device_user_id);
    }

    /*
     * Cards, rule 19 (owner, 2026-10-11: "not just fingerprint but also card number").
     */
    public function test_a_card_only_the_terminal_knew_is_saved_on_removal_and_put_back(): void
    {
        $this->assertNull($this->employee->card_number);
        $this->driver->roster = [new DeviceUser(uid: 40, userId: '501', name: 'Aisha Rahman', cardNumber: 9912345, fingerSlots: [])];
        $this->driver->templates = [];

        $users = app(\App\Services\Devices\DeviceUserSyncService::class);
        $users->removeEmployee($this->f18, $this->employee);
        $this->assertSame(9912345, (int) $this->employee->fresh()->card_number, 'saved to the record before the delete');

        app(\App\Services\Devices\DeviceIdentityResolver::class)->claim($this->f18, $this->employee->fresh());
        $users->pushEmployee($this->f18, $this->employee->fresh());

        $this->assertSame([['501', 9912345]], $this->driver->cardWrites);
    }

    public function test_the_card_this_terminal_had_wins_over_the_record_card(): void
    {
        $this->employee->update(['card_number' => 1111111]);
        $this->driver->roster = [new DeviceUser(uid: 40, userId: '501', name: 'Aisha Rahman', cardNumber: 2222222, fingerSlots: [])];
        $this->driver->templates = [];

        $users = app(\App\Services\Devices\DeviceUserSyncService::class);
        $users->removeEmployee($this->f18, $this->employee);
        $this->assertSame(1111111, (int) $this->employee->fresh()->card_number, 'the record card is not overwritten');

        app(\App\Services\Devices\DeviceIdentityResolver::class)->claim($this->f18, $this->employee->fresh());
        $users->pushEmployee($this->f18, $this->employee->fresh());

        $this->assertSame([['501', 2222222]], $this->driver->cardWrites);
    }

    public function test_a_card_cleared_on_purpose_or_reported_lost_is_not_put_back(): void
    {
        $this->employee->update(['card_number' => 3333333]);
        \App\Models\AccessCard::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'device_id' => $this->f18->id,
            'card_number' => 3333333, 'status' => \App\Models\AccessCard::REVOKED,
            'revoke_reason' => \App\Models\AccessCard::REASON_CLEARED, 'assigned_at' => now()->subDay(), 'revoked_at' => now(),
        ]);
        $users = app(\App\Services\Devices\DeviceUserSyncService::class);
        $this->assertNull($users->cardToRestore($this->f18, $this->employee));

        // No history on the panel, so the record card applies — unless it was lost.
        $this->assertSame(3333333, $users->cardToRestore($this->panel, $this->employee));
        \App\Models\AccessCard::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'device_id' => $this->f18->id,
            'card_number' => 3333333, 'status' => \App\Models\AccessCard::LOST, 'assigned_at' => now(),
        ]);
        $this->assertNull($users->cardToRestore($this->panel, $this->employee));
    }

    public function test_a_roster_read_puts_a_terminal_only_card_on_the_record(): void
    {
        $this->driver->roster = [new DeviceUser(uid: 40, userId: '501', name: 'Aisha Rahman', cardNumber: 4444444, fingerSlots: [])];
        $this->driver->templates = [];

        app(\App\Services\Devices\DeviceUserSyncService::class)->syncEnrollmentState($this->f18);

        $this->assertSame(4444444, (int) $this->employee->fresh()->card_number);
    }
}
