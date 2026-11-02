<?php

namespace Tests\Feature\Api;

use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The Postman collection's example bodies must pass the API as they are (owner, 2026-11-02).
 * The previous collection's placeholders ("gender": "Male", "external_id": 1, "" for an
 * array) failed validation and cost a morning of testing. This sends the committed file's
 * own bodies, with its own variable defaults, to the routes they name.
 *
 * Regenerate with `php tools/postman/integration-collection.php`; it refuses to write a
 * collection that misses a live /api/v1 route.
 */
class PostmanCollectionTest extends TestCase
{
    use RefreshDatabase;

    private const FILE = __DIR__.'/../../../docs/postman/ZKT-Integration-API-v1.postman_collection.json';

    private array $collection;

    private array $vars;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();

        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($tenant);

        Tenancy::set($tenant->id);
        $this->admin = User::withoutGlobalScopes()->create([
            'name' => 'Admin', 'email' => 'admin@acme.test', 'password' => 'secret-pass-1',
            'tenant_id' => $tenant->id, 'is_active' => true, 'email_verified_at' => now(),
        ]);
        $this->admin->syncRoles(['tenant-admin']);

        // The ids the collection's defaults name, so its bodies meet the same rows as production.
        $company = Department::create(['name' => 'PROMOSEVEN']);
        $company->forceFill(['id' => 4])->save();
        $test = Employee::create(['name' => 'Test', 'employee_code' => '90110121', 'external_id' => '1', 'is_active' => true]);
        $device = Device::create(['name' => 'IT ROOM', 'ip_address' => '192.0.2.62', 'port' => 4370, 'protocol' => 'zkteco']);
        Tenancy::clear();

        $this->collection = json_decode(file_get_contents(self::FILE), true, flags: JSON_THROW_ON_ERROR);
        $this->vars = array_column($this->collection['variable'], 'value', 'key');
        $this->vars['employee_id'] = (string) $test->id;
        $this->vars['device_id'] = (string) $device->id;
    }

    private function request(string $name): array
    {
        $found = null;
        $walk = function (array $items) use (&$walk, &$found, $name) {
            foreach ($items as $item) {
                isset($item['item']) ? $walk($item['item']) : ($item['name'] === $name ? $found = $item['request'] : null);
            }
        };
        $walk($this->collection['item']);
        $this->assertNotNull($found, "No request named \"{$name}\" in the collection.");

        return $found;
    }

    private function fill(string $text): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', fn ($m) => $this->vars[$m[1]] ?? $m[0], $text);
    }

    private function send(string $name)
    {
        $r = $this->request($name);
        $path = '/'.implode('/', array_map(fn ($p) => $this->fill($p), $r['url']['path']));
        $body = isset($r['body']['raw']) ? json_decode($this->fill($r['body']['raw']), true, flags: JSON_THROW_ON_ERROR) : [];

        $token = $this->admin->createToken('postman', ['*'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($r['method'], $path, $body);
    }

    public function test_every_request_reaches_the_api_root_and_asks_for_json(): void
    {
        $walk = function (array $items) use (&$walk) {
            foreach ($items as $item) {
                if (isset($item['item'])) {
                    $walk($item['item']);

                    continue;
                }
                $this->assertSame('{{base_url}}', $item['request']['url']['host'][0], $item['name']);
                $this->assertContains(['key' => 'Accept', 'value' => 'application/json'], $item['request']['header'], $item['name']);
            }
        };
        $walk($this->collection['item']);
    }

    public function test_the_employee_examples_pass_validation_as_they_are(): void
    {
        Queue::fake();

        $this->send('Create employee')->assertCreated();
        $this->send('Create or update by your id (upsert)')->assertOk()->assertJsonPath('meta.created', false);
        $this->send('Update employee')->assertOk();
    }

    public function test_the_department_and_token_examples_pass_validation_as_they_are(): void
    {
        $this->send('Create department')->assertCreated();
        $this->send('Create token')->assertCreated();
    }

    public function test_the_terminal_examples_pass_validation_as_they_are(): void
    {
        Queue::fake();

        // The bodies only: this terminal was never probed, so the command may still be refused
        // for its capability ("does not support …") AFTER the body was accepted. Never because
        // a field or a value in the example is wrong.
        $command = $this->send('Send a command to a terminal');
        $this->assertStringNotContainsString('invalid', (string) $command->json('message'), (string) $command->getContent());
        $this->assertEmpty(array_intersect(['full', 'from', 'to'], array_keys($command->json('errors') ?? [])));
        $this->assertArrayNotHasKey('finger_slot', $this->send('Start fingerprint enrollment')->json('errors') ?? []);
    }
}
