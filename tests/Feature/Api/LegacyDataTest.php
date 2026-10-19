<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The legacy-import/export screen: upload .json (or .mdb read server-side),
 * preview = dry run only, apply = replay those exact bytes, export = download
 * a snapshot in the same canonical shape. These tests pin the round trip —
 * an export of a just-applied import must decode back to the same punch key —
 * and the two-step safety property (preview writes nothing).
 */
class LegacyDataTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'test-password';

    private string $email = 'legacy@acme.test';

    /** A pin no seeder employee can already own. */
    private const NEW_PIN = '77777';

    private string $deviceSerial = 'TEST-SN-0001';

    private string $deviceIp = '192.168.9.9';

    private Tenant $acme;

    /** Local wall clock as the terminals record it (Iraq, UTC+3). */
    private const PUNCH_LOCAL = '2020-02-05 14:31:33';

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();

        $this->acme = Tenant::where('slug', 'acme')->firstOrFail();
        Tenancy::set($this->acme->id);
        $user = User::create([
            'name' => 'Legacy Admin',
            'email' => $this->email,
            'password' => Hash::make(self::PASSWORD),
            'tenant_id' => $this->acme->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->syncRoles(['tenant-admin']);
        Tenancy::clear();

        Storage::fake('local');

        Device::create([
            'tenant_id' => $this->acme->id,
            'name' => 'Legacy Test Terminal',
            'serial_number' => $this->deviceSerial,
            'ip_address' => $this->deviceIp,
        ]);
    }

    /** One canonical snapshot referencing a fresh user and the test terminal. */
    private function snapshotFixture(): array
    {
        return [
            'source' => 'snapshot.json',
            'exported_at' => '2026-10-06 12:00:00',
            'departments' => [
                ['id' => '901', 'name' => 'Legacy Dept', 'parent_id' => '', 'code' => 'LD'],
            ],
            'machines' => [
                ['id' => '402', 'name' => 'Legacy Test Terminal', 'serial' => $this->deviceSerial,
                    'ip' => $this->deviceIp, 'product' => 'Test', 'firmware' => '', 'machine_number' => ''],
            ],
            'users' => [
                [
                    'user_id' => '77', 'pin' => self::NEW_PIN, 'first_name' => 'Zk', 'last_name' => 'Restored',
                    'gender' => '', 'card' => '', 'hire_date' => '', 'birth_date' => '',
                    'department_id' => '901', 'privilege' => '', 'password' => '', 'email' => '',
                    'mobile_phone' => '', 'home_phone' => '', 'home_address' => '', 'street' => '',
                    'city' => '', 'country' => '', 'education' => '', 'political' => '', 'job_title' => '',
                    'emptype' => '', 'status' => '', 'deleted' => '0',
                ],
            ],
            'punches' => [
                [
                    'user_id' => '77', 'pin' => self::NEW_PIN, 'serial' => $this->deviceSerial,
                    'at' => self::PUNCH_LOCAL, 'type' => 'I', 'verify' => 0,
                ],
            ],
        ];
    }

    private function upload(): TestResponse
    {
        $file = UploadedFile::fake()->createWithContent(
            'snapshot.json',
            json_encode($this->snapshotFixture(), JSON_THROW_ON_ERROR),
        );

        return $this->authorized()->post('/api/legacy/import/preview', ['file' => $file]);
    }

    /**
     * Logs in over the real endpoint and forgets the guards (one app instance is
     * shared across tests, so a cached RequestGuard must not leak between them).
     */
    private function authorized(): static
    {
        $token = $this->postJson('/api/auth/login', [
            'email' => $this->email,
            'password' => self::PASSWORD,
        ])->json('data.token');

        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_preview_writes_nothing_and_reports_the_dry_run(): void
    {
        $response = $this->upload()->assertOk()->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertNotEmpty($data['token']);
        $this->assertSame('snapshot.json', $data['source']);
        $this->assertSame(1, $data['counts']['users']);
        $this->assertSame(1, $data['counts']['punches']);

        $report = $data['report'];
        $this->assertSame(1, $report['departments']['created']);
        $this->assertSame(1, $report['employees']['created']);
        $this->assertSame(1, $report['attendance']['candidates']);
        $this->assertSame(0, $report['attendance']['duplicates']);

        // The dry run must not touch the database.
        $this->assertSame(0, Employee::withoutGlobalScopes()->where('tenant_id', $this->acme->id)->where('employee_code', self::NEW_PIN)->count());
        $this->assertSame(0, Department::where('tenant_id', $this->acme->id)->where('name', 'Legacy Dept')->count());
        $this->assertSame(0, AttendanceLog::where('tenant_id', $this->acme->id)->count());
    }

    public function test_apply_creates_and_is_idempotent(): void
    {
        $token = $this->upload()->json('data.token');

        $apply = $this->authorized()->post("/api/legacy/import/{$token}/apply")->assertOk();
        $report = $apply->json('data.report');

        $this->assertSame(1, $report['employees']['created']);
        $this->assertSame(1, $report['attendance']['candidates']);
        $this->assertSame(1, $report['attendance']['inserted']);

        $employee = Employee::withoutGlobalScopes()->where('employee_code', self::NEW_PIN)->firstOrFail();
        $this->assertSame($this->acme->id, $employee->tenant_id);
        $this->assertSame(Department::where('tenant_id', $this->acme->id)->where('name', 'Legacy Dept')->firstOrFail()->id, $employee->department_id);

        $punch = AttendanceLog::where('tenant_id', $this->acme->id)->firstOrFail();
        $this->assertSame(self::NEW_PIN, $punch->device_user_id);
        $this->assertSame($employee->id, $punch->employee_id);
        $this->assertSame('2020-02-05 11:31:33', $punch->punched_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $punch->status);
        $this->assertSame(0, (int) $punch->verify_method);

        // Applying the same token again must change nothing (devices+pin+utc+verify dedupe).
        $again = $this->authorized()->post("/api/legacy/import/{$token}/apply")->assertOk();
        $againReport = $again->json('data.report');
        $this->assertSame(0, $againReport['employees']['created']);
        $this->assertSame(1, $againReport['attendance']['duplicates']);
        $this->assertSame(0, $againReport['attendance']['inserted']);
        $this->assertSame(1, AttendanceLog::where('tenant_id', $this->acme->id)->count());
    }

    public function test_export_snapshot_round_trips_the_applied_punch(): void
    {
        $token = $this->upload()->json('data.token');
        $this->authorized()->post("/api/legacy/import/{$token}/apply")->assertOk();

        $export = $this->authorized()->get('/api/legacy/export')->assertOk();

        $this->assertStringContainsString('attachment; filename="zkaccess-snapshot-', $export->headers->get('content-disposition'));
        $this->assertStringContainsString('application/json', $export->headers->get('content-type'));

        $snapshot = json_decode($export->getContent(), true);

        $this->assertArrayHasKey('source', $snapshot);
        $this->assertArrayHasKey('exported_at', $snapshot);
        $this->assertArrayHasKey('departments', $snapshot);
        $this->assertArrayHasKey('machines', $snapshot);
        $this->assertArrayHasKey('users', $snapshot);
        $this->assertArrayHasKey('punches', $snapshot);

        // The restored employee and his punch are in the snapshot.
        $pins = array_column($snapshot['users'], 'pin');
        $this->assertContains(self::NEW_PIN, $pins);

        $matching = array_values(array_filter(
            $snapshot['punches'],
            fn (array $p): bool => ($p['pin'] ?? '') === self::NEW_PIN,
        ));
        $this->assertCount(1, $matching);
        // Terminal local wall clock, so the key round-trips back to the same UTC.
        $this->assertSame(self::PUNCH_LOCAL, $matching[0]['at']);
        $this->assertSame('I', $matching[0]['type']);
    }

    public function test_rejects_non_snapshot_uploads(): void
    {
        $other = UploadedFile::fake()->createWithContent('notes.txt', 'not a snapshot');
        $this->authorized()->post('/api/legacy/import/preview', ['file' => $other])
            ->assertStatus(422);

        $badJson = UploadedFile::fake()->createWithContent('bad.json', '{"users": []}');
        $response = $this->authorized()->post('/api/legacy/import/preview', ['file' => $badJson]);
        $response->assertStatus(422);
        $this->assertStringContainsString('Not a ZKAccess snapshot', $response->json('message'));
    }

    public function test_apply_for_unknown_token_is_404(): void
    {
        $this->authorized()->post('/api/legacy/import/aaaaaaaaaaaaaaaaaaaaaaaa/apply')->assertStatus(404);
    }

    public function test_legacy_routes_require_authentication(): void
    {
        $this->get('/api/legacy/export')->assertStatus(401);
        $this->post('/api/legacy/import/preview')->assertStatus(401);
    }
}
