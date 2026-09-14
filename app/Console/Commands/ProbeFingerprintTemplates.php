<?php

namespace App\Console\Commands;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Promoseven\Biometric\Client\Device as ProtocolDevice;
use Promoseven\Biometric\Codec\TemplateCodec;
use Promoseven\Biometric\Exception\DeviceException;
use Promoseven\Biometric\Protocol\Command as Protocol;

/**
 * Calibration tool for the fingerprint-template read.
 *
 * This is how the 22,066-byte fixture behind TemplateCodecTest was captured, and
 * how the layout in TemplateCodec was confirmed against real hardware. Run it
 * against a new firmware before trusting a template count from it.
 *
 * STRICTLY READ-ONLY: no writes, no beep, no capture mode. Safe to run while
 * people are clocking in.
 *
 * SESSION HYGIENE, WHICH USED TO BE THIS COMMAND'S PROBLEM: a bulk read leaves the
 * device mid-transfer, so a second request on the same session returns leftover
 * chunks from the first. An earlier version worked around that by opening a fresh
 * connection per probe, after a version that did not reported five different
 * "framings" all appearing to answer and zero users on a populated device. The
 * library now owns this — DeviceClient::send() marks the session dirty and
 * Session::ensureClean() reopens before the next command — so a single session is
 * correct here. Each top-level probe still gets its own run() so one failure
 * cannot contaminate the next.
 */
class ProbeFingerprintTemplates extends Command
{
    protected $signature = 'zkteco:probe-templates
                            {device : Device id}
                            {--code= : Employee code to probe finger slots for}
                            {--dump= : Write the raw template table to this file}';

    protected $description = 'Probe a terminal for how it exposes fingerprint templates (read-only diagnostic)';

    private Device $device;

    private BiometricDeviceDriver $driver;

    public function handle(BiometricDeviceDriver $driver): int
    {
        Tenancy::spanAllTenants();

        $device = Device::query()->withoutGlobalScopes()->find($this->argument('device'));

        if ($device === null) {
            $this->error('Device not found.');

            return self::FAILURE;
        }

        $this->device = $device;
        $this->driver = $driver;

        $this->info("Probing {$device->name} at {$device->ip_address}:{$device->port} (read-only)");

        $this->probeHandshake();
        $raw = $this->probeBufferedTable();
        $this->probeDecoder($raw);
        $this->probeUserSlots();

        return self::SUCCESS;
    }

    /** Runs one callback on its own session. */
    private function fresh(\Closure $callback): mixed
    {
        return $this->driver->run($this->device, $callback);
    }

    /** What does the device say when asked for the template table? */
    private function probeHandshake(): void
    {
        $this->newLine();
        $this->line('<comment>== Template table handshake ==</comment>');

        try {
            $reply = $this->fresh(fn (ProtocolDevice $d) => $d->client()->probe(
                Protocol::DB_RRQ,
                pack('C', Protocol::FCT_FINGERTMP),
            ));
        } catch (DeviceException $e) {
            $this->line("  <fg=red>{$e->getMessage()}</>");

            return;
        }

        $this->line("  reply command : {$reply['command']} ({$reply['name']})");
        $this->line("  payload bytes : {$reply['length']}");
        $this->line("  payload hex   : {$reply['hex']}");

        if ($reply['command'] === Protocol::PREPARE_DATA && $reply['length'] >= 4) {
            $size = unpack('V', hex2bin(substr($reply['hex'], 0, 8)))[1];
            $this->line("  <info>device will send {$size} bytes of template table</info>");
        }
    }

    /** Reassemble the whole table and look at its actual structure. */
    private function probeBufferedTable(): string
    {
        $this->newLine();
        $this->line('<comment>== Reassembled template table ==</comment>');

        try {
            $raw = $this->fresh(fn (ProtocolDevice $d) => $d->client()->probeBuffered(
                Protocol::DB_RRQ,
                pack('C', Protocol::FCT_FINGERTMP),
            ));
        } catch (DeviceException $e) {
            $this->line("  <fg=red>{$e->getMessage()}</>");

            return '';
        }

        $this->line('  received: '.strlen($raw).' bytes');

        if ($raw === '') {
            $this->line('  <fg=yellow>empty table</>');

            return '';
        }

        if ($dump = $this->option('dump')) {
            file_put_contents($dump, $raw);
            $this->line("  raw bytes written to {$dump}");
        }

        $this->line('  first 96 bytes: '.bin2hex(substr($raw, 0, 96)));

        if (strlen($raw) >= 4) {
            $declared = unpack('V', substr($raw, 0, 4))[1];
            $this->line("  leading uint32 = {$declared}; bytes after it = ".(strlen($raw) - 4));
        }

        $this->walkRecords($raw, 'with 4-byte length prefix', 4);
        $this->walkRecords($raw, 'with NO length prefix', 0);

        return $raw;
    }

    /**
     * Walks the buffer as [2-byte size][2-byte uid][1-byte fid][1-byte valid][blob]
     * under both prefix assumptions and reports what the first records look like.
     * This is what tells us whether the assumed layout matches this firmware.
     */
    private function walkRecords(string $raw, string $label, int $skip): void
    {
        $this->newLine();
        $this->line("  <comment>-- parse {$label} --</comment>");

        $body = substr($raw, $skip);
        $offset = 0;
        $shown = 0;

        while ($offset + 6 <= strlen($body) && $shown < 8) {
            $size = unpack('v', substr($body, $offset, 2))[1];
            $uid = unpack('v', substr($body, $offset + 2, 2))[1];
            $fid = ord($body[$offset + 4]);
            $valid = ord($body[$offset + 5]);

            $this->line(sprintf(
                '    @%-6d size=%-6d uid=%-6d fid=%-4d valid=%-4d %s',
                $offset,
                $size,
                $uid,
                $fid,
                $valid,
                $size < 6 ? '<fg=red>size < header</>' : ($offset + $size > strlen($body) ? '<fg=red>overruns buffer</>' : ''),
            ));

            if ($size < 6 || $offset + $size > strlen($body)) {
                break;
            }

            $offset += $size;
            $shown++;
        }

        // Keep walking silently to see whether the whole table is consistent.
        $records = 0;
        $offset = 0;
        $ok = true;
        while ($offset + 6 <= strlen($body)) {
            $size = unpack('v', substr($body, $offset, 2))[1];
            if ($size < 6 || $offset + $size > strlen($body)) {
                $ok = false;
                break;
            }
            $records++;
            $offset += $size;
        }

        $this->line(sprintf(
            '    => %s after %d record(s), consumed %d of %d bytes',
            $ok ? '<info>consistent</info>' : '<fg=red>inconsistent</>',
            $records,
            $offset,
            strlen($body),
        ));
    }

    /**
     * What the shipping decoder makes of the same bytes.
     *
     * The hand-rolled walk above says whether the layout is self-consistent; this
     * says whether TemplateCodec agrees. An empty result here means "unknown", never
     * "nobody is enrolled" — that distinction is the codec's entire contract.
     */
    private function probeDecoder(string $raw): void
    {
        if ($raw === '') {
            return;
        }

        $this->newLine();
        $this->line('<comment>== TemplateCodec verdict ==</comment>');

        $records = TemplateCodec::decode($raw);

        if ($records === []) {
            $this->line('  <fg=yellow>decoded to UNKNOWN — the codec refused this table.</>');
            $this->line('  <fg=yellow>Counts must not be shown for this firmware until the layout is understood.</>');

            return;
        }

        $slots = TemplateCodec::slotsByUid($records);

        $this->line('  <info>'.count($records).' template(s) across '.count($slots).' uid(s)</info>');

        foreach ($slots as $uid => $fids) {
            $this->line(sprintf('    uid=%-6d slots: %s', $uid, implode(', ', $fids)));
        }
    }

    /**
     * The precise question: for one enrolled user, which finger slots hold a
     * template? USERTEMP_RRQ with (uid, fid) asks per slot, so a positive reply is
     * direct evidence rather than an inference from a table layout. This is the
     * cross-check that confirmed the fixture.
     */
    private function probeUserSlots(): void
    {
        $this->newLine();
        $this->line('<comment>== Per-slot probe (USERTEMP_RRQ + uid/fid) ==</comment>');

        try {
            $users = $this->fresh(fn (ProtocolDevice $d) => $d->users()->all());
        } catch (DeviceException $e) {
            $this->line("  <fg=red>{$e->getMessage()}</>");

            return;
        }

        $this->line('  users on device: '.count($users));

        $code = $this->option('code');
        $target = null;

        foreach ($users as $user) {
            if ($code === null || (string) ($user['user_id'] ?? '') === (string) $code) {
                $target = $user;
                break;
            }
        }

        if ($target === null) {
            $this->line('  <fg=yellow>No matching user to probe.</>');

            return;
        }

        $this->line("  uid={$target['uid']} code={$target['user_id']} name={$target['name']}");

        try {
            // One session for all ten slots: probeTemplateSlot() marks the session
            // dirty after a PREPARE_DATA reply, and the session reopens itself before
            // the next command. That is what used to require a connection per slot.
            $results = $this->fresh(function (ProtocolDevice $d) use ($target) {
                $out = [];

                foreach (range(0, 9) as $fid) {
                    $out[$fid] = $d->client()->probeTemplateSlot((int) $target['uid'], $fid);
                }

                return $out;
            });
        } catch (DeviceException $e) {
            $this->line("  <fg=red>{$e->getMessage()}</>");

            return;
        }

        foreach ($results as $fid => $result) {
            $this->line(sprintf(
                '    fid=%d %s reply=%-5d size=%d',
                $fid,
                $result['exists'] ? '<info>TEMPLATE</info>' : '<fg=gray>empty   </>',
                $result['reply'],
                $result['size'],
            ));
        }
    }
}
