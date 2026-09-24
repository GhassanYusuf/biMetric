<?php

namespace App\Console\Commands;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use App\Models\EmployeeDeviceMap;
use App\Services\Employees\FingerprintVault;
use Illuminate\Console\Command;
use Promoseven\Biometric\Exception\UnsupportedCommandException;

/**
 * Records a digest of every fingerprint template the terminals hold, and keeps the
 * system's encrypted COPY of each template (FingerprintVault, 2026-09-24).
 *
 * READ-ONLY on the terminals. It runs the template-table read the enrollment sync
 * already performs; what it keeps is a SHA-1 per blob and, since 2026-09-24, the blob.
 *
 * WHY: a vendor tool that enrols a finger once and pushes it to the other terminals
 * leaves byte-identical templates behind. A matching digest is therefore the device's
 * own record that two employee records are one human — evidence a name cannot give and
 * a card can only approximate. Confirmed 2026-09-15: PIN 261 slot 3 returned the same
 * 1,054 bytes from the F18 at 192.168.7.62 and the F22 at 192.168.7.63.
 *
 * THE BYTES WERE ONCE DELIBERATELY NOT STORED. On 2026-09-24 the owner decided the
 * system keeps a copy, so a Personnel ID change or a new terminal does not depend on
 * the one terminal holding the enrollment (docs/PROJECT-STATE.md has the reasoning).
 * They are stored encrypted, only by FingerprintVault, and never logged or returned
 * over HTTP. The digest logic below is unchanged.
 *
 * READ THE RESULT THE RIGHT WAY ROUND: matching digests mean one enrolment copied.
 * DIFFERENT digests mean nothing at all — the same finger presented twice produces a
 * different template, and there is no matcher here.
 */
class DeviceReadTemplates extends Command
{
    protected $signature = 'device:read-templates {device? : Device id or name; omit for every active device}';

    protected $description = 'Record template digests and store the encrypted fingerprint copies (read-only on the terminals)';

    public function handle(BiometricDeviceDriver $driver, FingerprintVault $vault): int
    {
        $devices = $this->resolve();

        if ($devices->isEmpty()) {
            $this->error('No matching device.');

            return self::FAILURE;
        }

        foreach ($devices as $device) {
            try {
                $records = $driver->readTemplates($device);
            } catch (UnsupportedCommandException $e) {
                // Declared, not silently plausible (rule 9). The stored digests are
                // left alone: "we cannot read this" must not erase a previous read.
                $this->line(sprintf('  %-14s skipped — %s', $device->name, $e->getMessage()));

                continue;
            } catch (\Throwable $e) {
                // A device that will not answer is evidence about nothing.
                $this->line(sprintf('  %-14s <fg=red>failed</> — %s', $device->name, $e->getMessage()));

                continue;
            }

            // slot:sha1 per person, because one person's thumb and index finger are
            // different enrolments and must never be compared to one another.
            $bySlotDigest = [];
            foreach ($records as $r) {
                if ($r['user_id'] !== null && $r['user_id'] !== '') {
                    $bySlotDigest[(string) $r['user_id']][] = $r['slot'].':'.$r['sha1'];
                }
            }

            // Which slots are filled, from the same read. Without this the roster
            // shows an amber "?" — "not read" — for people whose templates we are
            // holding a digest of, which is worse than unhelpful: it reads as though
            // the terminal never answered.
            $slotsByPin = [];
            foreach ($records as $r) {
                if ($r['user_id'] !== null && $r['user_id'] !== ''
                    && ! in_array($r['slot'], $slotsByPin[(string) $r['user_id']] ?? [], true)) {
                    $slotsByPin[(string) $r['user_id']][] = $r['slot'];
                }
            }

            $written = 0;
            foreach (EmployeeDeviceMap::query()->where('device_id', $device->id)->live()->get() as $map) {
                $digests = $bySlotDigest[$map->device_user_id] ?? [];
                sort($digests);

                // The table decoded, so absence is a real "none enrolled" — an empty
                // list, not null. Those two must stay distinct (rule 10).
                $slots = $slotsByPin[$map->device_user_id] ?? [];
                sort($slots);

                $map->update([
                    'template_digests' => $digests,
                    'fingerprint_slots' => $slots,
                    'templates_read_at' => now(),
                    'synced_at' => now(),
                ]);
                $written++;
            }

            $copies = $vault->capture($device, $records);

            $this->line(sprintf(
                '  %-14s %3d template(s) across %d person(s); %d mapping(s) updated; copies: %d new, %d refreshed, %d dropped',
                $device->name,
                count($records),
                count($bySlotDigest),
                $written,
                $copies['stored'],
                $copies['refreshed'],
                $copies['dropped'],
            ));
        }

        $this->newLine();
        $this->line('Digests recorded. Duplicate detection will now rank a template match above a card.');

        return self::SUCCESS;
    }

    private function resolve()
    {
        $arg = $this->argument('device');

        if ($arg === null) {
            return Device::withoutGlobalScopes()->where('is_active', true)->orderBy('id')->get();
        }

        return Device::withoutGlobalScopes()
            ->where('id', is_numeric($arg) ? (int) $arg : 0)
            ->orWhere('name', $arg)
            ->get();
    }
}
