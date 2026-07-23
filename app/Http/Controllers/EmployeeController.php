<?php

namespace App\Http\Controllers;

use App\Models\AttendanceWaiver;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Holiday;
use App\Services\Zkteco\DeviceSyncService;
use App\Services\Zkteco\ZktecoException;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function __construct(private readonly DeviceSyncService $sync)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Employees/Index', [
            'employees' => Employee::query()
                ->withCount('attendanceLogs')
                ->orderBy('name')
                ->get(),
            'binaryDevices' => Device::query()
                ->where('protocol', Device::PROTOCOL_BINARY)
                ->where('is_active', true)
                ->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'employee_code' => ['required', 'string', 'max:255', 'unique:employees,employee_code'],
            'email' => ['nullable', 'email', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'max:4096'],
        ]);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('employees', 'public');
        }
        unset($data['photo']);

        Employee::create($data);

        return back()->with('success', 'Employee added.');
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'employee_code' => ['required', 'string', 'max:255', 'unique:employees,employee_code,' . $employee->id],
            'email' => ['nullable', 'email', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'photo' => ['nullable', 'image', 'max:4096'],
        ]);

        if ($request->hasFile('photo')) {
            if ($employee->photo_path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($employee->photo_path);
            }
            $data['photo_path'] = $request->file('photo')->store('employees', 'public');
        }
        unset($data['photo']);

        $employee->update($data);

        return back()->with('success', 'Employee updated.');
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $employee->delete();

        return back()->with('success', 'Employee removed.');
    }

    public function pushToDevice(Employee $employee, Device $device): RedirectResponse
    {
        try {
            $this->sync->pushEmployeeToDevice($device, $employee);

            return back()->with('success', "{$employee->name} enrolled on {$device->name}.");
        } catch (ZktecoException $e) {
            return back()->with('error', "Failed to push to device: {$e->getMessage()}");
        }
    }

    public function removeFromDevice(Employee $employee, Device $device): RedirectResponse
    {
        try {
            $this->sync->removeEmployeeFromDevice($device, $employee);

            return back()->with('success', "{$employee->name} removed from {$device->name}.");
        } catch (ZktecoException $e) {
            return back()->with('error', "Failed to remove from device: {$e->getMessage()}");
        }
    }

    /**
     * Per-day arrival/departure/absence buckets (for a donut chart) plus the
     * underlying daily rows (so the UI can drill into exactly which days
     * make up a given donut segment) and a payroll-hours total, evaluated
     * against the office's local timezone/shift hours rather than raw UTC -
     * "was this person late" only means something in local wall-clock time.
     * Attendance is defined by the first and last punch of the day only -
     * punches in between (breaks, repeat taps) don't change how long
     * someone was actually present, so they're ignored.
     *
     * Only scheduled work days (config('attendance.work_days'), default
     * Sun-Thu) are considered at all - Fri/Sat never count as absent and
     * are left out of the response entirely. A work day with no punches
     * counts as "absent" unless it's a company holiday or has an
     * employee-specific waiver, in which case it's "excused" and credited
     * the full shift length so it doesn't reduce the payroll total.
     */
    public function attendanceSummary(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $tz = config('attendance.timezone');

        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'], $tz)->endOfDay()
            : now($tz)->endOfDay();
        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'], $tz)->startOfDay()
            : $to->copy()->subDays(29)->startOfDay();

        $logs = $employee->attendanceLogs()
            ->whereBetween('punched_at', [$from->copy()->setTimezone('UTC'), $to->copy()->setTimezone('UTC')])
            ->orderBy('punched_at')
            ->get(['punched_at']);

        // Logs are already ordered by punched_at, so each day's punches arrive
        // pre-sorted - first/last are just the array's ends.
        $punchesByDay = [];
        foreach ($logs as $log) {
            $local = $log->punched_at->copy()->setTimezone($tz);
            $punchesByDay[$local->format('Y-m-d')][] = $local;
        }

        $byDay = [];
        foreach ($punchesByDay as $day => $times) {
            $byDay[$day] = [
                'first' => $times[0],
                'last' => end($times),
                'count' => count($times),
            ];
        }

        $holidays = Holiday::query()
            ->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->get()
            ->keyBy(fn (Holiday $h) => $h->date->format('Y-m-d'));

        $waivers = AttendanceWaiver::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->get()
            ->keyBy(fn (AttendanceWaiver $w) => $w->date->format('Y-m-d'));

        $shiftStart = config('attendance.shift_start');
        $shiftEnd = config('attendance.shift_end');
        $grace = config('attendance.grace_minutes');
        $workDays = config('attendance.work_days');
        $shiftLengthHours = round(Carbon::parse($shiftStart)->diffInMinutes(Carbon::parse($shiftEnd)) / 60, 1);

        $categories = [
            'early' => 0, 'come_late' => 0, 'leave_on_time' => 0, 'leave_late' => 0,
            'absent' => 0, 'excused' => 0, 'missed_punch_out' => 0,
        ];
        $daily = [];
        $totalPayrollHours = 0;

        foreach (CarbonPeriod::create($from, $to) as $date) {
            if (!in_array($date->dayOfWeek, $workDays, true)) {
                continue; // weekend - not a scheduled work day, never absent
            }

            $key = $date->format('Y-m-d');
            $info = $byDay[$key] ?? null;
            $holiday = $holidays->get($key);
            $waiver = $waivers->get($key);

            $arrival = null;
            $departure = null;
            $hours = 0;
            $payrollHours = 0;

            if ($info) {
                $status = 'present';
                $expectedStart = Carbon::parse("{$key} {$shiftStart}", $tz)->addMinutes($grace);
                $expectedEnd = Carbon::parse("{$key} {$shiftEnd}", $tz)->addMinutes($grace);

                $arrival = $info['first']->lte($expectedStart) ? 'early' : 'come_late';
                $categories[$arrival]++;

                if ($info['count'] > 1) {
                    $departure = $info['last']->gte($expectedEnd) ? 'leave_late' : 'leave_on_time';
                    $categories[$departure]++;
                    $hours = round($info['first']->diffInMinutes($info['last']) / 60, 1);

                    // Payroll only counts time worked inside the official window -
                    // arriving early or leaving late past it doesn't add extra hours.
                    $windowStart = Carbon::parse("{$key} {$shiftStart}", $tz);
                    $windowEnd = Carbon::parse("{$key} {$shiftEnd}", $tz);
                    $clippedStart = $info['first']->greaterThan($windowStart) ? $info['first'] : $windowStart;
                    $clippedEnd = $info['last']->lessThan($windowEnd) ? $info['last'] : $windowEnd;
                    $payrollHours = $clippedEnd->greaterThan($clippedStart)
                        ? round($clippedStart->diffInMinutes($clippedEnd) / 60, 1)
                        : 0;
                } else {
                    // Punched in but never punched out - can't tell when they
                    // left, so no departure/payroll hours can be credited.
                    $categories['missed_punch_out']++;
                }
            } elseif ($holiday || $waiver) {
                $status = 'excused';
                $categories['excused']++;
                // Waived/holiday days are credited the full shift so they don't
                // reduce the payroll total, per how public holidays are paid.
                $payrollHours = $shiftLengthHours;
            } else {
                $status = 'absent';
                $categories['absent']++;
            }

            $totalPayrollHours += $payrollHours;

            $daily[] = [
                'date' => $key,
                'status' => $status,
                'excused_reason' => $holiday?->name ?? $waiver?->reason ?? ($waiver ? 'Waived' : null),
                'excused_source' => $holiday ? 'holiday' : ($waiver ? 'waiver' : null),
                'hours' => $hours,
                'payroll_hours' => $payrollHours,
                'first' => $info ? $info['first']->format('g:i A') : null,
                'last' => $info ? $info['last']->format('g:i A') : null,
                'arrival' => $arrival,
                'departure' => $departure,
                'missed_punch_out' => $info !== null && $info['count'] === 1,
            ];
        }

        return response()->json([
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_code' => $employee->employee_code,
                'email' => $employee->email,
                'department' => $employee->department,
                'photo_url' => $employee->photo_url,
                'is_active' => $employee->is_active,
            ],
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'daily' => $daily,
            'categories' => $categories,
            'total_punches' => $logs->count(),
            'total_payroll_hours' => round($totalPayrollHours, 1),
        ]);
    }

    /** Toggles a waiver (excuses an absence, e.g. approved leave) for one employee on one date. */
    public function waiveDay(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $waiver = AttendanceWaiver::query()
            ->where('employee_id', $employee->id)
            ->where('date', $data['date'])
            ->first();

        if ($waiver) {
            $waiver->delete();

            return response()->json(['waived' => false]);
        }

        AttendanceWaiver::create([
            'employee_id' => $employee->id,
            'date' => $data['date'],
            'reason' => $data['reason'] ?? null,
        ]);

        return response()->json(['waived' => true]);
    }

    public function enrollFingerprint(Request $request, Employee $employee, Device $device): RedirectResponse
    {
        $data = $request->validate([
            'finger_index' => ['required', 'integer', 'min:0', 'max:9'],
        ]);

        // The device waits (up to ~30s inside the service call) for someone to
        // physically place a finger - make sure PHP doesn't kill the request first.
        set_time_limit(60);

        try {
            $result = $this->sync->enrollFingerprint($device, $employee, (int) $data['finger_index']);

            if ($result['success']) {
                return back()->with('success', "Fingerprint captured for {$employee->name} on {$device->name}.");
            }

            return back()->with('error', "Device reported a capture error (code {$result['error_code']}) - ask them to try again.");
        } catch (ZktecoException $e) {
            return back()->with('error', "Fingerprint enrollment failed: {$e->getMessage()}");
        }
    }

    public function enrollCard(Employee $employee, Device $device): RedirectResponse
    {
        // The device waits (up to ~20s inside the service call) for someone
        // to physically tap a card - make sure PHP doesn't kill the request first.
        set_time_limit(60);

        try {
            $result = $this->sync->enrollCard($device, $employee);

            return back()->with('success', "Card enrolled for {$employee->name} on {$device->name} (card #{$result['card_number']}).");
        } catch (ZktecoException $e) {
            return back()->with('error', "Card enrollment failed: {$e->getMessage()}");
        }
    }
}
