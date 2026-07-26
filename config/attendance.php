<?php

return [
    // The office's real local timezone - attendance_logs stores UTC, but "was
    // this person early/late" only makes sense against local wall-clock time.
    'timezone' => env('ATTENDANCE_TIMEZONE', '+03:00'),

    // Official work hours (local time, 24h "H:i") - used both to judge
    // early/late arrivals & departures, and as the payroll clipping window
    // (time worked outside this window isn't counted toward payroll hours).
    'shift_start' => env('ATTENDANCE_SHIFT_START', '09:00'),
    'shift_end' => env('ATTENDANCE_SHIFT_END', '18:00'),

    // Minutes of tolerance before an arrival/departure counts as late.
    'grace_minutes' => (int) env('ATTENDANCE_GRACE_MINUTES', 15),

    // --- Device communication -------------------------------------------------

    // Socket timeout for one request/reply with a terminal. Bulk transfers poll
    // faster than this internally; this is the handshake budget.
    'device_timeout' => (float) env('DEVICE_TIMEOUT', 8.0),

    // Wall-clock zone a terminal stamps its records with, when the device row does
    // not override it. Terminals stamp LOCAL time, not UTC — see DeviceConfig.
    'device_timezone' => env('DEVICE_TIMEZONE', env('ATTENDANCE_TIMEZONE', '+03:00')),

    // How long a device may go unheard-from before health reports it offline.
    'offline_after_seconds' => (int) env('DEVICE_OFFLINE_AFTER', 300),

    // Retry policy for queued device commands.
    'command_max_attempts' => (int) env('DEVICE_COMMAND_ATTEMPTS', 3),
    'command_retry_backoff' => [30, 120, 300],

    // Carbon dayOfWeek values that are scheduled work days (0 = Sunday ... 6 =
    // Saturday). Default: Sunday-Thursday, with Friday/Saturday as the weekend.
    'work_days' => [0, 1, 2, 3, 4],
];
