<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Devices\DeviceCommandBus;
use App\Services\Devices\DeviceDriverManager;
use App\Support\ApiResponse;
use App\Support\Timezone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Promoseven\Biometric\Capability\Capability;

/**
 * Time zones and device clocks (owner, 2026-10-05).
 *
 * A terminal has no time zone database — no zones, no daylight saving. What "make
 * the devices follow this time zone" means is writing that zone's current wall-clock
 * time to the device clock, so every FUTURE punch is stamped in it. The existing
 * `sync_time` command already does exactly that (reads the clock, sets it, reads it
 * back); this controller only decides which devices get it and reports the ones that
 * cannot, so nothing new writes to hardware on its own.
 *
 * The plan is deliberately free of device I/O. Opening four terminals on page load
 * would be slow and would look like a hang; the operator asks for a clock check when
 * they want one.
 */
class DeviceTimezoneController extends Controller
{
    public function __construct(
        private readonly DeviceDriverManager $drivers,
        private readonly DeviceCommandBus $bus,
    ) {}

    /**
     * What each terminal would be set to, and whether it can be set at all.
     *
     * No network: this is config and capability only, so the settings screen is safe
     * to render with two terminals offline.
     */
    public function plan(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.view'), 403);

        $system = Timezone::system();

        $devices = Device::query()
            ->withoutGlobalScopes()
            ->whereIn('protocol', [Device::PROTOCOL_BINARY, Device::PROTOCOL_C3])
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Device $device) use ($system) {
                $caps = $device->capabilitySet();
                $supported = $caps->supports(Capability::SET_TIME);
                $own = Timezone::deviceOverrides($device);

                return [
                    'id' => $device->id,
                    'name' => $device->name,
                    'ip_address' => $device->ip_address,
                    'protocol' => $device->protocol,
                    'location' => $device->location,
                    // What this terminal uses, and whether it got there by its own
                    // override or by inheriting the company default.
                    'timezone' => $own ? Timezone::forDevice($device) : $system,
                    'overrides' => $own,
                    'system_timezone' => $system,
                    'supports_set_time' => $supported,
                    // Null when supported; otherwise the operator-facing reason, so the
                    // UI can explain a refusal instead of showing an empty cell.
                    'refusal' => $supported ? null : sprintf(
                        'Cannot set the clock on this terminal: %s',
                        Capability::describe($caps->level(Capability::SET_TIME))
                    ),
                    // The exact time that would be written, so it can be shown before
                    // anyone agrees to write it.
                    'target_time' => Timezone::nowFor($device)->format('Y-m-d H:i:s P'),
                ];
            })
            ->values()
            ->all();

        return ApiResponse::success([
            'system_timezone' => $system,
            'server_timezone' => (string) config('app.timezone'),
            /*
             | Both zones are returned because they are genuinely different and the
             | difference is a trap: this host runs app.timezone = UTC, so a schedule
             | evaluated on the server would run "0 3 * * *" at 06:00 Bahrain time. It
             | is evaluated on the COMPANY zone instead. The UI says which is which,
             | rather than letting "03:00" quietly mean something nobody typed.
             */
            'schedule_zone_note' => sprintf(
                'Cron jobs run on your company time zone (%s). This server itself runs on %s.',
                $system,
                config('app.timezone')
            ),
            'devices' => $devices,
        ], 'Time zone plan retrieved.');
    }

    /**
     * Reads each terminal's clock and reports the drift against the time we would set.
     *
     * READ-ONLY, and opt-in for that reason: it opens every terminal in the selection,
     * which over the WAN path (Good Wheels, Camelot) is slow enough to time out a web
     * request if it were on page load.
     */
    public function check(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.view'), 403);

        $ids = $request->validate([
            'device_ids' => ['nullable', 'array'],
            'device_ids.*' => ['integer'],
        ])['device_ids'] ?? null;

        $query = Device::query()->withoutGlobalScopes()
            ->whereIn('protocol', [Device::PROTOCOL_BINARY, Device::PROTOCOL_C3])
            ->where('is_active', true);

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        $rows = [];

        foreach ($query->orderBy('name')->get() as $device) {
            $expected = Timezone::nowFor($device);

            try {
                $reported = $this->drivers->run($device, fn ($d) => $d->time());
            } catch (\Throwable $e) {
                $rows[] = [
                    'id' => $device->id,
                    'name' => $device->name,
                    'reachable' => false,
                    'error' => $e->getMessage(),
                    'timezone' => Timezone::forDevice($device),
                    'expected' => $expected->format('Y-m-d H:i:s P'),
                ];

                continue;
            }

            $rows[] = [
                'id' => $device->id,
                'name' => $device->name,
                'reachable' => $reported !== null,
                // Unreadable clocks are common on locked panels; not an error, but the
                // operator should see it rather than assume the check passed.
                'reported' => $reported?->format('Y-m-d H:i:s'),
                'expected' => $expected->format('Y-m-d H:i:s P'),
                'drift_seconds' => $reported === null
                    ? null
                    : $reported->getTimestamp() - $expected->getTimestamp(),
                'timezone' => Timezone::forDevice($device),
                'error' => null,
            ];
        }

        return ApiResponse::success(['devices' => $rows], 'Clock check complete.');
    }

    /**
     * Queues `sync_time` for the selected terminals.
     *
     * QUEUED, not applied inline: the bus already dedupes, retries, records the
     * outcome against the device and lets one slow terminal fail without holding up
     * the rest, and setting a clock is a real hardware write that should not ride on
     * an HTTP request's lifetime.
     */
    public function apply(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $data = $request->validate([
            'device_ids' => ['nullable', 'array'],
            'device_ids.*' => ['integer'],
        ]);

        $ids = $data['device_ids'] ?? null;

        $query = Device::query()->withoutGlobalScopes()
            ->whereIn('protocol', [Device::PROTOCOL_BINARY, Device::PROTOCOL_C3])
            ->where('is_active', true);

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        $queued = [];
        $refused = [];

        foreach ($query->orderBy('name')->get() as $device) {
            $caps = $device->capabilitySet();

            if (! $caps->supports(Capability::SET_TIME)) {
                $refused[] = [
                    'id' => $device->id,
                    'name' => $device->name,
                    'reason' => Capability::describe($caps->level(Capability::SET_TIME)),
                ];

                continue;
            }

            \App\Support\Tenancy::set($device->tenant_id);

            try {
                $this->bus->dispatch($device, \App\Models\DeviceCommand::SYNC_TIME);
                $queued[] = ['id' => $device->id, 'name' => $device->name];
            } finally {
                \App\Support\Tenancy::clear();
            }
        }

        return ApiResponse::success([
            'queued' => $queued,
            'refused' => $refused,
        ], sprintf(
            'Queued %d clock sync(s)%s.',
            count($queued),
            $refused === [] ? '' : sprintf(', %d cannot have their clock set', count($refused))
        ));
    }
}
