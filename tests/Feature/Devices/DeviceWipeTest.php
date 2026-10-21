<?php

namespace Tests\Feature\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\AccessCard;
use App\Models\AttendanceLog;
use App\Models\BiometricTemplate;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Devices\DeviceWipeService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Exception\ProtocolException;
use Promoseven\Biometric\Exception\UnsupportedCommandException;
use Promoseven\Biometric\Exception\VerificationException;
use Promoseven\Biometric\Value\DeviceUser;
use Tests\TestCase;

/**
 * "Wipe data from terminal" (owner, 2026-10-21; CLAUDE.md rule 22). The terminal ends
 * with no users and no punches. Linked employees' cards and fingers are saved and
 * checked first; unlinked users go with nothing kept; punches are saved, or erased
 * unsaved when the operator says so. Every irreversible step comes after every refusal.
 */
class DeviceWipeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Device $device;

    private FakeBiometricDriver $driver;

    private Employee $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();

        $this->tenant = Tenant::query()->firstOrFail();
        Tenancy::set($this->tenant->id);

        $this->device = Device::create([
            'tenant_id' => $this->tenant->id, 'name' => 'IT-Office', 'model' => 'F18',
            'ip_address' => '192.0.2.64', 'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY,
            'timezone' => '+03:00', 'is_active' => true,
        ]);

        $this->driver = new FakeBiometricDriver;
        $this->app->instance(BiometricDeviceDriver::class, $this->driver);

        // Ali is linked (user 205, finger 6, card 777); user 48 is a stranger.
        $this->ali = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Ali', 'employee_code' => '205', 'is_active' => true]);
        EmployeeDeviceMap::create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->ali->id, 'device_id' => $this->device->id, 'device_user_id' => '205', 'valid_from' => now()->subMonth()]);
        $this->driver->roster = [
            new DeviceUser(uid: 7, userId: '205', name: 'Ali', privilege: 14, cardNumber: 777, fingerSlots: [6]),
            new DeviceUser(uid: 8, userId: '48', name: 'stranger', privilege: 0, cardNumber: 555, fingerSlots: [1]),
        ];
        $this->driver->templates = [
            ['user_id' => '205', 'uid' => 7, 'slot' => 6, 'valid' => 1, 'size' => 4, 'sha1' => sha1('ALI6'), 'template' => 'ALI6'],
            ['user_id' => '48', 'uid' => 8, 'slot' => 1, 'valid' => 1, 'size' => 4, 'sha1' => sha1('STR1'), 'template' => 'STR1'],
        ];
        $this->driver->punchLog = [$this->punch('2026-10-20 08:00:00', '205'), $this->punch('2026-10-20 08:05:00', '48')];
    }

    private function punch(string $localTime, string $pin): array
    {
        return ['user_id' => $pin, 'timestamp' => Carbon::parse($localTime, '+03:00'), 'verify_method' => 1, 'status' => 0, 'raw' => "raw:{$pin}:{$localTime}"];
    }

    private function wipe(bool $savePunches): array
    {
        return app(DeviceWipeService::class)->wipe($this->device, $savePunches);
    }

    public function test_it_saves_linked_credentials_and_punches_then_empties_the_terminal(): void
    {
        $result = $this->wipe(true);

        $this->assertSame([], $this->driver->roster);
        $this->assertSame([], $this->driver->punchLog);
        $this->assertSame(1, $this->driver->wipes);
        // Ali's finger and card are in the system; the stranger's are not.
        $this->assertTrue(BiometricTemplate::where('employee_id', $this->ali->id)->where('finger_slot', 6)->where('template_sha1', sha1('ALI6'))->exists());
        $this->assertTrue(AccessCard::where('employee_id', $this->ali->id)->where('card_number', 777)->exists());
        $this->assertSame('777', (string) $this->ali->fresh()->card_number);
        $this->assertSame(0, BiometricTemplate::where('template_sha1', sha1('STR1'))->count());
        // Both punches were saved before the clear.
        $this->assertSame(2, AttendanceLog::where('device_id', $this->device->id)->count());
        // Our records follow the terminal: Ali's mapping closed, his privilege kept on it.
        $map = EmployeeDeviceMap::where('employee_id', $this->ali->id)->sole();
        $this->assertNotNull($map->valid_to);
        $this->assertSame(14, (int) $map->device_privilege);
        $this->assertStringContainsString('2 user(s) removed (1 linked', $result['message']);
        $this->assertStringContainsString('saved to the system and cleared', $result['message']);
        $this->assertNull($this->device->fresh()->attendance_watermark);
    }

    /** Owner's choice: "don't save" erases the punches without reading them in. */
    public function test_not_saving_erases_the_punches_without_storing_them(): void
    {
        $result = $this->wipe(false);

        $this->assertSame([], $this->driver->punchLog);
        $this->assertSame(0, AttendanceLog::where('device_id', $this->device->id)->count());
        $this->assertSame([], $this->driver->roster);
        $this->assertStringContainsString('erased without saving', $result['message']);
        // Linked credentials are still saved: that part of the wipe is not optional.
        $this->assertTrue(AccessCard::where('employee_id', $this->ali->id)->where('card_number', 777)->exists());
    }

    /**
     * A linked employee whose fingers the terminal will not report cannot be shown
     * saved (rule 10). Nothing goes — not the users, and not the punches either, even
     * in erase mode: the punch step only runs after the copy is proven.
     */
    public function test_unreadable_fingers_of_a_linked_employee_refuse_before_anything_is_erased(): void
    {
        $this->driver->roster[0] = new DeviceUser(uid: 7, userId: '205', name: 'Ali', cardNumber: 777, fingerSlots: null);

        try {
            $this->wipe(false);
            $this->fail('Expected a refusal.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Nothing was wiped from IT-Office', $e->getMessage());
            $this->assertStringContainsString('205 (fingers not readable)', $e->getMessage());
        }

        $this->assertCount(2, $this->driver->roster);
        $this->assertCount(2, $this->driver->punchLog);
        $this->assertSame(0, $this->driver->wipes);
        $this->assertSame(0, $this->driver->clears);
    }

    /** Save mode: a punch that cannot be read cleanly stops the whole wipe. */
    public function test_a_punch_that_cannot_be_saved_stops_the_wipe(): void
    {
        $this->driver->punchLog[] = ['raw' => 'deadbeef'];

        $this->expectException(ProtocolException::class);

        try {
            $this->wipe(true);
        } finally {
            $this->assertCount(2, $this->driver->roster);
            $this->assertSame(0, $this->driver->wipes);
        }
    }

    public function test_users_still_there_after_the_delete_are_reported(): void
    {
        $driver = new class extends FakeBiometricDriver
        {
            public function deleteAllUsers(Device $device): int
            {
                $this->wipes++;

                return count($this->roster); // ACKed, dropped
            }
        };
        $driver->roster = $this->driver->roster;
        $driver->templates = $this->driver->templates;
        $this->app->instance(BiometricDeviceDriver::class, $driver);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('still holds 2 user(s)');

        $this->wipe(false);
    }

    /** F18-first: a model without device.wipe is refused before anything is read. */
    public function test_a_model_without_the_wipe_is_refused(): void
    {
        $this->driver->capabilityOverrides = [Capability::DEVICE_WIPE => Capability::NOT_IMPLEMENTED];

        $this->expectException(UnsupportedCommandException::class);

        try {
            $this->wipe(true);
        } finally {
            $this->assertCount(2, $this->driver->roster);
            $this->assertCount(2, $this->driver->punchLog);
        }
    }

    public function test_the_endpoint_needs_the_choice_and_the_exact_name_and_queues_one_attempt(): void
    {
        $admin = User::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($admin, 'sanctum')->postJson("/api/devices/{$this->device->id}/wipe", ['confirm_name' => 'IT-Office'])
            ->assertUnprocessable()->assertJsonValidationErrors('save_punches');
        $this->actingAs($admin, 'sanctum')->postJson("/api/devices/{$this->device->id}/wipe", ['save_punches' => true, 'confirm_name' => 'it-office'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm_name');
        $this->assertSame(0, DeviceCommand::where('command', DeviceCommand::WIPE_DEVICE)->count());

        $this->actingAs($admin, 'sanctum')->postJson("/api/devices/{$this->device->id}/wipe", ['save_punches' => false, 'confirm_name' => 'IT-Office'])
            ->assertOk();

        $command = DeviceCommand::where('command', DeviceCommand::WIPE_DEVICE)->sole();
        $this->assertSame(1, (int) $command->max_attempts);
        $this->assertFalse($command->payload['save_punches']);
    }
}
