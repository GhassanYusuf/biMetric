<?php

/*
 * Builds the Postman collection for the Integration API v1 (docs/integration-api.md).
 *
 * Owner, 2026-11-02: "generate the new collection with the proper documentation and fields
 * in raw json". The previous collection (2026-10-20) was drafted from validation rules
 * alone: placeholder bodies that fail validation ("gender": "Male", "external_id": 1, …) and
 * the website's internal routes mixed in beside the API, which sent a push to the wrong
 * endpoint. This one is the v1 API only, with working example bodies, written by hand.
 *
 * It refuses to write the file if a live /api/v1 route has no entry below, or an entry
 * names a route that does not exist, so it cannot quietly fall behind the API.
 *
 * Usage:  php tools/postman/integration-collection.php [output-file]
 *         default output: docs/postman/ZKT-Integration-API-v1.postman_collection.json
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$out = $argv[1] ?? __DIR__.'/../../docs/postman/ZKT-Integration-API-v1.postman_collection.json';

// --------------------------------------------------------------------------- helpers

/** A markdown table of body or query fields: [name, type, required?, notes]. */
function fields(string $title, array $rows): string
{
    $md = "\n\n**{$title}**\n\n| Field | Type | Required | Notes |\n|---|---|---|---|\n";
    foreach ($rows as [$name, $type, $req, $notes]) {
        $md .= "| `{$name}` | {$type} | ".($req ? 'yes' : 'no')." | {$notes} |\n";
    }

    return $md;
}

const TERMINAL = "\n\n> ⚠ **Talks to a real terminal.** Check `{{device_id}}` before sending.";
const QUEUED = "\n\n**Answer:** `202`, with a **command**. That only means *queued*. The result is the command's `status`: poll `GET /device-commands/{{command_id}}` until `is_finished` is true, and read `succeeded` / `failed`. The collection saves `command_id` for you.";

/** Saves a value from the response into a collection variable. */
function saves(array $map): array
{
    $lines = ['const j = pm.response.json();'];
    foreach ($map as $var => $path) {
        $lines[] = "if (j && j.data && {$path} !== undefined && {$path} !== null) { pm.collectionVariables.set('{$var}', String({$path})); }";
    }

    return [['listen' => 'test', 'script' => ['type' => 'text/javascript', 'exec' => $lines]]];
}

/*
 * One endpoint. $route is "METHOD uri" exactly as route:list prints the uri (for the check).
 * $path uses Postman variables. $body is an array (sent as raw JSON) or null.
 */
function req(string $route, string $name, string $path, string $doc, ?array $body = null, array $query = [], array $events = [], bool $form = false): array
{
    [$method] = explode(' ', $route, 2);
    $headers = [['key' => 'Accept', 'value' => 'application/json']];
    if ($body !== null && ! $form) {
        $headers[] = ['key' => 'Content-Type', 'value' => 'application/json'];
    }
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $headers[] = ['key' => 'Idempotency-Key', 'value' => '{{$guid}}', 'disabled' => true,
            'description' => 'Optional. Repeat a timed-out write with the same key: the first answer comes back and nothing runs twice.'];
    }

    $q = [];
    foreach ($query as $key => [$value, $enabled, $desc]) {
        $q[] = ['key' => $key, 'value' => $value, 'disabled' => ! $enabled, 'description' => $desc];
    }
    $raw = '{{base_url}}'.$path.($q ? '?'.implode('&', array_map(fn ($p) => $p['key'].'='.$p['value'], array_filter($q, fn ($p) => ! $p['disabled']))) : '');

    $request = [
        'method' => $method,
        'header' => $headers,
        'url' => [
            'raw' => rtrim($raw, '?'),
            'host' => ['{{base_url}}'],
            'path' => array_values(array_filter(explode('/', strtok($path, '?')))),
            'query' => $q,
        ],
        'description' => $doc,
    ];
    if ($form) {
        $request['body'] = ['mode' => 'formdata', 'formdata' => [['key' => 'photo', 'type' => 'file', 'src' => [],
            'description' => 'JPEG/PNG/GIF/WebP image, at most 4 MB.']]];
    } elseif ($body !== null) {
        $request['body'] = [
            'mode' => 'raw',
            'raw' => json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'options' => ['raw' => ['language' => 'json']],
        ];
    }

    return ['_route' => $route, 'name' => $name, 'request' => $request, 'event' => $events];
}

function folder(string $name, string $doc, array $items): array
{
    return ['name' => $name, 'description' => $doc, 'item' => $items];
}

$employeeFields = fields('Body fields', [
    ['name', 'string ≤255', true, 'First name(s). On POST only; on PUT/PATCH send only what changes.'],
    ['last_name', 'string ≤255', false, ''],
    ['employee_code', 'string, digits, 1–9', false, 'Personnel ID (CPR). Leave out and the next free one is allocated. Unique per company. Becomes the user ID on terminals.'],
    ['external_id', '**string** ≤128', false, 'YOUR system\'s id for the person. Must be text: `"1"`, not `1`. Unique per company. Never sent to a terminal.'],
    ['gender', '`male` \| `female`', false, 'Lowercase.'],
    ['birthday', 'date `YYYY-MM-DD`', false, 'Before today.'],
    ['email', 'email', false, ''],
    ['mobile_phone', 'string ≤32', false, 'Digits, spaces, `()` and `-`, optional leading `+`. The website stores `"+973 33445566"`.'],
    ['home_phone', 'string ≤32', false, 'As mobile_phone.'],
    ['department_id', 'integer', false, 'A company or a department from `GET /departments` (e.g. 4 = PROMOSEVEN). Must exist.'],
    ['job_title', 'string ≤255', false, ''],
    ['hired_date', 'date', false, ''],
    ['employment_type', '`full_time` \| `part_time`', false, 'Default `full_time`. Do not send `""`.'],
    ['resigned_at', 'date, today or earlier', false, '**Setting it makes them inactive and queues their removal from every terminal.** Leave out for a current employee; `null` to clear.'],
    ['privilege', '`user` \| `enroller` \| `admin` \| `superadmin`', false, 'Role on terminals. Leave out for a normal user.'],
    ['device_password', 'string, 1–8 digits', false, 'Keypad password on terminals. Write-only: never returned.'],
    ['card_number', 'integer 0–4294967295', false, 'Stored; reaches a terminal only through a push.'],
    ['access_level_ids', 'array of integers', false, 'From `GET /access-levels`. Send `[]` for none, never `""`.'],
    ['multi_card_group_id', 'integer', false, 'Must exist; leave out when not used.'],
    ['valid_period_enabled', 'boolean', false, 'When true, both dates below are required and **to must be after from**.'],
    ['access_valid_from', 'date', false, ''],
    ['access_valid_to', 'date', false, 'After access_valid_from.'],
    ['office, education, country, city, postal_code, political_status, race', 'string', false, 'Free text. `country` is the English name, e.g. `"Bahrain"`.'],
    ['home_address, work_address', 'string ≤500', false, ''],
    ['is_active', 'boolean', false, ''],
    ['push_to_device_ids', 'array of integers', false, 'After saving, queue the person onto these terminals (`meta.device_commands` / `meta.skipped_devices`). ⚠ real terminals.'],
    ['push_to_mapped_devices', 'boolean', false, 'Update only: re-push to every terminal they are on now.'],
]).'

Not accepted here: `photo` (use **Upload photo**, multipart) and the old text field `department` (use `department_id`).';

$employeeBody = [
    'name' => 'API',
    'last_name' => 'Test',
    'employee_code' => '{{new_employee_code}}',
    'external_id' => '{{new_external_id}}',
    'gender' => 'male',
    'birthday' => '1990-01-15',
    'email' => 'api.test@example.com',
    'mobile_phone' => '+973 17000000',
    'department_id' => 4,
    'job_title' => 'Test',
    'hired_date' => '2026-11-01',
    'employment_type' => 'full_time',
    'country' => 'Bahrain',
    'access_level_ids' => [],
    'valid_period_enabled' => false,
];

$commandSaves = saves(['command_id' => 'j.data.id']);

// --------------------------------------------------------------------------- the collection

$items = [
    folder('0. Start here', "Sign in, then check the token. Every other request sends `{{token}}` as a Bearer token.\n\nFor software that runs unattended, do not use a person's sign-in: create a scoped token for a service user on the server (`php artisan integration:token <email> <name> --ability=…`) and paste it into the `token` variable.", [
        req('POST api/auth/login', 'Sign in (saves {{token}})', '/api/auth/login',
            "Signs in a website user and stores the token in `{{token}}`. A sign-in token carries every ability (`*`) but is still limited by that user's permissions.\n\nSet the collection variables `email` and `password` first (Collection → Variables). They stay on your PC.".fields('Body fields', [
                ['email', 'email', true, ''],
                ['password', 'string', true, ''],
                ['device_name', 'string', false, 'A label for this token, e.g. `postman`.'],
            ]),
            ['email' => '{{email}}', 'password' => '{{password}}', 'device_name' => 'postman'], [],
            [['listen' => 'test', 'script' => ['type' => 'text/javascript', 'exec' => [
                'const j = pm.response.json();',
                "const t = j && j.data && (j.data.token || (j.data.data && j.data.data.token));",
                "if (t) { pm.collectionVariables.set('token', t); }",
            ]]]]),
        req('GET api/v1/ping', 'Ping: check the token', '/api/v1/ping',
            "Answers who you are, which company (tenant) and the token's abilities. Use it to check a token works.\n\n**Answer:** `200`. `401` = no or bad token."),
        req('GET api/v1/token-abilities', 'List token abilities', '/api/v1/token-abilities',
            "The abilities a token can carry and what each grants:\n\n| Ability | Grants |\n|---|---|\n| `employees:read` | employees, departments, access levels |\n| `employees:write` | create / change / delete employees, departments, photos |\n| `devices:read` | terminals, commands, sync runs, enrollment sessions |\n| `devices:write` | push/remove people, enroll, trigger syncs, retry/cancel commands |\n| `attendance:read` | punches, summaries, reports |\n| `webhooks:manage` | webhooks (the user also needs `settings.manage`) |"),
    ]),

    folder('1. Employees', "Create, read, change and delete employees.\n\nThree different numbers: `id` (this system's row id, used in URLs), `employee_code` (Personnel ID, also the user ID on terminals) and `external_id` (yours).\n\nEvery response uses the envelope `{success, message, data, meta, errors}`. `422` = validation, with the reason per field in `errors`.", [
        req('GET api/v1/employees', 'List employees', '/api/v1/employees',
            "Paged list, ordered by `id`.".fields('Query parameters', [
                ['search', 'string', false, 'Name or code contains.'],
                ['employee_code', 'string', false, 'Exact.'],
                ['external_id', 'string', false, 'Exact.'],
                ['department_id', 'integer', false, ''],
                ['is_active', 'boolean', false, ''],
                ['resigned', '`1` \| `0`', false, '`1` only resigned, `0` none resigned.'],
                ['employment_type', '`full_time` \| `part_time`', false, ''],
                ['device_id', 'integer', false, 'Only people on this terminal.'],
                ['updated_since', 'UTC ISO-8601', false, 'Changed since; for keeping a copy in sync.'],
                ['per_page', 'integer ≤200', false, ''],
                ['page', 'integer', false, ''],
            ]),
            null, [
                'per_page' => ['50', true, '≤ 200'],
                'search' => ['', false, 'name or code'],
                'department_id' => ['{{department_id}}', false, ''],
                'is_active' => ['1', false, ''],
                'updated_since' => ['2026-11-01T00:00:00Z', false, 'UTC'],
            ]),
        req('POST api/v1/employees', 'Create employee', '/api/v1/employees',
            "Creates one employee. **Answer:** `201` with the employee; its `id` is saved to `{{employee_id}}`.\n\nThe example body passes validation as it is. It uses `{{new_employee_code}}` and `{{new_external_id}}`; change them before a second run, since both must be unique. Afterwards `{{employee_id}}` and `{{employee_code}}` point at the new employee.".$employeeFields,
            $employeeBody, [], saves(['employee_id' => 'j.data.id', 'employee_code' => 'j.data.employee_code'])),
        req('GET api/v1/employees/{employee}', 'Get employee by id', '/api/v1/employees/{{employee_id}}',
            "One employee by this system's `id`.\n\n`fingerprint_count: null` means **never read from a terminal**, not zero."),
        req('GET api/v1/employees/by-code/{code}', 'Get employee by Personnel ID', '/api/v1/employees/by-code/{{employee_code}}',
            "One employee by `employee_code`, matched exactly as text (`0099902` ≠ `99902`). `404` if no employee in your company has it."),
        req('GET api/v1/employees/by-external-id/{externalId}', 'Get employee by your id (external_id)', '/api/v1/employees/by-external-id/{{external_id}}',
            "One employee by **your** id. `404` if none."),
        req('PUT api/v1/employees/by-external-id/{externalId}', 'Create or update by your id (upsert)', '/api/v1/employees/by-external-id/{{external_id}}',
            "**Safe to run twice.** Creates the employee if no one has this `external_id`, otherwise updates them. The URL's id wins over any `external_id` in the body.\n\n**Answer:** `201` + `meta.created: true`, or `200` + `meta.created: false`.".$employeeFields,
            ['name' => 'API', 'last_name' => 'Test', 'email' => 'api.test@example.com', 'department_id' => 4, 'job_title' => 'Test'],
            [], saves(['employee_id' => 'j.data.id'])),
        req('PATCH api/v1/employees/{employee}', 'Update employee', '/api/v1/employees/{{employee_id}}',
            "**Partial**: send only the fields that change. `PUT` behaves the same.\n\nTo update the terminals they are on in the same call, add `\"push_to_mapped_devices\": true`.".$employeeFields,
            ['job_title' => 'Senior Test', 'mobile_phone' => '+973 17000001']),
        req('GET api/v1/employees/next-code', 'Next free Personnel ID', '/api/v1/employees/next-code',
            "The `employee_code` a create without one would get."),
        req('POST api/v1/employees/{employee}/photo', 'Upload photo', '/api/v1/employees/{{employee_id}}/photo',
            "**Multipart form**, not JSON: Body → form-data → key `photo` (type File), pick an image. JPEG/PNG/GIF/WebP, at most 4 MB.", [], [], [], true),
        req('DELETE api/v1/employees/{employee}/photo', 'Remove photo', '/api/v1/employees/{{employee_id}}/photo',
            "Removes the photo. The employee is kept."),
        req('GET api/v1/employees/{employee}/attendance-summary', 'Attendance summary', '/api/v1/employees/{{employee_id}}/attendance-summary',
            "Per-day breakdown and payroll hours for one employee, as on the website.".fields('Query parameters', [
                ['from', 'date', false, 'Office-local day.'],
                ['to', 'date', false, ''],
            ]), null, ['from' => ['2026-10-01', true, ''], 'to' => ['2026-10-31', true, '']]),
        req('DELETE api/v1/employees/{employee}', 'Delete employee (record only, or ?remove_from_devices=1)', '/api/v1/employees/{{employee_id}}',
            "Without the flag: deletes the record only, **the person stays on the terminals** and can still punch. Prefer **Delete by Personnel ID**.\n\nWith `remove_from_devices=1`: `202`; removals are queued per terminal (card and fingers saved first), the employee is inactive at once and deleted only after every terminal confirms. A failed removal keeps the record, inactive.".TERMINAL,
            null, ['remove_from_devices' => ['1', true, 'Also take them off every terminal.']]),
        req('DELETE api/v1/employees/by-code/{code}', 'Delete by Personnel ID (system AND terminals)', '/api/v1/employees/by-code/{{employee_code}}',
            "Deletes the employee **and always takes them off every terminal**; there is no record-only form. Card and fingerprints are saved into the system before each terminal delete.\n\n- `200` + `meta.deleted: true`: on no terminal, deleted at once.\n- `202` + `meta.deleted: false`: removals queued (`meta.device_commands`); deleted once every terminal confirms.\n- `404`: no employee has that code.\n\n`data` = `{id, employee_code, name}` of who it was. Check it is the person you meant.".TERMINAL),
    ]),

    folder('2. Terminals and pushing people', "Terminals (devices), and putting people on them or taking them off.\n\n**Check `capabilities` on a terminal before offering an action**: not every model can do everything (the C3 panels cannot write users or capture fingers).", [
        req('GET api/v1/devices', 'List terminals', '/api/v1/devices',
            "Every terminal with `is_online`, `is_writable`, `write_refusal` and `capabilities` (`confirmed` / `implemented` / `not_implemented` / `unknown` per action)."),
        req('GET api/v1/devices/{device}', 'Get terminal', '/api/v1/devices/{{device_id}}',
            "One terminal, with its capabilities."),
        req('POST api/v1/employees/{employee}/devices/{device}/push', 'Push employee to terminal', '/api/v1/employees/{{employee_id}}/devices/{{device_id}}/push',
            "Queues writing this person onto this terminal: name, role, keypad password, and the card and fingers the system holds for them. A first push gives them their Personnel ID as the terminal's user ID (or the next free number when it is taken there).\n\nNo body.".QUEUED.TERMINAL,
            null, [], $commandSaves),
        req('POST api/v1/employees/{employee}/devices/{device}/remove', 'Remove employee from terminal', '/api/v1/employees/{{employee_id}}/devices/{{device_id}}/remove',
            "Queues taking this person off this terminal. Their card and fingers are saved into the system first; **the employee record is kept**.\n\nNo body.".QUEUED.TERMINAL,
            null, [], $commandSaves),
        req('PUT api/v1/employees/{employee}/devices/{device}/card', 'Assign card number (synchronous)', '/api/v1/employees/{{employee_id}}/devices/{{device_id}}/card',
            "Writes a typed card number to this terminal and reads it back. **Synchronous**: may take a few seconds; `502` if the terminal is unreachable.".fields('Body fields', [
                ['card_number', '**string** of digits, 1–10', true, 'The number printed on the card.'],
            ]).TERMINAL,
            ['card_number' => '2857525']),
    ]),

    folder('3. Fingerprint and card enrollment', "Enrollment needs **a person at the terminal**. The call returns at once with a **command**; when it runs, it opens a **session** and the terminal waits for a finger (~30 s) or a card tap (~20 s).\n\nSession `status`: `pending → sent → waiting → success | timeout | failed | cancelled`. `timeout` means nobody came, not a broken terminal.\n\nThe person must be on the terminal first (**Push employee to terminal**).", [
        req('POST api/v1/employees/{employee}/devices/{device}/enrollment/fingerprint', 'Start fingerprint enrollment', '/api/v1/employees/{{employee_id}}/devices/{{device_id}}/enrollment/fingerprint',
            "Puts the terminal into capture mode for this person. **Be at the terminal**: press the same finger 3 times, lifting it in between.\n\n**Answer:** `200` with the **command** that starts the capture; its id is saved to `{{command_id}}`. The enrollment **session** is created when that command runs, a second later: then run **Find enrollment session (for this command)** until `status` is `success`. `409` = a capture is already waiting for them there. `422` = this terminal cannot capture fingers.".fields('Body fields', [
                ['finger_slot', 'integer 0–9', true, '0–4 left hand little→thumb, 5–9 right hand thumb→little. **6 = right index**.'],
            ]).TERMINAL,
            ['finger_slot' => 6], [], $commandSaves),
        req('POST api/v1/employees/{employee}/devices/{device}/enrollment/card', 'Start card enrollment', '/api/v1/employees/{{employee_id}}/devices/{{device_id}}/enrollment/card',
            "Puts the terminal into card-capture mode (~20 s for a tap). `422` where the model cannot (e.g. the F18 cannot hand over a scanned card number: use **Assign card number** instead).\n\nNo body. **Answer:** `200` with the command (saved to `{{command_id}}`); then **Find enrollment session (for this command)**.".TERMINAL,
            null, [], $commandSaves),
        req('GET api/v1/enrollment-sessions', 'Find enrollment session (for this command)', '/api/v1/enrollment-sessions',
            "The session started by `{{command_id}}`, saved to `{{session_id}}`. **Run this after Start fingerprint/card enrollment, until `status` is final**: `success`, `timeout`, `failed` or `cancelled`. An empty `data` list = the command has not run yet: wait a second and send again.\n\nWithout `device_command_id` (untick it) it lists recent sessions.".fields('Query parameters', [
                ['device_command_id', 'integer', false, 'The command an enrollment start answered with.'],
                ['employee_id', 'integer', false, ''],
                ['device_id', 'integer', false, ''],
                ['status', 'string', false, '`pending` `sent` `waiting` `success` `timeout` `failed` `cancelled`'],
                ['active', 'boolean', false, 'Only sessions still waiting.'],
            ]),
            null, ['device_command_id' => ['{{command_id}}', true, 'from Start enrollment'], 'employee_id' => ['{{employee_id}}', false, '']],
            saves(['session_id' => 'j.data[0] && j.data[0].id'])),
        req('GET api/v1/enrollment-sessions/{session}', 'Get enrollment session', '/api/v1/enrollment-sessions/{{session_id}}',
            "One session by its id (set by **Find enrollment session**). Final states: `success`, `timeout`, `failed`, `cancelled`; `events` shows each step and the sample quality scores."),
        req('POST api/v1/enrollment-sessions/{session}/cancel', 'Cancel enrollment session', '/api/v1/enrollment-sessions/{{session_id}}/cancel',
            "Releases the terminal. Do this if the person walked away.".TERMINAL),
        req('DELETE api/v1/employees/{employee}/devices/{device}/enrollment/fingerprints/{slot}', 'Delete one fingerprint (destructive)', '/api/v1/employees/{{employee_id}}/devices/{{device_id}}/enrollment/fingerprints/{{finger_slot}}',
            "Deletes one finger from the terminal. **Destructive**: the person must stand at a terminal to enroll it again.".TERMINAL),
        req('DELETE api/v1/employees/{employee}/devices/{device}/enrollment/card', 'Clear card on terminal', '/api/v1/employees/{{employee_id}}/devices/{{device_id}}/enrollment/card',
            "Clears the card on this terminal; keeps the user and their fingers.".TERMINAL),
    ]),

    folder('4. Commands and sync runs', "Everything that touches a terminal is a **command**: `queued → running → succeeded | failed | cancelled`. Trust `is_finished`. `message` is for people; `error` / `raw_response` are for whoever debugs. One command runs per terminal at a time.", [
        req('GET api/v1/device-commands', 'List commands', '/api/v1/device-commands',
            "Recent commands, newest first.".fields('Query parameters', [
                ['device_id', 'integer', false, ''],
                ['status', '`queued` \| `running` \| `succeeded` \| `failed` \| `cancelled`', false, ''],
                ['command', 'string', false, 'e.g. `push_user`'],
            ]), null, ['device_id' => ['{{device_id}}', false, ''], 'status' => ['failed', false, '']]),
        req('GET api/v1/device-commands/{command}', 'Get command (poll this)', '/api/v1/device-commands/{{command_id}}',
            "One command. Poll until `is_finished` is true, then read `status` and `message`."),
        req('POST api/v1/device-commands/{command}/retry', 'Retry command', '/api/v1/device-commands/{{command_id}}/retry',
            "Re-queues a failed command.".TERMINAL, null, [], $commandSaves),
        req('POST api/v1/device-commands/{command}/cancel', 'Cancel command', '/api/v1/device-commands/{{command_id}}/cancel',
            "Cancels a command that has not run yet."),
        req('POST api/v1/devices/{device}/commands', 'Send a command to a terminal', '/api/v1/devices/{{device_id}}/commands',
            "Queues one terminal command.".fields('Body fields', [
                ['command', 'string', true, '`sync_attendance` (read punches), `sync_enrollment` (read fingers/cards), `import_employees`, `reconcile_users`, `health_check`, `read_info`, `probe_capabilities`, `sync_time` (set its clock)'],
                ['full', 'boolean', false, '`sync_attendance` only: re-read everything instead of only what is new.'],
                ['from', 'date', false, '`sync_attendance` only: first office-local day to import.'],
                ['to', 'date', false, '`sync_attendance` only: last day.'],
            ]).QUEUED.TERMINAL,
            ['command' => 'sync_attendance', 'full' => false], [], $commandSaves),
        req('GET api/v1/device-sync-runs', 'List attendance sync runs', '/api/v1/device-sync-runs',
            "Each punch read with its counters: `records_read`, `records_imported`, `records_duplicate`, `records_undecodable` (any non-zero needs attention), `records_unmatched`, `records_out_of_range`.",
            null, ['device_id' => ['{{device_id}}', false, '']]),
        req('GET api/v1/device-sync-runs/{run}', 'Get sync run', '/api/v1/device-sync-runs/{{run_id}}', 'One sync run.'),
    ]),

    folder('5. Attendance', "Punches. All times are **UTC ISO-8601**. A bare date in `from` / `to` means an office-local (Bahrain) day.", [
        req('GET api/v1/attendance', 'Punch feed (cursor)', '/api/v1/attendance',
            "A **feed**, oldest first, keyed on the row `id`. Store `meta.next_after_id` and send it as `after_id` next time: you get every new punch exactly once, including punches synced late. `meta.has_more` = call again.\n\nA punch by a terminal user linked to no employee has `employee_id: null` and still appears, with `device_user_id`.".fields('Query parameters', [
                ['after_id', 'integer', false, 'Rows with a greater id. Start at 0.'],
                ['limit', 'integer ≤1000', false, 'Default 200.'],
                ['from', 'date or UTC timestamp', false, ''],
                ['to', 'date or UTC timestamp', false, ''],
                ['employee_id', 'integer', false, ''],
                ['external_id', 'string', false, 'Unknown id returns nothing, never everyone.'],
                ['device_id', 'integer', false, ''],
                ['matched', 'boolean', false, '`true` linked to an employee, `false` unlinked only.'],
            ]),
            null, [
                'after_id' => ['0', true, 'your saved cursor'],
                'limit' => ['200', true, '≤ 1000'],
                'employee_id' => ['{{employee_id}}', false, ''],
                'from' => ['2026-11-01', false, 'office-local day'],
            ]),
        req('GET api/v1/reports/attendance', 'Attendance report', '/api/v1/reports/attendance',
            "The website's attendance report for a period.".fields('Query parameters', [
                ['from', 'date', false, ''],
                ['to', 'date', false, ''],
                ['department_id', 'integer', false, ''],
            ]), null, ['from' => ['2026-10-01', true, ''], 'to' => ['2026-10-31', true, ''], 'department_id' => ['{{department_id}}', false, '']]),
    ]),

    folder('6. Departments and access levels', "A **company** is a department with no `parent_id`; its departments name it as their parent (e.g. 4 = PROMOSEVEN, 12 = PROMOSEVEN › Information Technology).", [
        req('GET api/v1/departments', 'List departments and companies', '/api/v1/departments', 'Every company and department with `parent_id` and employee counts.'),
        req('GET api/v1/departments/{department}', 'Get department', '/api/v1/departments/{{department_id}}', 'One department.'),
        req('POST api/v1/departments', 'Create department', '/api/v1/departments',
            "Creates a company (no `parent_id`) or a department under one. Its id is saved to `{{new_department_id}}`.".fields('Body fields', [
                ['name', 'string', true, 'Unique among its siblings.'],
                ['parent_id', 'integer', false, 'The company it belongs to. Leave out to create a company.'],
                ['code', 'string ≤50', false, ''],
                ['manager_id', 'integer', false, 'An employee id.'],
            ]),
            ['name' => 'API Test Department', 'parent_id' => 4], [], saves(['new_department_id' => 'j.data.id'])),
        req('PATCH api/v1/departments/{department}', 'Update department', '/api/v1/departments/{{new_department_id}}',
            "Partial update. Renaming updates the old text `department` of its employees too.", ['name' => 'API Test Department (renamed)']),
        req('DELETE api/v1/departments/{department}', 'Delete department', '/api/v1/departments/{{new_department_id}}',
            "Deletes a department. `409` while it still has employees or departments under it. Uses `{{new_department_id}}` (the one you created), never a real one by default."),
        req('GET api/v1/access-levels', 'List access levels', '/api/v1/access-levels', 'The ids to use in an employee\'s `access_level_ids`.'),
    ]),

    folder('7. Tokens', "Tokens for other software. A token can only **narrow** its user's permissions. Only a signed-in user can create one, never another token.", [
        req('GET api/v1/tokens', 'List my tokens', '/api/v1/tokens', 'Your integration tokens (never their secret).'),
        req('POST api/v1/tokens', 'Create token', '/api/v1/tokens',
            "**The token is shown ONCE** in the answer; only its hash is stored. Copy it to the other system.".fields('Body fields', [
                ['name', 'string ≤80', true, 'What it is for.'],
                ['abilities', 'array', true, 'From **List token abilities**.'],
                ['expires_at', 'date', false, 'In the future. Leave out for no expiry.'],
            ]),
            ['name' => 'payroll-sync', 'abilities' => ['employees:read', 'attendance:read'], 'expires_at' => '2027-11-01'],
            [], saves(['token_id' => 'j.data.id'])),
        req('DELETE api/v1/tokens/{token}', 'Revoke token', '/api/v1/tokens/{{token_id}}', 'Revokes a token at once.'),
    ]),

    folder('8. Webhooks', "Instead of polling, this system calls **your** URL when something happens. Needs `webhooks:manage` and the `settings.manage` permission.\n\nEach call is signed: `X-Webhook-Signature: sha256=HMAC_SHA256(secret, \"{X-Webhook-Timestamp}.{raw body}\")`. Verify it, reject timestamps older than ~5 minutes, dedupe on `X-Webhook-Id`. Delivery is at-least-once and not ordered.", [
        req('GET api/v1/webhook-events', 'List webhook events', '/api/v1/webhook-events',
            "`attendance.punch` (batched, ≤100 per call), `employee.created`, `employee.updated`, `employee.deleted`, `employee.enrollment_changed`, `enrollment.finished`, `device_command.finished`, `device.status_changed`, `webhook.test`."),
        req('GET api/v1/webhooks', 'List webhooks', '/api/v1/webhooks', 'Your webhook endpoints.'),
        req('POST api/v1/webhooks', 'Create webhook', '/api/v1/webhooks',
            "**The `secret` is shown ONCE.** Store it in the receiving system. The id is saved to `{{webhook_id}}`.".fields('Body fields', [
                ['url', 'http(s) URL ≤2048', true, 'Your receiver. LAN addresses are allowed; loopback and link-local are refused.'],
                ['events', 'array', true, 'Event names, or `["*"]` for all.'],
                ['description', 'string ≤255', false, ''],
                ['is_active', 'boolean', false, ''],
            ]),
            ['url' => 'https://hr.example.com/hooks/zkt', 'events' => ['attendance.punch', 'employee.created'], 'description' => 'HR system'],
            [], saves(['webhook_id' => 'j.data.id'])),
        req('GET api/v1/webhooks/{webhook}', 'Get webhook', '/api/v1/webhooks/{{webhook_id}}', 'One endpoint.'),
        req('PATCH api/v1/webhooks/{webhook}', 'Update webhook', '/api/v1/webhooks/{{webhook_id}}',
            "Partial update. `\"is_active\": true` re-enables a disabled endpoint and resets its failure count.", ['is_active' => true, 'events' => ['*']]),
        req('DELETE api/v1/webhooks/{webhook}', 'Delete webhook', '/api/v1/webhooks/{{webhook_id}}', 'Deletes the endpoint.'),
        req('POST api/v1/webhooks/{webhook}/rotate-secret', 'Rotate secret', '/api/v1/webhooks/{{webhook_id}}/rotate-secret',
            "New secret, shown once. **The old one stops working immediately.**"),
        req('POST api/v1/webhooks/{webhook}/test', 'Send test event', '/api/v1/webhooks/{{webhook_id}}/test', 'Sends a signed `webhook.test` to the URL.'),
        req('GET api/v1/webhooks/{webhook}/deliveries', 'List deliveries', '/api/v1/webhooks/{{webhook_id}}/deliveries',
            "What was sent and what came back.", null, ['status' => ['failed', false, '`pending` | `succeeded` | `failed`']],
            saves(['delivery_id' => 'j.data[0] && j.data[0].id'])),
        req('POST api/v1/webhook-deliveries/{delivery}/redeliver', 'Redeliver', '/api/v1/webhook-deliveries/{{delivery_id}}/redeliver',
            "Sends the same delivery again: same event id, same body."),
    ]),
];

// --------------------------------------------------------------------------- completeness check

$documented = [];
array_walk_recursive($items, function ($v, $k) use (&$documented) {
    if ($k === '_route') {
        $documented[] = $v;
    }
});

$live = [];
foreach (app('router')->getRoutes() as $route) {
    if (! str_starts_with($route->uri(), 'api/v1/')) {
        continue;
    }
    foreach (array_diff($route->methods(), ['HEAD']) as $m) {
        $live[] = "{$m} {$route->uri()}";
    }
}

// PUT and PATCH share a route; the collection shows one of the pair.
$covered = fn (string $r) => in_array($r, $documented, true)
    || in_array(preg_replace('/^PUT /', 'PATCH ', $r), $documented, true)
    || in_array(preg_replace('/^PATCH /', 'PUT ', $r), $documented, true);
$missing = array_values(array_filter($live, fn ($r) => ! $covered($r)));
$unknown = array_values(array_filter($documented, fn ($r) => str_starts_with($r, 'POST api/auth') ? false : ! in_array($r, $live, true)));

if ($missing !== [] || $unknown !== []) {
    fwrite(STDERR, "Not written. The collection and the live routes disagree.\n");
    foreach ($missing as $r) {
        fwrite(STDERR, "  route with no entry: {$r}\n");
    }
    foreach ($unknown as $r) {
        fwrite(STDERR, "  entry with no route: {$r}\n");
    }
    exit(1);
}

array_walk_recursive($items, function () {});
$strip = function (array $node) use (&$strip): array {
    unset($node['_route']);
    if ($node['event'] ?? null) {
        // keep
    } elseif (array_key_exists('event', $node)) {
        unset($node['event']);
    }
    if (isset($node['item'])) {
        $node['item'] = array_map($strip, $node['item']);
    }

    return $node;
};
$items = array_map($strip, $items);

$collection = [
    'info' => [
        'name' => 'ZKT Integration API v1',
        'description' => "The Integration API (`/api/v1`) for other software: HR, payroll, access control. Contract: `docs/integration-api.md` in the repository.\n\n"
            ."Generated ".gmdate('Y-m-d')." by `tools/postman/integration-collection.php`. Every live `/api/v1` route is here; the generator refuses to run if one is missing.\n\n"
            ."**Setup**\n1. Collection → Variables: set `base_url`, and `email` / `password` for signing in (or paste a token into `token`).\n2. Run **0. Start here → Sign in**: the token is saved for every other request.\n3. Ids are variables (`employee_id`, `device_id`, …). Requests that create something save its id for the next request.\n\n"
            ."**Conventions**\n- Envelope: `{success, message, data, meta, errors}`. `401` no/bad token, `403` missing ability or permission, `404` not found, `422` validation (reason per field in `errors`), `429` rate limit (120/min per token).\n- Times are UTC ISO-8601.\n- Anything that touches a terminal answers `202` with a command or session: **queued, not done**. Poll it.\n- Writes accept an optional `Idempotency-Key` header (disabled by default in each request).\n\n"
            ."⚠ **This is a live system.** Requests marked *Talks to a real terminal* write to real hardware. Default ids point at the test employee (1829, Personnel ID 90110121) and IT ROOM (15).",
        'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    ],
    'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']]],
    /*
     * Stops a request whose URL names an empty variable, with a message that says which and
     * how to fill it. Without it an empty {{session_id}} sent ".../enrollment-sessions/",
     * which the server redirects and then refuses (owner's test, 2026-11-02).
     */
    'event' => [['listen' => 'prerequest', 'script' => ['type' => 'text/javascript', 'exec' => [
        "const how = { token: 'run 0. Start here → Sign in', command_id: 'send a request that queues a command first (Push, Start enrollment, …)', session_id: 'run 3. → Find enrollment session (for this command) first', new_department_id: 'run Create department first', webhook_id: 'run Create webhook first', delivery_id: 'run List deliveries first', token_id: 'run Create token first', run_id: 'copy an id from List attendance sync runs' };",
        "const names = (pm.request.url.toString().match(/{{(\\w+)}}/g) || []).map(v => v.slice(2, -2));",
        "if (pm.request.auth === undefined || pm.request.auth === null || pm.request.auth.type !== 'noauth') { names.push('token'); }",
        "for (const n of names) { if (n === 'token' && pm.info.requestName.startsWith('Sign in')) { continue; } const v = pm.variables.get(n); if (v === undefined || v === null || String(v).trim() === '') { throw new Error('{{' + n + '}} is empty: ' + (how[n] || 'set it under Collection → Variables') + '. (If it is filled under Variables, check no Environment is selected, top right.)'); } }",
    ]]]],
    'variable' => array_map(fn ($k, $v) => ['key' => $k, 'value' => $v[0], 'description' => $v[1]], array_keys($vars = [
        'base_url' => ['https://zkt.takeone.bh', 'No trailing slash.'],
        'email' => ['', 'For Sign in. Kept in your Postman only.'],
        'password' => ['', 'For Sign in. Kept in your Postman only.'],
        'token' => ['', 'Set by Sign in, or paste an integration token.'],
        'employee_id' => ['1829', "This system's id (the test employee)."],
        'employee_code' => ['90110121', 'Personnel ID (the test employee).'],
        'external_id' => ['1', 'Your id for the person (the test employee has "1").'],
        'new_employee_code' => ['90110122', 'For Create employee: a Personnel ID nobody has yet.'],
        'new_external_id' => ['API-TEST-0002', 'For Create employee: an external_id nobody has yet.'],
        'device_id' => ['15', 'Terminal: 15 = IT ROOM (F18).'],
        'finger_slot' => ['6', '0–9; 6 = right index.'],
        'department_id' => ['4', '4 = PROMOSEVEN.'],
        'new_department_id' => ['', 'Set by Create department.'],
        'command_id' => ['', 'Set by any request that queues a command.'],
        'session_id' => ['', 'Set by Start fingerprint/card enrollment.'],
        'run_id' => ['', 'A sync run id.'],
        'token_id' => ['', 'Set by Create token.'],
        'webhook_id' => ['', 'Set by Create webhook.'],
        'delivery_id' => ['', 'Set by List deliveries.'],
    ]), $vars),
    'item' => $items,
];

@mkdir(dirname($out), 0775, true);
file_put_contents($out, json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
printf("written: %s (%d requests, all %d live /api/v1 routes covered)\n", $out, count($documented), count($live));
