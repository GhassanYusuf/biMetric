<?php

namespace Tests\Feature\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\BiometricTemplate;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceRosterEntry;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Devices\DeviceRebuildService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Promoseven\Biometric\Value\DeviceUser;

/**
 * "Rebuild from system" (2026-10-11, casebook DC-017): one terminal made to hold exactly
 * what the system says, without a literal wipe.
 *
 * Under test: the preview is read-only and names every change; the start refuses a
 * read-only terminal; the backup step refuses until punches were downloaded, then saves
 * the whole terminal and queues one command per change (people already correct are not
 * touched); a person's command gives them the code, name, card and fingers; an unlinked
 * record is kept unless removal was ticked, and is saved before it goes.
 */
class DeviceRebuildTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Device $device;

    private Employee $aisha;

    private Employee $omar;

    private FakeBiometricDriver $driver;

    private string $five;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();

        $this->tenant = Tenant::where('slug', 'acme')->firstOrFail();
        Tenancy::set($this->tenant->id);

        $admin = User::withoutGlobalScopes()->create([
            'name' => 'Admin', 'email' => 'admin@acme.test', 'password' => Hash::make('secret-pass-1'),
            'tenant_id' => $this->tenant->id, 'is_active' => true, 'email_verified_at' => now(),
        ]);
        $admin->syncRoles(['tenant-admin']);

        $this->device = Device::create([
            'tenant_id' => $this->tenant->id, 'name' => 'IT ROOM', 'model' => 'F18', 'ip_address' => '192.0.2.50',
            'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY, 'adoption_status' => Device::ADOPTED, 'is_active' => true,
        ]);
        $other = Device::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Glass Door', 'model' => 'inBIO460', 'ip_address' => '192.0.2.51',
            'port' => 4370, 'protocol' => Device::PROTOCOL_C3, 'adoption_status' => Device::ADOPTED, 'is_active' => true,
        ]);

        // Aisha: on the terminal under an old number and an old name, finger 5 missing there.
        $this->aisha = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Aisha Rahman', 'employee_code' => '501', 'card_number' => 7511517, 'is_active' => true]);
        // Omar: already exactly right.
        $this->omar = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Omar Ali', 'employee_code' => '502', 'is_active' => true]);

        foreach ([[$this->aisha, '654'], [$this->omar, '502']] as [$e, $pin]) {
            EmployeeDeviceMap::create(['tenant_id' => $this->tenant->id, 'device_id' => $this->device->id, 'employee_id' => $e->id, 'device_user_id' => $pin, 'valid_from' => now()->subYear()]);
        }

        $this->five = "\x4A\x2DSS21".random_bytes(120);
        BiometricTemplate::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->aisha->id, 'device_id' => $other->id, 'finger_slot' => 5,
            'template' => base64_encode($this->five), 'template_sha1' => sha1($this->five), 'template_size' => strlen($this->five),
            'template_format' => 'c3:inBIO460', 'verified_at' => now(),
        ]);

        $this->driver = new FakeBiometricDriver;
        $this->driver->roster = [
            new DeviceUser(uid: 1, userId: '654', name: 'aisha old', privilege: 14, cardNumber: 7511517, fingerSlots: []),
            new DeviceUser(uid: 2, userId: '502', name: 'Omar Ali', fingerSlots: []),
            new DeviceUser(uid: 3, userId: '42', name: 'stranger', cardNumber: 999, fingerSlots: [1]),
        ];
        $this->driver->templates = [['user_id' => '42', 'uid' => 3, 'slot' => 1, 'valid' => 1, 'size' => 4, 'sha1' => sha1('XXXX'), 'template' => 'XXXX']];
        $this->app->instance(BiometricDeviceDriver::class, $this->driver);
        DeviceRosterEntry::create(['tenant_id' => $this->tenant->id, 'device_id' => $this->device->id, 'device_user_id' => '42', 'device_name' => 'stranger', 'claim_status' => DeviceRosterEntry::IGNORED, 'first_seen_at' => now(), 'last_seen_at' => now()]);
    }

    public function test_the_preview_names_every_change_and_writes_nothing(): void
    {
        $plan = app(DeviceRebuildService::class)->plan($this->device);

        $this->assertSame([], $plan['blockers']);
        $aisha = collect($plan['people'])->firstWhere('employee_id', $this->aisha->id);
        $this->assertSame('654', $aisha['from']);
        $this->assertSame('501', $aisha['to']);
        $this->assertContains('user ID 654 → 501', $aisha['changes']);
        $this->assertTrue(collect($aisha['changes'])->contains(fn ($c) => str_starts_with($c, 'name ')));
        $this->assertContains('finger(s) 5 put back', $aisha['changes']);
        $this->assertSame([], collect($plan['people'])->firstWhere('employee_id', $this->omar->id)['changes']);
        $this->assertSame('42', $plan['unlinked'][0]['device_user_id']);
        $this->assertSame(['to_change' => 1, 'unchanged' => 1, 'unlinked' => 1], array_intersect_key($plan['counts'], array_flip(['to_change', 'unchanged', 'unlinked'])));

        $this->assertSame([], $this->driver->writes);
        $this->assertSame([], $this->driver->deletes);
    }

    public function test_the_backup_waits_for_the_punch_download_then_saves_everything_and_fans_out(): void
    {
        Storage::fake('local');
        Queue::fake();
        $service = app(DeviceRebuildService::class);
        $payload = ['remove_unlinked' => false, 'requested_at' => now()->subSecond()->toIso8601String()];

        try {
            $service->backupAndFanOut($this->device, $payload);
            $this->fail('Expected a refusal before the punches were downloaded.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('punches have not been downloaded', $e->getMessage());
        }
        $this->assertSame([], $this->driver->deletes);

        DeviceCommand::create(['tenant_id' => $this->tenant->id, 'device_id' => $this->device->id, 'command' => DeviceCommand::SYNC_ATTENDANCE,
            'status' => DeviceCommand::STATUS_SUCCEEDED, 'finished_at' => now(), 'available_at' => now(), 'max_attempts' => 3]);

        $result = $service->backupAndFanOut($this->device, $payload);

        Storage::disk('local')->assertExists($result['raw']['backup']);
        $this->assertSame(1, $result['raw']['queued'], 'Omar is already right and is not touched');
        $this->assertSame(0, $result['raw']['removals'], 'unlinked records are kept unless removal was ticked');
        $this->assertSame(
            [DeviceCommand::REBUILD_PERSON, DeviceCommand::SYNC_ENROLLMENT],
            DeviceCommand::query()->where('device_id', $this->device->id)->where('id', '>', 1)->whereIn('command', [DeviceCommand::REBUILD_PERSON, DeviceCommand::REBUILD_REMOVE, DeviceCommand::SYNC_ENROLLMENT])->orderBy('id')->pluck('command')->all(),
        );
    }

    public function test_a_person_gets_the_code_name_card_and_fingers_from_the_system(): void
    {
        $result = app(DeviceRebuildService::class)->rebuildPerson($this->device, $this->aisha);

        $this->assertSame('501', $result['raw']['pin']);
        $now = collect($this->driver->roster)->firstWhere('userId', '501');
        $this->assertSame('Aisha Rahman', $now->name);
        $this->assertSame(7511517, $now->cardNumber);
        $this->assertSame([5], $now->fingerSlots);
        $this->assertSame(14, $now->privilege, 'an admin stays an admin');
        $this->assertNull(collect($this->driver->roster)->firstWhere('userId', '654'));
    }

    public function test_an_unlinked_record_is_saved_then_removed_only_when_asked(): void
    {
        Storage::fake('local');

        $result = app(DeviceRebuildService::class)->removeUnlinked($this->device, '42');

        Storage::disk('local')->assertExists($result['raw']['snapshot']);
        $this->assertSame(['42'], $this->driver->deletes);
        $this->assertSame(DeviceRosterEntry::IGNORED, DeviceRosterEntry::query()->where('device_user_id', '42')->value('claim_status'));
    }

    public function test_a_number_an_employee_held_is_never_removed_as_unlinked(): void
    {
        EmployeeDeviceMap::query()->where('device_user_id', '502')->update(['valid_to' => now()]);

        $this->expectException(\DomainException::class);

        try {
            app(DeviceRebuildService::class)->removeUnlinked($this->device, '502');
        } finally {
            $this->assertSame([], $this->driver->deletes);
        }
    }

    public function test_the_api_previews_and_starts_and_refuses_a_read_only_terminal(): void
    {
        Queue::fake();
        $token = $this->postJson('/api/auth/login', ['email' => 'admin@acme.test', 'password' => 'secret-pass-1'])->json('data.token');

        $this->withToken($token)->getJson("/api/devices/{$this->device->id}/rebuild-plan")
            ->assertOk()->assertJsonPath('data.counts.to_change', 1);

        $this->withToken($token)->postJson("/api/devices/{$this->device->id}/rebuild", ['remove_unlinked' => true])
            ->assertOk()->assertJsonCount(2, 'data.command_ids');
        $this->assertSame(
            [DeviceCommand::SYNC_ATTENDANCE, DeviceCommand::REBUILD_DEVICE],
            DeviceCommand::query()->where('device_id', $this->device->id)->orderBy('id')->pluck('command')->all(),
        );

        $this->device->forceFill(['adoption_status' => Device::UNADOPTED])->save();
        DeviceRosterEntry::create(['tenant_id' => $this->tenant->id, 'device_id' => $this->device->id, 'device_user_id' => '77', 'device_name' => 'unknown', 'claim_status' => DeviceRosterEntry::UNCLAIMED, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $this->withToken($token)->postJson("/api/devices/{$this->device->id}/rebuild")->assertStatus(409);
    }
}
