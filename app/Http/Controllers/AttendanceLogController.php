<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Device;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'device_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $logs = AttendanceLog::query()
            ->with(['device:id,name', 'employee:id,name,employee_code'])
            ->when($filters['device_id'] ?? null, fn ($q, $deviceId) => $q->where('device_id', $deviceId))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('punched_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('punched_at', '<=', $to))
            ->latest('punched_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Attendance/Index', [
            'logs' => $logs,
            'devices' => Device::query()->select('id', 'name')->get(),
            'filters' => $filters,
        ]);
    }
}
