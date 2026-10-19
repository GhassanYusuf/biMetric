<?php

namespace Tests\Feature\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\AuditLog;
use App\Models\BiometricTemplate;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Employees\FingerprintVault;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The system's copy of each employee's fingerprints (owner's decision, 2026-09-24).
 *
 * Under test: copies are stored ENCRYPTED and come back byte-exact for a device
 * write; they never leave through the API or the audit log; a decoded table's absence
 * drops a copy but a failed read never does; copies from different terminals stay
 * separate.
 */
class FingerprintVaultTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $employee;

    private Device $f18;

    private FakeBiometricDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();

        $this->tenant = Tenant::where('slug', 'acme')->firstOrFail();
        Tenancy::set($this->tenant->id);

        $user = User::withoutGlobalScopes()->create([
            'name' => 'Admin', 'email' => 'admin@acme.test', 'password' => Hash::make('pw-test-123'),
            'tenant_id' => $this->tenant->id, 'is_active' => true, 'email_verified_at' => now(),
        ]);
        $user->syncRoles(['tenant-admin']);

        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Aisha Rahman', 'employee_code' => '501', 'is_active' => true,
        ]);
        $this->f18 = $this->device('IT Room', 'F18');
        $this->map($this->f18, '501');

        $this->driver = new FakeBiometricDriver();
        $this->app->instance(BiometricDeviceDriver::class, $this->driver);
    }

    private function device(string $name, string $model): Device
    {
        return Device::create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'model' => $model,
            'ip_address' => '192.0.2.'.random_int(10, 250), 'port' => 4370,
            'protocol' => Device::PROTOCOL_BINARY, 'adoption_status' => Device::ADOPTED, 'is_active' => true,
        ]);
    }

    private function map(Device $device, string $pin): void
    {
        EmployeeDeviceMap::create([
            'tenant_id' => $this->tenant->id, 'device_id' => $device->id, 'employee_id' => $this->employee->id,
            'device_user_id' => $pin, 'valid_from' => now()->subYear(),
        ]);
    }

    /** A record as the drivers return it; the blob is deliberately binary. */
    private function rec(string $pin, int $slot, string $blob): array
    {
        return ['user_id' => $pin, 'uid' => 1, 'slot' => $slot, 'valid' => 1, 'size' => strlen($blob), 'sha1' => sha1($blob), 'template' => $blob];
    }

    public function test_copies_are_encrypted_at_rest_and_come_back_byte_exact(): void
    {
        $blob = "\x00\xFFSS21\x01\x02".random_bytes(40);
        $this->driver->templates = [$this->rec('501', 5, $blob), $this->rec('999', 1, 'someone-else')];

        $result = app(FingerprintVault::class)->captureDevice($this->f18);

        $this->assertSame(1, $result['stored']);
        $raw = DB::table('biometric_templates')->where('employee_id', $this->employee->id)->value('template');
        $this->assertStringNotContainsString(base64_encode($blob), $raw, 'the column must hold ciphertext');

        $copies = app(FingerprintVault::class)->templatesFor($this->employee->id, Device::PROTOCOL_BINARY, 'F18');
        $this->assertSame($blob, $copies[0]['template']);
        $this->assertSame(5, $copies[0]['fid']);

        // Nobody unmapped on this terminal gets a row.
        $this->assertSame(1, BiometricTemplate::query()->count());
    }

    public function test_the_api_and_audit_log_never_carry_the_bytes(): void
    {
        $this->driver->templates = [$this->rec('501', 5, 'SECRET-TEMPLATE-BYTES')];
        app(FingerprintVault::class)->captureDevice($this->f18);
        Tenancy::clear();

        $token = $this->postJson('/api/auth/login', ['email' => 'admin@acme.test', 'password' => 'pw-test-123'])->json('data.token');
        $this->app['auth']->forgetGuards();
        $res = $this->withToken($token)->getJson('/api/employees')->assertOk();

        $row = collect($res->json('data'))->firstWhere('id', $this->employee->id);
        $this->assertSame([5], $row['stored_fingerprint_slots']);
        $this->assertStringNotContainsString('SECRET-TEMPLATE-BYTES', $res->getContent());
        $this->assertStringNotContainsString(base64_encode('SECRET-TEMPLATE-BYTES'), $res->getContent());

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'employee.fingerprints_captured')->firstOrFail();
        $this->assertSame(1, $audit->changes['stored']);
        $this->assertStringNotContainsString('SECRET', json_encode($audit->changes));
    }

    /*
     * Owner's rule, 2026-10-11: the system's copy outlives the terminal's. It used to
     * be dropped when the finger left the terminal — which is the one moment it is
     * needed (Akram, removed from IT Room and re-added with nothing to put back).
     */
    public function test_a_finger_gone_from_the_terminal_keeps_its_stored_copy(): void
    {
        $vault = app(FingerprintVault::class);
        $this->driver->templates = [$this->rec('501', 5, 'A'), $this->rec('501', 6, 'B')];
        $vault->captureDevice($this->f18);

        // The table decoded and slot 6 is no longer there: the copy stays.
        $this->driver->templates = [$this->rec('501', 5, 'A')];
        $this->assertSame(0, $vault->captureDevice($this->f18)['dropped']);
        $this->assertSame([5, 6], $vault->storedSlots($this->employee->id));

        // Unsupported / failed read: nothing captured, nothing deleted.
        $this->driver->templatesUnsupported = true;
        try {
            $vault->captureDevice($this->f18);
        } catch (\Throwable) {
        }
        $this->assertSame([5, 6], $vault->storedSlots($this->employee->id));
    }

    public function test_a_re_enrolled_finger_is_refreshed_and_copies_per_terminal_stay_apart(): void
    {
        $vault = app(FingerprintVault::class);
        $f22 = $this->device('Glass Door', 'F22');
        $this->map($f22, '77');

        $this->driver->templates = [$this->rec('501', 5, 'from-f18'), $this->rec('77', 5, 'from-f22')];
        $vault->captureDevice($this->f18);
        $vault->captureDevice($f22);

        $this->driver->templates = [$this->rec('501', 5, 'from-f18-reenrolled')];
        $this->assertSame(1, $vault->captureDevice($this->f18)['refreshed']);

        // A model's copies are only offered for that model (rule 15).
        $this->assertSame('from-f18-reenrolled', $vault->templatesFor($this->employee->id, Device::PROTOCOL_BINARY, 'F18')[0]['template']);
        $this->assertSame('from-f22', $vault->templatesFor($this->employee->id, Device::PROTOCOL_BINARY, 'F22')[0]['template']);
    }

    public function test_read_fingerprints_to_system_copies_one_person_and_writes_nothing(): void
    {
        $this->driver->templates = [$this->rec('501', 5, 'A'), $this->rec('501', 6, 'B'), $this->rec('999', 1, 'other')];
        Tenancy::clear();

        $token = $this->postJson('/api/auth/login', ['email' => 'admin@acme.test', 'password' => 'pw-test-123'])->json('data.token');
        $this->app['auth']->forgetGuards();

        $res = $this->withToken($token)->postJson("/api/employees/{$this->employee->id}/fingerprints/read")->assertOk();

        $this->assertSame([5, 6], $res->json('data.stored_fingerprint_slots'));
        $this->assertSame('read', $res->json('data.terminals.0.status'));
        $this->assertStringContainsString('2 finger(s) now stored', $res->json('message'));
        // A read must not write to any terminal, in any way.
        $this->assertSame([], $this->driver->writes);
        $this->assertSame([], $this->driver->deletes);
        $this->assertSame([], $this->driver->cardWrites);
        $this->assertSame([], $this->driver->renumbers);
        $this->assertSame(2, BiometricTemplate::withoutGlobalScopes()->whereNotNull('template_sha1')->count());
    }

    public function test_someone_on_no_terminal_gets_a_plain_answer(): void
    {
        EmployeeDeviceMap::query()->delete();
        Tenancy::clear();

        $token = $this->postJson('/api/auth/login', ['email' => 'admin@acme.test', 'password' => 'pw-test-123'])->json('data.token');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->postJson("/api/employees/{$this->employee->id}/fingerprints/read")
            ->assertOk()
            ->assertJsonPath('data.terminals', [])
            ->assertJsonPath('message', 'Aisha Rahman is not on any terminal, so there is nothing to read.');
    }
}
