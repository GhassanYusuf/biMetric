# Integrating from other platforms

The package is plain PHP with no dependencies, so "another platform" means one of
two things: another PHP context (CLI, a different framework, a worker daemon), or a
non-PHP runtime talking to a thin PHP boundary.

## Plain PHP script

Nothing but the autoloader:

```php
require __DIR__ . '/vendor/autoload.php';

use Promoseven\Biometric\Client\Device;
use Promoseven\Biometric\Value\DeviceConfig;

$config = new DeviceConfig(host: '192.168.7.62', timezone: '+03:00');

$punches = Device::run($config, fn (Device $d) => $d->attendance()->all());

foreach ($punches as $p) {
    // $p['punched_at'] is DEVICE-LOCAL. Convert before storing.
    echo $p['user_id'], ' ', $p['punched_at']->setTimezone(new DateTimeZone('UTC'))->format('c'), PHP_EOL;
}
```

## CLI tool

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';

use Promoseven\Biometric\Client\Device;
use Promoseven\Biometric\Exception\DeviceException;
use Promoseven\Biometric\Value\DeviceConfig;

$config = new DeviceConfig(host: $argv[1] ?? '192.168.7.62');

try {
    $out = Device::run($config, function (Device $d) use ($argv) {
        return match ($argv[2] ?? 'info') {
            'info'       => $d->info(),
            'users'      => array_map(fn ($u) => $u->toArray(), $d->users()->all()),
            'attendance' => ['count' => $d->attendance()->count()],
            'slots'      => $d->templates()->slotsByUserId(),
            default      => throw new InvalidArgumentException('unknown command'),
        };
    });

    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
} catch (DeviceException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit($e->isRetryable() ? 75 : 1);   // 75 = EX_TEMPFAIL, safe to retry
}
```

Exit code 75 (`EX_TEMPFAIL`) for retryable failures lets a supervisor or cron
wrapper distinguish "try again" from "this will never work" without parsing text.

## Node.js, Python, or anything else

There is no native port. Two workable shapes:

### 1. Shell out to the CLI tool

```js
import { execFile } from 'node:child_process';

function readDevice(host, command) {
    return new Promise((resolve, reject) => {
        execFile('php', ['bin/zkteco.php', host, command], (err, stdout, stderr) => {
            if (err) return reject(new Error(stderr.trim() || err.message));
            resolve(JSON.parse(stdout));
        });
    });
}
```

Simple and honest. The cost is process startup per call, which is irrelevant next
to a device read that takes seconds anyway.

### 2. Put an HTTP boundary in front

Run a small PHP service exposing the operations you need, and call it over HTTP.
This application *is* that service — see [api-endpoints.md](api-endpoints.md). A
Node worker can queue a sync and poll the command:

```js
const { data } = await post(`/api/devices/${id}/commands`, { command: 'sync_attendance' });
// data.id is the command; poll /api/device-commands/{id} or subscribe over MQTT
```

Prefer this when more than one consumer needs device access: the queue's
one-command-per-device serialisation is the thing you actually want, and
re-implementing it per client is how two callers end up talking to one terminal at
once.

## What you must supply yourself

The library deliberately has no:

| Concern | Why it is yours |
|---|---|
| Retry policy | read `isRetryable()` and apply your own backoff |
| Serialisation per device | **two concurrent sessions to one terminal corrupt both** |
| Persistence | it returns values; storing them is a host decision |
| Logging | no logger is injected; wrap the calls |
| Scheduling | no timers, no daemon |
| Timezone conversion | it returns device-local time; storing UTC is your call |

The serialisation one is not optional. Whatever your platform, ensure only one
process talks to a given terminal at a time. This app uses
`WithoutOverlapping("device:{$id}")`; a Node worker would need a distributed lock;
a cron script needs a lockfile.

## Porting to another language

If you write a native port, the things that will bite you are all in
[protocol-overview.md](protocol-overview.md):

1. **UDP, not TCP** on this hardware.
2. **Open a fresh session for every bulk read.** The device keeps sending after
   you stop reading, and the leftovers are well-formed packets that look like the
   next command's reply.
3. **`CMD_REFRESH_DATA` after every write**, or the write silently does not
   persist.
4. **`group ≥ 1`** in a user record, or the user cannot authenticate.
5. **Pad every timestamp component.** A two-digit format specifier against a
   one-digit value returns false and silently drops the record — that cost 34% of
   the attendance history here.
6. **Timestamps are device-local**, carrying no zone.
7. **Fail safe on template decoding.** Report "unknown", never "none".

Reproduce the fixture tests against
`vendor/promoseven/biometric-protocol/tests/Fixtures/fingerprint-template-table.bin` (a real
22,066-byte capture)
and you will have covered the decoding that is hardest to get right.
