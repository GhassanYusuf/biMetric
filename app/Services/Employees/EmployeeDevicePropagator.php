<?php

namespace App\Services\Employees;

use App\Models\Device;
use App\Models\Employee;
use App\Services\Devices\DeviceIdentityResolver;
use App\Services\Devices\DeviceUserSyncService;
use Illuminate\Support\Collection;

/**
 * Writes one employee onto several terminals.
 *
 * WHY THIS EXISTS: saving an employee used to change the database and nothing else.
 * A corrected name, or a new joiner, sat in this app while every terminal carried
 * the old value — or had never heard of them — until somebody remembered to open
 * the enrollment dialog and push, one device at a time. Nothing on screen said so.
 *
 * IMMEDIATE, NOT QUEUED — a deliberate exception to rule 11, taken by the owner on
 * 2026-09-15 and recorded in docs/PROJECT-STATE.md.
 *
 * Rule 11 says device writes are queued, and the reasons remain true: a queued
 * command gets its own retry, its own audit row and cannot time a request out. What
 * it cannot give is an ANSWER while the operator is standing there, and pushing one
 * person is the case where that matters most — they press Push to find out whether
 * the terminal took it, and "queued" is not that. The existing single-device push
 * behind the enrollment dialog has always worked this way for the same reason.
 *
 * What that costs, stated plainly so it is not rediscovered: this runs inside a web
 * request, so the caller must raise the time limit, a slow or unreachable terminal
 * holds the request for the driver's timeout, and there is no automatic retry. A
 * FAILURE ON ONE DEVICE NEVER STOPS THE REST — each is attempted in its own try,
 * because the alternative is one dead terminal silently cancelling the writes to
 * four healthy ones.
 *
 * IT DOES NOT DECIDE WHICH DEVICES. The caller passes them, because the two callers
 * mean different things: an edit propagates to the terminals the person is ALREADY
 * enrolled on, while a new joiner is pushed to the terminals somebody picked for
 * them. Guessing either would be wrong — pushing a new hire to every terminal in
 * the company is not a default anybody asked for.
 */
class EmployeeDevicePropagator
{
    public function __construct(
        private readonly DeviceUserSyncService $users,
        private readonly DeviceIdentityResolver $identity,
    ) {}

    /**
     * Writes this employee onto each device that can accept one, NOW.
     *
     * Three outcomes per device, kept distinct because they need different responses
     * from the operator (rule 10 applied to an action rather than a reading):
     *
     *   written  the terminal took it, and said so on a read-back
     *   skipped  it cannot take a write at all — disabled, unadopted, or a protocol
     *            with no user-write command. Nothing was attempted.
     *   failed   it should have worked and did not. Unreachable, a slot conflict, a
     *            protocol error. This is the one worth showing in red.
     *
     * Collapsing `skipped` into `failed` would be the familiar lie: a C3 panel that
     * cannot write users has not failed, and telling an operator it did sends them
     * to check a network that is fine.
     *
     * @param  Collection<int, Device>|iterable<Device>  $devices
     * @return array{written:list<array{device_id:int,device_name:string,pin:string,message:string}>, skipped:list<array{device_id:int,device_name:string,reason:string}>, failed:list<array{device_id:int,device_name:string,error:string}>}
     */
    public function push(Employee $employee, iterable $devices): array
    {
        $written = [];
        $skipped = [];
        $failed = [];

        foreach ($devices as $device) {
            /*
             * Asked of the DEVICE, not worked out here. Rule 8 keeps the library's
             * Capability vocabulary to a short list of classes, and the model is on
             * it — so the model answers in a sentence and this stays app code.
             */
            $reason = $device->userWriteRefusal();

            if ($reason !== null) {
                $skipped[] = [
                    'device_id' => $device->id,
                    'device_name' => $device->name,
                    'reason' => $reason,
                ];

                continue;
            }

            try {
                /*
                 * An employee being pushed to a terminal they are not on yet has no
                 * PIN there, and pushEmployee() refuses rather than inventing one.
                 * Claiming allocates the lowest number never used on that device — in
                 * OUR records only. The hardware is still read inside the session
                 * before anything is written, so a number free in our table but taken
                 * on the device still aborts the write.
                 */
                $map = $this->identity->claim($device, $employee);

                $result = $this->users->pushEmployee($device, $employee);

                $written[] = [
                    'device_id' => $device->id,
                    'device_name' => $device->name,
                    'pin' => $map->device_user_id,
                    'message' => $result['message'],
                ];
            } catch (\Throwable $e) {
                /*
                 * Caught PER DEVICE and never rethrown. One unreachable terminal must
                 * not cancel the writes to the others — that turns a partial outage
                 * into a total one, and the operator would have no way to tell which
                 * happened.
                 */
                $failed[] = [
                    'device_id' => $device->id,
                    'device_name' => $device->name,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return ['written' => $written, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * The terminals an employee is currently enrolled on.
     *
     * LIVE mappings only. A closed mapping is a PIN they used to hold on a device —
     * kept so their old punches still resolve to them (see EmployeeDeviceMap) — and
     * pushing to it would write their name over whoever holds that slot now.
     *
     * @return Collection<int, Device>
     */
    public function enrolledOn(Employee $employee): Collection
    {
        return Device::query()
            ->whereIn('id', $employee->deviceMaps()->live()->pluck('device_id'))
            ->orderBy('name')
            ->get();
    }
}
