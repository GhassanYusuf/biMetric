<?php

namespace Tests\Feature\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\BiometricTemplate;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\Tenant;
use App\Services\Devices\DeviceCommandHandler;
use App\Services\Devices\DeviceUserSyncService;
use App\Services\Employees\FingerprintVault;
use App\Services\Integration\EmployeeOffboarding;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Promoseven\Biometric\Exception\TimeoutException;
use Promoseven\Biometric\Value\DeviceUser;
use Tests\TestCase;

/**
 * The faster bulk delete (owner, 2026-11-16): one job per terminal that reads it ONCE, saves every
 * finger, then removes each person by removeEmployee() — every rule of a single removal per person.
 * Before, each person re-read Gems WorkShop's 719 users and 801 fingerprints: ~10 s each.
 */
class BulkRemovalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Device $f18;

    private FakeBiometricDriver $driver;

    /** @var list<Employee> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();
        $this->tenant = Tenant::where('slug', 'acme')->firstOrFail();
        Tenancy::set($this->tenant->id);

        $this->f18 = Device::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Gems WorkShop', 'model' => 'F18',
            'ip_address' => '192.0.2.64', 'port' => 4371, 'protocol' => Device::PROTOCOL_BINARY,
            'adoption_status' => Device::ADOPTED, 'is_active' => true,
        ]);
        $this->driver = new FakeBiometricDriver;
        $this->app->instance(BiometricDeviceDriver::class, $this->driver);

        $templates = [];
        foreach (['2296', '2352', '2089', '2401'] as $i => $pin) {
            $e = Employee::create(['tenant_id' => $this->tenant->id, 'name' => "Worker {$pin}", 'employee_code' => $pin, 'is_active' => true]);
            EmployeeDeviceMap::create(['tenant_id' => $this->tenant->id, 'device_id' => $this->f18->id, 'employee_id' => $e->id, 'device_user_id' => $pin, 'valid_from' => now()->subYear()]);
            $this->people[] = $e;
            $this->driver->roster[] = new DeviceUser(uid: 700 + $i, userId: $pin, name: "Worker {$pin}", fingerSlots: [6]);
            $blob = "\x4A\x2DSS21".random_bytes(100);
            $templates[] = ['user_id' => $pin, 'uid' => 700 + $i, 'slot' => 6, 'valid' => 1, 'size' => strlen($blob), 'sha1' => sha1($blob), 'template' => $blob];
        }
        $this->driver->templates = $templates;
    }

    public function test_the_terminal_is_read_once_and_everyone_is_removed_with_their_finger_saved(): void
    {
        $result = app(DeviceUserSyncService::class)->removeEmployees($this->f18, $this->people);

        $this->assertSame(['2296', '2352', '2089', '2401'], $this->driver->deletes);
        $this->assertSame(1, $this->driver->templateReads, 'the template table is read once for the batch');
        $this->assertSame(1, $this->driver->userReads, 'the user table is read once for the batch');
        foreach ($this->people as $e) {
            $this->assertSame([6], app(FingerprintVault::class)->storedSlots($e->id));
            $this->assertNull(EmployeeDeviceMap::where('employee_id', $e->id)->whereNull('valid_to')->first());
        }
        $this->assertStringContainsString('Removed 4 of 4', $result['message']);
    }

    public function test_someone_whose_finger_cannot_be_saved_is_refused_and_the_rest_go_ahead(): void
    {
        // 2352's template did not come off the terminal: the guard must refuse him alone.
        $this->driver->templates = array_values(array_filter($this->driver->templates, fn ($t) => $t['user_id'] !== '2352'));

        $result = app(DeviceUserSyncService::class)->removeEmployees($this->f18, $this->people);

        $this->assertSame(['2296', '2089', '2401'], $this->driver->deletes);
        $this->assertSame([$this->people[1]->id], array_column($result['raw']['refused'], 'employee_id'));
        $this->assertStringContainsString('fingerprint(s) 6 on user ID 2352 are not saved', $result['raw']['refused'][0]['reason']);
        $this->assertNotNull(EmployeeDeviceMap::where('employee_id', $this->people[1]->id)->whereNull('valid_to')->first());
    }

    public function test_a_terminal_that_stops_answering_stops_the_batch_for_a_retry(): void
    {
        $this->driver->silentAfterDeletes = 1;

        try {
            app(DeviceUserSyncService::class)->removeEmployees($this->f18, $this->people);
            $this->fail('Expected the batch to stop.');
        } catch (TimeoutException) {
        }

        // The first one is off; nobody else was deleted; the rest are still linked for the retry.
        $this->assertSame(['2296'], $this->driver->deletes);
        $this->assertSame(3, EmployeeDeviceMap::where('device_id', $this->f18->id)->whereNull('valid_to')->count());
    }

    public function test_the_job_deletes_each_record_once_its_person_is_off_the_terminal(): void
    {
        $offboarding = app(EmployeeOffboarding::class);
        $deleteAfter = [];
        foreach ($this->people as $e) {
            $deleteAfter[$e->id] = (string) \Illuminate\Support\Str::uuid();
        }
        // 2089 keeps an unsaved finger: refused, so his record must stay.
        $this->driver->templates = array_values(array_filter($this->driver->templates, fn ($t) => $t['user_id'] !== '2089'));

        $queued = $offboarding->takeManyOffTerminals($this->people, $deleteAfter);
        $this->assertCount(1, $queued['commands'], 'one job for the terminal, not one per person');
        $command = $queued['commands'][0];
        $this->assertSame(DeviceCommand::REMOVE_USERS, $command->command);

        $result = app(DeviceCommandHandler::class)->handle($command->fresh());

        $this->assertNull(Employee::withoutGlobalScopes()->find($this->people[0]->id));
        $this->assertNull(Employee::withoutGlobalScopes()->find($this->people[1]->id));
        $this->assertNotNull(Employee::withoutGlobalScopes()->find($this->people[2]->id), 'refused: the record stays');
        $this->assertNull(Employee::withoutGlobalScopes()->find($this->people[3]->id));
        $this->assertStringContainsString('Removed 3 of 4', $result['message']);
    }

    public function test_a_retry_only_takes_people_still_on_the_terminal(): void
    {
        $queued = app(EmployeeOffboarding::class)->takeManyOffTerminals($this->people);
        // The first run removed one before the terminal went silent.
        app(DeviceUserSyncService::class)->removeEmployee($this->f18, $this->people[0]);
        $this->driver->deletes = [];

        app(DeviceCommandHandler::class)->handle($queued['commands'][0]->fresh());

        $this->assertSame(['2352', '2089', '2401'], $this->driver->deletes);
    }

    public function test_a_big_batch_is_split_into_jobs_on_the_same_terminal(): void
    {
        $many = [];
        for ($i = 0; $i < EmployeeOffboarding::BATCH_SIZE + 5; $i++) {
            $e = Employee::create(['tenant_id' => $this->tenant->id, 'name' => "P{$i}", 'employee_code' => (string) (9000 + $i), 'is_active' => true]);
            EmployeeDeviceMap::create(['tenant_id' => $this->tenant->id, 'device_id' => $this->f18->id, 'employee_id' => $e->id, 'device_user_id' => (string) (9000 + $i), 'valid_from' => now()]);
            $many[] = $e;
        }

        $queued = app(EmployeeOffboarding::class)->takeManyOffTerminals($many);

        $this->assertCount(2, $queued['commands']);
        $this->assertCount(EmployeeOffboarding::BATCH_SIZE + 5, $queued['queued_for']);
    }
}
