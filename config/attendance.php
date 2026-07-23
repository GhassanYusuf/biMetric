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

    // Carbon dayOfWeek values that are scheduled work days (0 = Sunday ... 6 =
    // Saturday). Default: Sunday-Thursday, with Friday/Saturday as the weekend.
    'work_days' => [0, 1, 2, 3, 4],
];
