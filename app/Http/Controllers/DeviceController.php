<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\Zkteco\DeviceSyncService;
use App\Services\Zkteco\ZktecoException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeviceController extends Controller
{
    public function __construct(private readonly DeviceSyncService $sync)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Devices/Index', [
            'devices' => Device::query()
                ->withCount('attendanceLogs')
                ->latest()
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ip_address' => ['required', 'ip'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'protocol' => ['required', 'in:' . Device::PROTOCOL_BINARY . ',' . Device::PROTOCOL_CSL_HTTP],
            'comm_key' => ['nullable', 'string', 'max:255'],
            'web_username' => ['nullable', 'string', 'max:255'],
            'web_password' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        Device::create($data + ['port' => $data['port'] ?? 4370]);

        return back()->with('success', 'Device added.');
    }

    public function update(Request $request, Device $device): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ip_address' => ['required', 'ip'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'protocol' => ['required', 'in:' . Device::PROTOCOL_BINARY . ',' . Device::PROTOCOL_CSL_HTTP],
            'comm_key' => ['nullable', 'string', 'max:255'],
            'web_username' => ['nullable', 'string', 'max:255'],
            'web_password' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        // comm_key/web_password are never sent back to the client once saved,
        // so an empty submission means "leave unchanged", not "clear it".
        foreach (['comm_key', 'web_password'] as $secret) {
            if (($data[$secret] ?? '') === '') {
                unset($data[$secret]);
            }
        }

        $device->update($data);

        return back()->with('success', 'Device updated.');
    }

    public function destroy(Device $device): RedirectResponse
    {
        $device->delete();

        return back()->with('success', 'Device removed.');
    }

    public function testConnection(Device $device): RedirectResponse
    {
        try {
            $result = $this->sync->testConnection($device);

            $message = $result['device_time']
                ? "Connected. Device time: {$result['device_time']}"
                : 'Connected. ' . ($result['note'] ?? '');

            return back()->with('success', $message);
        } catch (ZktecoException $e) {
            return back()->with('error', "Connection failed: {$e->getMessage()}");
        }
    }

    public function syncAttendance(Device $device): RedirectResponse
    {
        try {
            $count = $this->sync->syncAttendance($device);

            return back()->with('success', "Sync complete: {$count} new punch(es) imported.");
        } catch (ZktecoException $e) {
            return back()->with('error', "Sync failed: {$e->getMessage()}");
        }
    }

    public function importEmployees(Device $device): RedirectResponse
    {
        try {
            $result = $this->sync->importEmployees($device);

            $message = "Found {$result['total_on_device']} employee(s) on the device: "
                . "{$result['created']} added, {$result['already_existed']} already existed (left unchanged). "
                . "{$result['backfilled']} existing punch(es) linked to employees. "
                . "Card status updated for {$result['enrollment_updated']} employee(s)";
            $message .= $result['fingerprints_known']
                ? ' (fingerprint counts included).'
                : ' (fingerprint counts unavailable on this device).';

            return back()->with('success', $message);
        } catch (ZktecoException $e) {
            return back()->with('error', "Employee import failed: {$e->getMessage()}");
        }
    }
}
