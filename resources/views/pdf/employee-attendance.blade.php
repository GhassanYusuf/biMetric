{{-- One employee's attendance: who they are, the totals, and the days behind them. --}}
@extends('pdf.layout')

@section('content')
    <table style="margin-bottom: 12px;">
        <tbody>
            <tr>
                <td style="border: none; padding-left: 0;">
                    <div style="font-size: 14px; font-weight: bold; color: #0f172a;">{{ $employee['name'] }}</div>
                    <div class="muted">
                        <span class="mono">{{ $employee['employee_code'] }}</span>
                        @if ($employee['department']) · {{ $employee['department'] }} @endif
                        @if (! $employee['is_active']) · Inactive @endif
                    </div>
                </td>
                <td style="border: none; padding-right: 0;" class="num">
                    <div style="font-size: 14px; font-weight: bold; color: #0f172a;">
                        {{ number_format($summary['total_payroll_hours'], 1) }} h
                    </div>
                    <div class="muted">payroll hours · {{ number_format($summary['total_punches']) }} punches</div>
                </td>
            </tr>
        </tbody>
    </table>

    {{-- The category counts, in the same order and wording as the screen. --}}
    <table style="margin-bottom: 12px;">
        <thead>
            <tr>
                <th>Present</th>
                <th>Late arrivals</th>
                <th>Left late</th>
                <th>Absent</th>
                <th>Excused</th>
                <th>Missed punch out</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $summary['categories']['early'] + $summary['categories']['come_late'] }}</td>
                <td>{{ $summary['categories']['come_late'] }}</td>
                <td>{{ $summary['categories']['leave_late'] }}</td>
                <td>{{ $summary['categories']['absent'] }}</td>
                <td>{{ $summary['categories']['excused'] }}</td>
                <td>{{ $summary['categories']['missed_punch_out'] }}</td>
            </tr>
        </tbody>
    </table>

    <table>
        <thead>
            <tr>
                <th style="width: 16%;">Date</th>
                <th style="width: 20%;">Status</th>
                <th style="width: 14%;">First punch</th>
                <th style="width: 14%;">Last punch</th>
                <th style="width: 12%;" class="num">Hours</th>
                <th style="width: 12%;" class="num">Payroll</th>
                <th style="width: 12%;">Note</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($summary['daily'] as $day)
                <tr>
                    <td>{{ $day['date'] }}</td>
                    <td>
                        {{ ucfirst(str_replace('_', ' ', $day['status'])) }}
                        @if ($day['arrival'] === 'come_late') (late) @endif
                    </td>
                    {{-- A day with one punch has no departure. It prints as
                         "missing", never as a departure equal to the arrival. --}}
                    <td>{{ $day['first'] ?? '—' }}</td>
                    <td>{{ $day['missed_punch_out'] ? 'missing' : ($day['last'] ?? '—') }}</td>
                    <td class="num">{{ $day['hours'] ? number_format($day['hours'], 1) : '—' }}</td>
                    <td class="num">{{ $day['payroll_hours'] ? number_format($day['payroll_hours'], 1) : '—' }}</td>
                    <td class="muted">{{ $day['excused_reason'] ?? '' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">No scheduled work days in this range.</td></tr>
            @endforelse
            <tr class="totals">
                <td colspan="5">Total payroll hours</td>
                <td class="num">{{ number_format($summary['total_payroll_hours'], 1) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <p class="muted" style="margin-top: 10px;">
        Only scheduled work days are listed. Payroll hours are clipped to the
        official shift, so time worked outside it is not counted.
    </p>
@endsection
