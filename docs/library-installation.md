# Installing `promoseven/biometric-protocol`

## What you are installing

31 PHP classes that speak the ZKTeco standalone binary protocol over UDP. It
requires **PHP ≥ 8.2 and nothing else** — no framework, no HTTP client, no
logger, no config system. That constraint is enforced by a test, not just
intended: see [testing-strategy.md](testing-strategy.md).

## In this repository (GitHub, installed from source)

The library is **not** in-tree. It is its own private repository,
`GhassanYusuf/biometric-protocol`, and since 2026-10-25 this app installs it from
there by tag. Before that it was a composer *path* repository pointing at a checkout
beside the app (`../../biometric-protocol`, symlinked into `vendor/`). That meant a
fresh clone of the app could not install until the library was also copied to that
exact folder.

```json
{
    "repositories": [
        { "type": "vcs", "url": "git@github.com:GhassanYusuf/biometric-protocol.git", "no-api": true }
    ],
    "require": {
        "promoseven/biometric-protocol": "^2.16"
    },
    "config": {
        "preferred-install": { "promoseven/biometric-protocol": "source", "*": "dist" }
    }
}
```

Why each setting:

- **`no-api: true`.** Composer talks to the repo with plain `git` over SSH, not the
  GitHub API, so no API token is needed. A machine only needs an SSH key that can
  read the repo.
- **`source` for this package.** `vendor/promoseven/biometric-protocol` is a git
  clone, not a zip. The zip honours the library's `.gitattributes` `export-ignore`,
  so it has no `tests/`, and the app's `Protocol` test suite
  (`phpunit.xml`) runs those tests from `vendor/`. A private repo's zip would also
  need an API token.
- **`^2.16`**, resolved to a tag and recorded in `composer.lock`. Pin the exact
  version deliberately with `composer update promoseven/biometric-protocol`.

### Access on a machine

The repo is private. A machine needs an SSH key GitHub accepts for it: an account
key, or a **deploy key** on that repo. A deploy key opens one repo only, so a machine
that also pushes the app with a deploy key needs a second key, reached through an
SSH host alias. The production box (2026-10-25) does this:

```
# /root/.ssh/config
Host github-biometric
    HostName github.com
    User git
    IdentityFile ~/.ssh/id_ed25519_biometric
    IdentitiesOnly yes
```

```bash
# Make composer's github.com URL use that alias, for this one repo only.
git config --global url."git@github-biometric:GhassanYusuf/biometric-protocol".insteadOf \
    "git@github.com:GhassanYusuf/biometric-protocol"
```

`composer.json` keeps the canonical `git@github.com:` URL so it works unchanged on a
machine with an account key. The alias is per-machine configuration, not part of
the repo. On the production box composer runs as **root** (the key is root's). Run
`chown -R www-data:www-data vendor/promoseven` afterwards.

### Changing the library

The working copy is still `/var/biometric-protocol/`, but the app **no longer sees
edits there live**. `vendor/` holds a separate clone at the locked tag. To ship a
library change to the app:

1. Commit in `/var/biometric-protocol`, bump the version, tag it (`vX.Y.Z`),
   `git push origin main --tags`.
2. In the app: `composer update promoseven/biometric-protocol`, then commit
   `composer.lock`.
3. Restart the queue worker (`php artisan queue:restart`), since it holds the old
   classes in memory.

To try an untagged change in the app first, a temporary
`composer require promoseven/biometric-protocol:dev-main` works once it is pushed.
Put the tag constraint back before committing.

## In another project

Point at the git remote and pin a tag:

```json
{
    "repositories": [
        { "type": "vcs", "url": "git@github.com:GhassanYusuf/biometric-protocol.git", "no-api": true }
    ],
    "require": {
        "promoseven/biometric-protocol": "^2.16"
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
