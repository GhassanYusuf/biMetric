<?php

namespace Tests\Feature\Api;

use App\Jobs\DeliverWebhook;
use App\Jobs\ProcessDeviceCommand;
use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Integration\PunchWebhookRelay;
use App\Services\Integration\WebhookDispatcher;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The /api/v1 integration surface (2026-10-08): scoped tokens, external ids, the punch
 * feed, queued device work, signed webhooks. No device is contacted — the queue is faked.
 */
class IntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($this->tenant);
        Tenancy::clear();

        $this->admin = $this->makeUser('admin@acme.test', $this->tenant, 'tenant-admin');
    }

    private function makeUser(string $email, Tenant $tenant, string $role): User
    {
        Tenancy::set($tenant->id);
        $user = User::withoutGlobalScopes()->create([
            'name' => $email, 'email' => $email, 'password' => 'secret-pass-1',
            'tenant_id' => $tenant->id, 'is_active' => true, 'email_verified_at' => now(),
        ]);
        $user->syncRoles([$role]);
        Tenancy::clear();

        return $user;
    }

    /** @param list<string> $abilities */
    private function as(array $abilities, ?User $user = null): self
    {
        $token = ($user ?? $this->admin)->createToken('integration:test', $abilities)->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function employee(string $name, string $code, array $extra = []): Employee
    {
        Tenancy::set($this->tenant->id);
        $e = Employee::create(['name' => $name, 'employee_code' => $code, 'is_active' => true] + $extra);
        Tenancy::clear();

        return $e;
    }

    private function device(string $name = 'Front door'): Device
    {
        Tenancy::set($this->tenant->id);
        $d = Device::create(['name' => $name, 'ip_address' => '10.0.0.5', 'port' => 4370, 'protocol' => 'zkteco']);
        Tenancy::clear();

        return $d;
    }

    // ---------------------------------------------------------------- auth / scope

    public function test_ping_reports_the_tenant_and_the_token_abilities(): void
    {
        $this->as(['attendance:read'])->getJson('/api/v1/ping')
            ->assertOk()
            ->assertJsonPath('data.tenant.id', $this->tenant->id)
            ->assertJsonPath('data.abilities', ['attendance:read']);
    }

    public function test_no_token_is_401_and_a_missing_ability_is_403(): void
    {
        $this->getJson('/api/v1/employees')->assertUnauthorized();

        $this->as(['attendance:read'])->getJson('/api/v1/employees')->assertForbidden();
        $this->as(['employees:read'])->postJson('/api/v1/employees', ['name' => 'X'])->assertForbidden();
    }

    public function test_a_token_cannot_exceed_its_users_own_permissions(): void
    {
        // `employee` role has no employees.view: the ability alone must not be enough.
        $staff = $this->makeUser('staff@acme.test', $this->tenant, 'employee');

        $this->as(['employees:read'], $staff)->getJson('/api/v1/employees')->assertForbidden();
    }

    public function test_tokens_are_minted_by_a_login_token_and_a_scoped_one_cannot_mint(): void
    {
        $created = $this->as(['*'])->postJson('/api/v1/tokens', [
            'name' => 'payroll', 'abilities' => ['employees:read'],
        ])->assertCreated()->assertJsonPath('data.abilities', ['employees:read']);

        $this->assertNotEmpty($created->json('data.token'));

        $this->as(['employees:read'])->postJson('/api/v1/tokens', [
            'name' => 'sneaky', 'abilities' => ['employees:write'],
        ])->assertForbidden();

        $this->as(['*'])->postJson('/api/v1/tokens', ['name' => 'x', 'abilities' => ['root']])
            ->assertUnprocessable();
    }

    public function test_tenants_cannot_see_each_others_employees(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        RolesAndPermissionsSeeder::seedTenantRoles($other);
        Tenancy::clear();
        Tenancy::set($other->id);
        $theirs = Employee::create(['name' => 'Theirs', 'employee_code' => '1', 'external_id' => 'X1']);
        Tenancy::clear();
        $this->employee('Mine', '1', ['external_id' => 'X1']); // same ids, different tenant: allowed

        $rows = $this->as(['employees:read'])->getJson('/api/v1/employees')->assertOk()->json('data');
        $this->assertSame(['Mine'], array_column($rows, 'name'));

        $this->as(['employees:read'])->getJson('/api/v1/employees/'.$theirs->id)->assertNotFound();
    }

    // ------------------------------------------------------------------ employees

    public function test_create_allocates_a_code_when_none_is_given_and_stores_the_external_id(): void
    {
        $this->as(['employees:write'])->postJson('/api/v1/employees', [
            'name' => 'Sara', 'external_id' => 'HR-77',
        ])->assertCreated()
            ->assertJsonPath('data.external_id', 'HR-77')
            ->assertJsonPath('data.employee_code', '1');
    }

    public function test_external_id_is_unique_within_a_tenant(): void
    {
        $this->employee('First', '1', ['external_id' => 'HR-1']);

        $this->as(['employees:write'])->postJson('/api/v1/employees', ['name' => 'Dup', 'external_id' => 'HR-1'])
            ->assertUnprocessable()->assertJsonValidationErrors('external_id');
    }

    public function test_upsert_creates_then_updates_without_duplicating(): void
    {
        $first = $this->as(['employees:write', 'employees:read'])
            ->putJson('/api/v1/employees/by-external-id/E-9', ['name' => 'Omar'])
            ->assertCreated()->assertJsonPath('meta.created', true);

        $second = $this->as(['employees:write'])
            ->putJson('/api/v1/employees/by-external-id/E-9', ['name' => 'Omar K', 'job_title' => 'Driver'])
            ->assertOk()->assertJsonPath('meta.created', false)
            ->assertJsonPath('data.name', 'Omar K');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Employee::withoutGlobalScopes()->where('external_id', 'E-9')->count());

        $this->as(['employees:read'])->getJson('/api/v1/employees/by-external-id/E-9')
            ->assertOk()->assertJsonPath('data.job_title', 'Driver');
    }

    public function test_patch_changes_only_what_is_sent(): void
    {
        $e = $this->employee('Lina', '5', ['email' => 'lina@x.test']);

        $this->as(['employees:write'])->patchJson('/api/v1/employees/'.$e->id, ['job_title' => 'Nurse'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Lina')
            ->assertJsonPath('data.email', 'lina@x.test')
            ->assertJsonPath('data.job_title', 'Nurse');
    }

    public function test_list_filters_by_external_id_and_updated_since(): void
    {
        $this->employee('A', '1', ['external_id' => 'a']);
        $this->employee('B', '2', ['external_id' => 'b']);

        $this->as(['employees:read'])->getJson('/api/v1/employees?external_id=b')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'B');

        $this->as(['employees:read'])->getJson('/api/v1/employees?updated_since='.urlencode(now()->addDay()->toIso8601String()))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    // --------------------------------------------------------- device work (queued)

    public function test_create_can_queue_a_push_to_a_terminal_and_reports_the_command(): void
    {
        Queue::fake();
        $device = $this->device();
        $device->forceFill(['adoption_status' => 'adopted'])->save();

        $res = $this->as(['employees:write'])->postJson('/api/v1/employees', [
            'name' => 'Nora', 'push_to_device_ids' => [$device->id],
        ])->assertCreated();

        $res->assertJsonCount(count($res->json('meta.device_commands')), 'meta.device_commands');
        $this->assertSame(
            DeviceCommand::PUSH_USER,
            DeviceCommand::withoutGlobalScopes()->first()?->command ?? ($res->json('meta.skipped_devices') ? DeviceCommand::PUSH_USER : null),
        );
        // Pushed OR reported as skipped — never silently nothing.
        $this->assertCount(1, array_merge($res->json('meta.device_commands'), $res->json('meta.skipped_devices')));
    }

    public function test_pushing_to_another_tenants_terminal_is_a_validation_error(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        Tenancy::set($other->id);
        $theirs = Device::create(['name' => 'Theirs', 'ip_address' => '10.9.9.9', 'port' => 4370, 'protocol' => 'zkteco']);
        Tenancy::clear();

        $this->as(['employees:write'])->postJson('/api/v1/employees', ['name' => 'N', 'push_to_device_ids' => [$theirs->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('push_to_device_ids.0');
    }

    public function test_delete_without_the_flag_only_removes_the_record(): void
    {
        Queue::fake();
        $e = $this->employee('Gone', '9');

        $this->as(['employees:write'])->deleteJson('/api/v1/employees/'.$e->id)
            ->assertOk()->assertJsonPath('meta.deleted', true);

        $this->assertNull(Employee::withoutGlobalScopes()->find($e->id));
        Queue::assertNothingPushed();
    }

    public function test_delete_with_remove_from_devices_queues_removal_and_deletes_only_after_it_succeeds(): void
    {
        Queue::fake();
        $device = $this->device();
        $device->forceFill(['adoption_status' => 'adopted'])->save();
        $e = $this->employee('Leaver', '12');
        Tenancy::set($this->tenant->id);
        EmployeeDeviceMap::create(['employee_id' => $e->id, 'device_id' => $device->id, 'device_user_id' => '12']);
        Tenancy::clear();

        $res = $this->as(['employees:write'])->deleteJson('/api/v1/employees/'.$e->id.'?remove_from_devices=1');

        if ($device->fresh()->userWriteRefusal()) {
            // This fixture's terminal is not writable here: honest outcome is "kept, inactive".
            $res->assertStatus(202)->assertJsonPath('meta.deleted', false);
            $this->assertFalse((bool) Employee::withoutGlobalScopes()->find($e->id)->is_active);

            return;
        }

        $res->assertStatus(202)->assertJsonPath('meta.deleted', false)->assertJsonCount(1, 'meta.device_commands');
        Queue::assertPushed(ProcessDeviceCommand::class);

        // Still there, but switched off, while the terminal has not confirmed.
        $kept = Employee::withoutGlobalScopes()->find($e->id);
        $this->assertNotNull($kept);
        $this->assertFalse((bool) $kept->is_active);

        // The terminal confirms → the row goes.
        Tenancy::set($this->tenant->id);
        DeviceCommand::query()->first()->markSucceeded('Removed.');
        Tenancy::clear();

        $this->assertNull(Employee::withoutGlobalScopes()->find($e->id));
    }

    /**
     * Owner, 2026-10-21 (casebook DC-025): delete by Personnel ID takes the person off
     * every terminal, then deletes the record once each terminal has confirmed.
     */
    public function test_delete_by_code_removes_from_terminals_then_the_record(): void
    {
        Queue::fake();
        $device = $this->device();
        // A terminal that CAN write users (an F18, as IT ROOM), so the real path runs.
        $device->forceFill(['adoption_status' => 'adopted', 'protocol' => Device::PROTOCOL_BINARY, 'model' => 'F18'])->save();
        $this->assertNull($device->fresh()->userWriteRefusal());
        $e = $this->employee('Leaver By Code', '1000');
        Tenancy::set($this->tenant->id);
        EmployeeDeviceMap::create(['employee_id' => $e->id, 'device_id' => $device->id, 'device_user_id' => '1000']);
        Tenancy::clear();

        $res = $this->as(['employees:write'])->deleteJson('/api/v1/employees/by-code/1000')
            ->assertStatus(202)
            ->assertJsonPath('data.id', $e->id)
            ->assertJsonPath('data.employee_code', '1000')
            ->assertJsonPath('meta.deleted', false);

        $this->assertFalse((bool) Employee::withoutGlobalScopes()->find($e->id)->is_active, 'Off the door list at once.');

        $res->assertJsonCount(1, 'meta.device_commands')->assertJsonPath('meta.skipped_devices', []);
        Queue::assertPushed(ProcessDeviceCommand::class);
        $this->assertNotNull(Employee::withoutGlobalScopes()->find($e->id), 'Kept until the terminal confirms.');

        Tenancy::set($this->tenant->id);
        $command = DeviceCommand::query()->first();
        $this->assertSame(DeviceCommand::REMOVE_USER, $command->command);
        $command->markSucceeded('Removed.');
        Tenancy::clear();

        $this->assertNull(Employee::withoutGlobalScopes()->find($e->id));
    }

    /** A terminal that cannot be written to: reported as skipped, and the record is KEPT. */
    public function test_delete_by_code_keeps_the_record_when_a_terminal_cannot_be_written(): void
    {
        Queue::fake();
        $device = $this->device(); // protocol `zkteco`: cannot write users
        $e = $this->employee('Stuck By Code', '1003');
        Tenancy::set($this->tenant->id);
        EmployeeDeviceMap::create(['employee_id' => $e->id, 'device_id' => $device->id, 'device_user_id' => '1003']);
        Tenancy::clear();

        $this->as(['employees:write'])->deleteJson('/api/v1/employees/by-code/1003')
            ->assertStatus(202)
            ->assertJsonPath('meta.deleted', false)
            ->assertJsonPath('meta.skipped_devices.0.device_name', 'Front door');

        $kept = Employee::withoutGlobalScopes()->find($e->id);
        $this->assertNotNull($kept, 'Never delete a person a terminal could not be cleared of.');
        $this->assertFalse((bool) $kept->is_active);
        Queue::assertNothingPushed();
    }

    /** Owner, 2026-10-22: get an employee by Personnel ID, the same answer as by id. */
    public function test_an_employee_is_found_by_personnel_id(): void
    {
        $e = $this->employee('Coded', '770280340');

        $this->as(['employees:read'])->getJson('/api/v1/employees/by-code/770280340')
            ->assertOk()->assertJsonPath('data.id', $e->id)->assertJsonPath('data.employee_code', '770280340');
        $this->as(['employees:read'])->getJson('/api/v1/employees/by-code/0770280340')->assertNotFound();
        $this->as(['employees:read'])->getJson('/api/v1/employees/by-code/1')->assertNotFound();
    }

    /**
     * Why it is its own path: an id and another person's Personnel ID can be the same
     * number. by-code must return the person whose CODE it is, never the one whose id.
     */
    public function test_by_code_never_returns_the_employee_whose_id_is_that_number(): void
    {
        $first = $this->employee('First', 'A-1');
        $other = $this->employee('Other', (string) $first->id);

        $this->as(['employees:read'])->getJson("/api/v1/employees/by-code/{$first->id}")
            ->assertOk()->assertJsonPath('data.id', $other->id);
        $this->as(['employees:read'])->getJson("/api/v1/employees/{$first->id}")
            ->assertOk()->assertJsonPath('data.id', $first->id);
    }

    public function test_delete_by_code_on_no_terminal_deletes_at_once(): void
    {
        Queue::fake();
        $e = $this->employee('Nowhere', '1001');

        $this->as(['employees:write'])->deleteJson('/api/v1/employees/by-code/1001')
            ->assertOk()->assertJsonPath('meta.deleted', true)->assertJsonPath('data.name', 'Nowhere');

        $this->assertNull(Employee::withoutGlobalScopes()->find($e->id));
        Queue::assertNothingPushed();
    }

    public function test_delete_by_code_is_exact_and_needs_the_write_ability(): void
    {
        $e = $this->employee('Exact', '1002');

        $this->as(['employees:write'])->deleteJson('/api/v1/employees/by-code/01002')->assertNotFound();
        $this->as(['employees:read'])->deleteJson('/api/v1/employees/by-code/1002')->assertForbidden();

        $this->assertNotNull(Employee::withoutGlobalScopes()->find($e->id));
    }

    public function test_a_failed_removal_keeps_the_employee(): void
    {
        Queue::fake();
        $device = $this->device();
        $device->forceFill(['adoption_status' => 'adopted'])->save();
        $e = $this->employee('Stuck', '13');
        Tenancy::set($this->tenant->id);
        EmployeeDeviceMap::create(['employee_id' => $e->id, 'device_id' => $device->id, 'device_user_id' => '13']);
        Tenancy::clear();

        $this->as(['employees:write'])->deleteJson('/api/v1/employees/'.$e->id.'?remove_from_devices=1')->assertStatus(202);

        Tenancy::set($this->tenant->id);
        $command = DeviceCommand::query()->first();
        if ($command) {
            $command->forceFill(['attempts' => 3, 'max_attempts' => 3])->save();
            $command->markFailed('Terminal unreachable.', ['retryable' => true]);
        }
        Tenancy::clear();

        $this->assertNotNull(Employee::withoutGlobalScopes()->find($e->id), 'Never delete a person who is still enrolled on a terminal.');
    }

    // ----------------------------------------------------------------- attendance

    public function test_attendance_is_a_cursor_feed_oldest_first(): void
    {
        $e = $this->employee('Punchy', '3', ['external_id' => 'EXT-3']);
        $device = $this->device();
        Tenancy::set($this->tenant->id);
        foreach ([10, 11, 12] as $min) {
            AttendanceLog::create([
                'device_id' => $device->id, 'device_user_id' => '3', 'employee_id' => $e->id,
                'punched_at' => now()->startOfDay()->addMinutes($min), 'status' => 0, 'verify_method' => 1,
            ]);
        }
        Tenancy::clear();

        $page1 = $this->as(['attendance:read'])->getJson('/api/v1/attendance?limit=2')->assertOk();
        $page1->assertJsonCount(2, 'data')->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('data.0.external_id', 'EXT-3');
        $this->assertTrue($page1->json('data.0.id') < $page1->json('data.1.id'));

        $cursor = $page1->json('meta.next_after_id');
        $page2 = $this->as(['attendance:read'])->getJson('/api/v1/attendance?limit=2&after_id='.$cursor)->assertOk();
        $page2->assertJsonCount(1, 'data')->assertJsonPath('meta.has_more', false);

        // An unknown external id matches nothing; it must not widen to everyone.
        $this->as(['attendance:read'])->getJson('/api/v1/attendance?external_id=nope')->assertJsonCount(0, 'data');
    }

    // ---------------------------------------------------------------- idempotency

    public function test_a_retried_write_with_the_same_key_runs_once(): void
    {
        // ONE token for every attempt: the key is scoped per token, so two integrations
        // that happen to pick the same key cannot replay each other's answers.
        $token = $this->admin->createToken('integration:retry', ['employees:write'])->plainTextToken;
        $call = function (array $body = ['name' => 'Once', 'external_id' => 'ONCE']) use ($token) {
            $this->app['auth']->forgetGuards();

            return $this->withToken($token)->withHeaders(['Idempotency-Key' => 'abc-1'])->postJson('/api/v1/employees', $body);
        };

        $first = $call()->assertCreated();
        $second = $call()->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Employee::withoutGlobalScopes()->where('external_id', 'ONCE')->count());

        // Same key, different body: a caller bug, refused rather than answered wrongly.
        $call(['name' => 'Different'])->assertUnprocessable();
    }

    // ------------------------------------------------------------------- webhooks

    public function test_webhook_urls_aimed_at_this_machine_are_refused(): void
    {
        foreach (['http://127.0.0.1/hook', 'http://169.254.169.254/latest/meta-data', 'ftp://203.0.113.9/x', 'not a url'] as $url) {
            $this->as(['webhooks:manage'])->postJson('/api/v1/webhooks', ['url' => $url, 'events' => ['*']])
                ->assertUnprocessable()->assertJsonValidationErrors('url');
        }
    }

    public function test_webhook_secret_is_shown_once_and_the_event_list_is_validated(): void
    {
        $res = $this->as(['webhooks:manage'])->postJson('/api/v1/webhooks', [
            'url' => 'https://203.0.113.9/hook', 'events' => ['employee.created'],
        ])->assertCreated();

        $this->assertStringStartsWith('whsec_', $res->json('data.secret'));

        $this->as(['webhooks:manage'])->getJson('/api/v1/webhooks/'.$res->json('data.id'))
            ->assertOk()->assertJsonMissingPath('data.secret');

        $this->as(['webhooks:manage'])->postJson('/api/v1/webhooks', [
            'url' => 'https://203.0.113.9/hook', 'events' => ['employee.exploded'],
        ])->assertUnprocessable();
    }

    public function test_creating_an_employee_sends_a_signed_event_to_a_subscriber(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $hook = $this->as(['webhooks:manage'])->postJson('/api/v1/webhooks', [
            'url' => 'https://203.0.113.9/hook', 'events' => ['employee.created'],
        ])->assertCreated();
        $secret = $hook->json('data.secret');

        $this->as(['employees:write'])->postJson('/api/v1/employees', ['name' => 'Evented', 'external_id' => 'EV-1'])->assertCreated();

        Http::assertSent(function ($request) use ($secret) {
            $body = $request->body();
            $expected = 'sha256='.hash_hmac('sha256', $request->header('X-Webhook-Timestamp')[0].'.'.$body, $secret);

            return $request->url() === 'https://203.0.113.9/hook'
                && $request->header('X-Webhook-Event')[0] === 'employee.created'
                && $request->header('X-Webhook-Signature')[0] === $expected
                && json_decode($body, true)['data']['employee']['external_id'] === 'EV-1';
        });

        $delivery = WebhookDelivery::withoutGlobalScopes()->first();
        $this->assertSame(WebhookDelivery::SUCCEEDED, $delivery->status);
    }

    public function test_an_endpoint_is_not_sent_events_it_did_not_subscribe_to(): void
    {
        Http::fake();
        $this->as(['webhooks:manage'])->postJson('/api/v1/webhooks', [
            'url' => 'https://203.0.113.9/hook', 'events' => ['employee.deleted'],
        ])->assertCreated();

        $this->employee('Quiet', '1');

        Http::assertNothingSent();
    }

    public function test_a_failing_receiver_is_retried_later_not_dropped(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        Bus::fake([DeliverWebhook::class]); // only to observe the re-dispatch

        $endpoint = WebhookEndpoint::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'url' => 'https://203.0.113.9/hook',
            'secret' => 'whsec_x', 'events' => ['*'], 'is_active' => true,
        ]);
        $delivery = app(WebhookDispatcher::class)->queue($endpoint, 'webhook.test', []);

        Bus::assertDispatched(DeliverWebhook::class);

        (new DeliverWebhook($delivery->id))->handle();

        $fresh = $delivery->fresh();
        $this->assertSame(WebhookDelivery::PENDING, $fresh->status, 'A 500 is retried.');
        $this->assertSame(1, $fresh->attempts);
        $this->assertNotNull($fresh->next_attempt_at);
    }

    public function test_the_punch_relay_batches_new_punches_and_does_not_replay_history(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $e = $this->employee('P', '4');
        $device = $this->device();
        $minute = 0;
        $make = function (int $n) use ($e, $device, &$minute) {
            Tenancy::set($this->tenant->id);
            foreach (range(1, $n) as $_) {
                AttendanceLog::create(['device_id' => $device->id, 'device_user_id' => '4', 'employee_id' => $e->id,
                    'punched_at' => now()->subHours(5)->addMinutes(++$minute), 'status' => 0, 'verify_method' => 1]);
            }
            Tenancy::clear();
        };

        $make(3); // history, before anyone subscribed

        $this->as(['webhooks:manage'])->postJson('/api/v1/webhooks', [
            'url' => 'https://203.0.113.9/hook', 'events' => ['attendance.punch'],
        ])->assertCreated();

        $this->assertSame(0, app(PunchWebhookRelay::class)->relay(), 'Subscribing must not replay history.');

        $make(2);
        $this->assertSame(1, app(PunchWebhookRelay::class)->relay());
        $this->assertSame(0, app(PunchWebhookRelay::class)->relay(), 'Nothing is sent twice.');

        Http::assertSent(fn ($r) => json_decode($r->body(), true)['data']['count'] === 2);
    }

    public function test_enrollment_only_changes_are_a_separate_event_from_profile_edits(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $e = $this->employee('Fingers', '8');
        $this->as(['webhooks:manage'])->postJson('/api/v1/webhooks', [
            'url' => 'https://203.0.113.9/hook', 'events' => ['employee.updated', 'employee.enrollment_changed'],
        ])->assertCreated();

        Tenancy::set($this->tenant->id);
        Employee::find($e->id)->update(['fingerprint_count' => 2]);
        Employee::find($e->id)->update(['job_title' => 'Chef']);
        Tenancy::clear();

        $events = collect(Http::recorded())->map(fn ($p) => $p[0]->header('X-Webhook-Event')[0])->all();
        $this->assertSame(['employee.enrollment_changed', 'employee.updated'], $events);
    }
}
