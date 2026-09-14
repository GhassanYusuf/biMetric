# Installing `promoseven/biometric-protocol`

## What you are installing

31 PHP classes that speak the ZKTeco standalone binary protocol over UDP. It
requires **PHP ≥ 8.2 and nothing else** — no framework, no HTTP client, no
logger, no config system. That constraint is enforced by a test, not just
intended: see [testing-strategy.md](testing-strategy.md).

## In this repository (path repository)

The library is **not** in-tree. It is its own repository, checked out beside this
one, and consumed as a path repository so an edit is live immediately with no
publish step:

```
Projects/
├── biometric-protocol/     <- the library, its own git repo, its own tags
└── payroll/app/            <- this application, one consumer
```

```json
{
    "repositories": [
        { "type": "path", "url": "../../biometric-protocol", "options": { "symlink": true } }
    ],
    "require": {
        "promoseven/biometric-protocol": "@dev"
    }
}
```

Composer junctions it to `vendor/promoseven/biometric-protocol`, so
`composer dump-autoload` is enough after adding a class. `@dev` tracks the
library's checked-out branch; pin `^1.0` once you install from a registry.

**Anyone cloning this app needs both repositories.** The relative path is the
development convenience; a private Composer registry is the deployment answer.

## In another project

Point at the git remote and pin a tag:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/promoseven/biometric-protocol.git" }
    ],
    "require": {
        "promoseven/biometric-protocol": "^1.0"
    }
}
```

Or vendor it wholesale — copy the library's `src/` and register PSR-4 (permitted
only within the terms of your licence):

```json
{
    "autoload": {
        "psr-4": { "Promoseven\\Biometric\\": "lib/biometric-protocol/src/" }
    }
}
```

Copying is a legitimate option precisely because the package has no
dependencies to reconcile.

## Requirements

| | |
|---|---|
| PHP | ≥ 8.2 |
| Extensions | `sockets` (or `ext-standard` UDP stream functions) |
| Network | Outbound UDP to the terminal's port 4370 |

The device must be reachable directly. There is no cloud relay and no vendor
service in the path.

## First contact

```php
use Promoseven\Biometric\Client\Device;
use Promoseven\Biometric\Value\DeviceConfig;

$config = new DeviceConfig(
    host: '192.168.7.62',
    port: 4370,
    commKey: 0,
    timeout: 8.0,
    timezone: '+03:00',
);

$info = Device::run($config, fn (Device $d) => $d->info());

print_r($info);
```

`Device::run()` opens a session, hands you the device, and **closes it even if
your callback throws**. Prefer it over `Device::open()` unless you genuinely need
to manage the lifetime yourself.

Expected output from the reference device:

```
Array
(
    [model] => F18/ID
    [firmware_version] => Ver 6.60 Apr 26 2016
    [platform] => ZMM210_TFT
    [serial_number] => AIOR204860846
    ...
)
```

## Configuration that matters

**`timezone`** is the device's own timezone (`+03:00` here), not your server's and
not UTC. Attendance timestamps carry no zone information; getting this wrong
shifts every punch by the difference. There is no way for the library to detect
it.

**`commKey`** is 0 when unset. A non-zero key triggers the `CMD_AUTH` exchange.

**`timeout`** (seconds, default `8.0`) applies per datagram. Bulk reads of a large
attendance log legitimately take many multiples of it.

A fixed offset like `+03:00` is deliberate for deployments that do not observe
DST. Pass a named zone (`'Europe/Berlin'`) where DST applies, or every punch will
be an hour out for half the year.

## Verifying the install

```php
$ok = Device::run($config, fn (Device $d) => $d->ping());
```

If that times out, the causes in likelihood order:

1. Wrong IP, or the device is on another VLAN.
2. A firewall dropping outbound UDP 4370.
3. The device is reseller-branded firmware that speaks CSL HTTP rather than this
   protocol — see [troubleshooting.md](troubleshooting.md).
4. A wrong comm key, which presents as a timeout rather than a clear rejection.

## What the library will not do for you

It has no logger, no retry policy, no persistence and no scheduler. Those are
host-application concerns, and building them in is what would have made it
un-reusable. See [integration-laravel.md](integration-laravel.md) and
[integration-other-platforms.md](integration-other-platforms.md) for how to supply
them.

## Strongly recommended: ext-sockets

The package requires only `php ^8.2`. `ext-sockets` is a **suggest**, and
everything works without it — but bulk reads are markedly less reliable.

Without it the socket sits on the OS default receive buffer, typically 64 KB. One
attendance table on an F18 is 412 KB, and this protocol has no flow control, so any
pause while reading means the kernel discards the overflow. It surfaces as:

```
Incomplete transfer: got 89,088 of 412,084 bytes
```

Measured on real hardware: roughly **1 read in 3** failed on the 64 KB default,
against **25 of 26** completing with a 4 MB buffer.

Enable it:

| Platform | How |
|---|---|
| Debian/Ubuntu | `apt install php8.2-sockets` |
| Windows / XAMPP | uncomment `extension=sockets` in `php.ini` |
| Docker (official images) | `docker-php-ext-install sockets` |

Then **restart every long-running PHP process** — FPM, the web server, and any
queue workers. A worker started before the change keeps the old configuration and
will quietly keep using the 64 KB buffer.

Verify:

```php
$t = new UdpTransport($config);
$t->open();
echo $t->receiveBufferBytes(); // null = not applied
```

On Linux the kernel halves the request and clamps it to `net.core.rmem_max`, so a
granted value far below what you asked for means raising that sysctl.
