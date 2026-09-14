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

    /*
     * Socket receive buffer to request for device reads, in bytes.
     *
     * MEASURED, and the single biggest lever on bulk-read reliability. The OS
     * default is 64KB; one attendance table on an F18 is 412KB. UDP has no flow
     * control, so anything past the buffer is discarded by the kernel before PHP
     * sees it — surfacing as "Incomplete transfer: got X of Y bytes".
     *
     * Raising it to 4MB took that failure from roughly 1 read in 3 to 25 of 26.
     *
     * Requires ext-sockets; without it the request is skipped and reads fall back
     * to the platform default. Raise this only if a terminal holds a table larger
     * than 4MB (~100,000 records).
     */
    'device_receive_buffer' => (int) env('DEVICE_RECEIVE_BUFFER', 4 * 1024 * 1024),

    /*
     * How many times a stalled windowed-read window is re-requested before the
     * whole transfer is abandoned. See DeviceConfig::$bulkWindowRetries.
     *
     * The receive buffer above stops the KERNEL dropping a burst; it does nothing
     * for a device that goes silent mid-transfer on a lossy or high-latency link.
     * MEASURED on the Camelot F18 over a public IP (2026-09-14): a 1.66MB table
     * stalled ~7 times per read, and with no per-window retry the reader lost the
     * whole transfer on the first stall and never finished. Re-requesting the
     * stalled window makes a stall cost 16KB instead of the table.
     *
     * A LAN device never stalls a window, so this default is free there. Raise it
     * (env DEVICE_WINDOW_RETRIES) for devices reached across the internet.
     */
    'device_window_retries' => (int) env('DEVICE_WINDOW_RETRIES', 4),

    /*
     * Transport for the ZKTeco standalone protocol: 'udp', 'tcp', or 'auto'.
     *
     * Default 'udp' — proven on every device here, including a full Camelot read
     * over the public internet once windowed reads resume across a stall (see
     * device_window_retries). TCP is implemented and is the more robust transport in
     * principle (flow control + retransmission; CONFIRMED reading an F22 whole over
     * TCP), but has NOT yet completed a Camelot read — so it is opt-in, not the
     * default, until it is proven on the problem device.
     *
     * 'tcp' forces TCP everywhere. 'auto' uses TCP for public-IP devices and UDP on
     * the LAN — enable it to try TCP on Camelot/Good Wheels once they can be tested
     * on a clean, unloaded connection.
     */
    'device_transport' => env('DEVICE_TRANSPORT', 'udp'),

    // How long a device may go unheard-from before health reports it offline.
    'offline_after_seconds' => (int) env('DEVICE_OFFLINE_AFTER', 300),

    // Retry policy for queued device commands.
    'command_max_attempts' => (int) env('DEVICE_COMMAND_ATTEMPTS', 3),
    'command_retry_backoff' => [30, 120, 300],

    // Carbon dayOfWeek values that are scheduled work days (0 = Sunday ... 6 =
    // Saturday). Default: Sunday-Thursday, with Friday/Saturday as the weekend.
    'work_days' => [0, 1, 2, 3, 4],
];
