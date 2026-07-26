<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\Zkteco\ZktecoDevice;
use App\Services\Zkteco\ZktecoException;
use App\Services\Zkteco\ZktecoProtocol;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Calibration tool for the fingerprint-template read.
 *
 * IMPORTANT: every probe runs on its OWN connection. A bulk read leaves the
 * device mid-transfer, so issuing a second request on the same session returns
 * leftover data chunks from the first — which made an earlier version of this
 * command report nonsense (five different "framings" all appearing to answer,
 * and zero users on a populated device).
 *
 * Strictly read-only: no writes, no beep, no capture mode. Safe to run while
 * people are clocking in.
 */
class ProbeFingerprintTemplates extends Command
{
    protected $signature = 'zkteco:probe-templates
                            {device : Device id}
                            {--code= : Employee code to probe finger slots for}
                            {--dump= : Write the raw template table to this file}';

    protected $description = 'Probe a terminal for how it exposes fingerprint templates (read-only diagnostic)';

    private Device $device;

    public function handle(): int
    {
        Tenancy::spanAllTenants();

        $device = Device::query()->withoutGlobalScopes()->find($this->argument('device'));

        if ($device === null) {
            $this->error('Device not found.');

            return self::FAILURE;
        }

        $this->device = $device;
        $this->info("Probing {$device->name} at {$device->ip_address}:{$device->port} (read-only)");

        $this->probePrepareHandshake();
        $this->probeBufferedTable();
        $this->probeUserSlots();

        return self::SUCCESS;
    }

    /** Runs one callback on a dedicated connection. */
    private function fresh(\Closure $callback): mixed
    {
        return ZktecoDevice::fromModel($this->device)->run($callback);
    }

    /** What does the device say when asked for the template table? */
    private function probePrepareHandshake(): void
    {
        $this->newLine();
        $this->line('<comment>== Template table handshake ==</comment>');

        try {
            $reply = $this->fresh(fn (ZktecoDevice $d) => $d->probeCommand(
                ZktecoProtocol::CMD_DB_RRQ,
                pack('C', ZktecoProtocol::FCT_FINGERTMP),
            ));

            $this->line("  reply command : {$reply['command']} (1500 = PREPARE_DATA, 1501 = DATA)");
            $this->line("  payload bytes : {$reply['length']}");
            $this->line("  payload hex   : {$reply['hex']}");

            if ($reply['command'] === ZktecoProtocol::CMD_PREPARE_DATA && $reply['length'] >= 4) {
                $size = unpack('V', hex2bin(substr($reply['hex'], 0, 8)))[1];
                $this->line("  <info>device will send {$size} bytes of template table</info>");
            }
        } catch (ZktecoException $e) {
            $this->line("  <fg=red>{$e->getMessage()}</>");
        }
    }

    /** Reassemble the whole table and look at its actual structure. */
    private function probeBufferedTable(): void
    {
        $this->newLine();
        $this->line('<comment>== Reassembled template table ==</comment>');

        try {
            $raw = $this->fresh(fn (ZktecoDevice $d) => $d->probeBuffered(
                ZktecoProtocol::CMD_DB_RRQ,
                pack('C', ZktecoProtocol::FCT_FINGERTMP),
            ));
        } catch (ZktecoException $e) {
            $this->line("  <fg=red>{$e->getMessage()}</>");

            return;
        }

        $this->line('  received: ' . strlen($raw) . ' bytes');

        if ($raw === '') {
            $this->line('  <fg=yellow>empty table</>');

            return;
        }

        if ($dump = $this->option('dump')) {
            file_put_contents($dump, $raw);
            $this->line("  raw bytes written to {$dump}");
        }

        $this->line('  first 96 bytes: ' . bin2hex(substr($raw, 0, 96)));

        if (strlen($raw) >= 4) {
            $declared = unpack('V', substr($raw, 0, 4))[1];
            $this->line("  leading uint32 = {$declared}; bytes after it = " . (strlen($raw) - 4));
        }

        $this->walkRecords($raw, 'with 4-byte length prefix', 4);
        $this->walkRecords($raw, 'with NO length prefix', 0);
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
        $slots = [];

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

            $slots[$uid][] = $fid;
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
     * The precise question: for one enrolled user, which finger slots hold a
     * template? CMD_USERTEMP_RRQ with (uid, fid) asks per slot, so a positive
     * reply is direct evidence rather than an inference from a table layout.
     */
    private function probeUserSlots(): void
    {
        $this->newLine();
        $this->line('<comment>== Per-slot probe (CMD_USERTEMP_RRQ + uid/fid) ==</comment>');

        try {
            $users = $this->fresh(fn (ZktecoDevice $d) => $d->getUsers());
        } catch (ZktecoException $e) {
            $this->line("  <fg=red>{$e->getMessage()}</>");

            return;
        }

        $this->line('  users on device: ' . count($users));

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

        foreach (range(0, 9) as $fid) {
            try {
                // Each slot on its own connection: a template reply is itself a
                // bulk transfer, so reusing the session would poison the next.
                $reply = $this->fresh(fn (ZktecoDevice $d) => $d->probeCommand(
                    ZktecoProtocol::CMD_USERTEMP_RRQ,
                    pack('vC', $target['uid'], $fid),
                ));

                $this->line(sprintf(
                    '    fid=%d reply=%-5d bytes=%-6d %s',
                    $fid,
                    $reply['command'],
                    $reply['length'],
                    substr($reply['hex'], 0, 32),
                ));
            } catch (ZktecoException $e) {
                $this->line(sprintf('    fid=%d <fg=red>%s</>', $fid, $e->getMessage()));
            }
        }
    }
}
