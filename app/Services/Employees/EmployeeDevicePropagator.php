<?php

namespace App\Services\Employees;

use App\Models\Device;
use App\Models\Employee;
use App\Services\Devices\DeviceIdentityResolver;
use App\Services\Devices\DeviceUserSyncService;
use App\Support\DeviceRefusalAdvice;
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
     * $toEmployeeCode (added 2026-09-23, the operator's explicit choice in the push
     * prompt): also make the terminal's user ID equal the employee code. On a terminal
     * they are already on, the record is renumbered in place first — card and fingers
     * kept, old punches still theirs (DeviceUserSyncService::renumberEmployee). On one
     * they are new to, the code is claimed as their PIN when it is free there. If the
     * renumber fails, NOTHING is written to that terminal and it is reported as failed:
     * a half-done push (new name, old ID) is harder to reason about than none.
     *
     * @param  Collection<int, Device>|iterable<Device>  $devices
     * @return array{written:list<array{device_id:int,device_name:string,pin:string,message:string,restored:list<string>}>, skipped:list<array{device_id:int,device_name:string,reason:string}>, failed:list<array{device_id:int,device_name:string,error:string}>}
     */
    public function push(Employee $employee, iterable $devices, bool $toEmployeeCode = false, bool $applyRole = false): array
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

            /*
             * A panel that cannot take names but CAN move a user to a new ID (a C3 with
             * `user.renumber`, 2026-09-24) goes through when an ID change was asked
             * for; below, it gets the ID move and nothing else. Still refused when it is
             * disabled or unadopted.
             */
            if ($reason !== null && $toEmployeeCode && $device->is_active && $device->isWritable() && $device->canRenumberUsers()) {
                $reason = null;
            }

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
                $code = (string) $employee->employee_code;
                $wantCode = $toEmployeeCode && preg_match('/^\d{1,9}$/', $code) === 1;
                $notes = [];
                // Set when the terminal ends up under an ID other than the code asked for.
                $idProblem = null;

                if ($this->identity->activeMap($device, $employee) === null && $wantCode) {
                    try {
                        $this->identity->assertPinFreeFor($device, $employee, $code);
                        $this->identity->claim($device, $employee, $code);
                    } catch (\RuntimeException $e) {
                        $notes[] = "user ID {$code} is taken there, so the next free one was used";
                    }
                }

                $map = $this->identity->claim($device, $employee);

                if ($wantCode && $map->device_user_id !== $code) {
                    /*
                     * A terminal that cannot renumber (the F18 refuses it, 2026-09-23)
                     * still gets the name, under the ID it already has — the operator
                     * asked for both, and refusing the half that works helps nobody.
                     * Stale browser tabs still send this flag, which is how it came up.
                     */
                    /*
                     * Each model moves a user ID by ITS OWN method (CLAUDE.md rule 21,
                     * docs/device-models.md): in place where the model can (C3 panels),
                     * otherwise by re-adding the person with their card and fingers put
                     * back (the F18, which refuses an in-place move). The re-add refuses
                     * BEFORE deleting anything if this model cannot put back what they have.
                     */
                    if ($device->canRenumberUsers()) {
                        $notes[] = $this->users->renumberEmployee($device, $employee, $code)['message'];
                        $map = $this->identity->activeMap($device, $employee);
                    } elseif ($device->canMoveUsersByReadding()) {
                        $moved = $this->users->moveByReadding($device, $employee, $code);
                        $notes[] = $moved['message'];
                        $movedRestored = $moved['restored'];
                        $map = $this->identity->activeMap($device, $employee);
                    } else {
                        /*
                         * NOT a success (owner, 2026-10-11, casebook DC-014): every save
                         * must leave the employee code on every terminal. The name is still
                         * written, but the terminal is reported as FAILED so the result
                         * row is red and offers Retry, instead of a green row with a note
                         * nobody reads.
                         */
                        $idProblem = "the user ID is still {$map->device_user_id}, not {$code}: {$device->name} cannot change a user ID from this app yet.";
                    }
                }

                /*
                 * A panel whose names cannot be written from here (a C3: user.write is
                 * not_implemented) can still have had the ID moved above. Report that,
                 * rather than failing the terminal on the name write it cannot take.
                 */
                $restored = $movedRestored ?? [];
                unset($movedRestored);
                if ($device->canWriteUsers()) {
                    $pushed = $this->users->pushEmployee($device, $employee);
                    $notes[] = $pushed['message'];
                    $restored = array_merge($restored, $pushed['restored'] ?? []);
                } elseif ($notes === []) {
                    // Nothing to move and names cannot be written here: a skip, not a failure.
                    $skipped[] = [
                        'device_id' => $device->id,
                        'device_name' => $device->name,
                        'reason' => "the user ID there already matches, and this panel's names are managed in ZKAccess",
                    ];

                    continue;
                } else {
                    $notes[] = 'Name not written: this panel\'s names are managed in ZKAccess.';
                }

                /*
                 * The Device role, when the save CHANGED it (owner, 2026-10-20: "make it
                 * really work"). Only then: real roles differ per terminal (Ghassan is an
                 * administrator on Camelot only), so a save that left the field alone must
                 * never level them. Through setPrivilege() — the per-terminal button's
                 * path — which reads the role back and audits it (rule 10).
                 */
                $roleProblem = null;
                if ($applyRole) {
                    try {
                        if (($said = $this->applyRole($device, $employee)) !== '') {
                            $notes[] = $said;
                        }
                    } catch (\Throwable $e) {
                        $roleProblem = 'the role was not changed: '.$e->getMessage();
                    }
                }

                if ($idProblem !== null || $roleProblem !== null) {
                    $failed[] = [
                        'device_id' => $device->id,
                        'device_name' => $device->name,
                        'error' => 'Name written, but '.implode(' Also, ', array_filter([$idProblem, $roleProblem])),
                    ];

                    continue;
                }

                $written[] = [
                    'device_id' => $device->id,
                    'device_name' => $device->name,
                    'pin' => $map->device_user_id,
                    'message' => implode(' ', $notes),
                    // Card / fingerprints written back from the system's copy (2026-10-11).
                    'restored' => $restored,
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
                    // With what to do about it, when the reply has a known remedy
                    // (4994/4995: restart the terminal, DC-034).
                    'error' => DeviceRefusalAdvice::message($e),
                    // What the operator can press to resolve it, when there is such a
                    // thing (2026-10-11): free a number held by an unlinked record, or
                    // test fingerprint writing on a model that never did it.
                    'action' => method_exists($e, 'action') ? $e->action() : null,
                ];
            }
        }

        return ['written' => $written, 'skipped' => $skipped, 'failed' => $failed];
    }

    /** One sentence for the result row; throws when the terminal refused or read back wrong. */
    private function applyRole(Device $device, Employee $employee): string
    {
        $admin = $employee->terminalAdmin();
        $role = fn (bool $a) => $a ? 'an administrator' : 'a normal user';

        if ($admin === null) {
            return $employee->privilege === 'enroller'
                ? "Device role not changed on {$device->name}: Enroller has no equivalent on this terminal."
                : '';
        }
        if (! $device->canSetPrivilegeOnSave()) {
            return "Device role not changed on {$device->name}: changing a role from a save has not been proven on this terminal yet — use Make administrator in the employee's details.";
        }

        $held = $this->identity->activeMap($device, $employee)?->device_privilege;
        if ($held !== null && ($held > 0) === $admin) {
            return "Already {$role($admin)} on {$device->name}.";
        }

        return $this->users->setPrivilege($device, $employee, $admin)['message'];
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
