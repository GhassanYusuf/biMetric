{{-- Payroll hours and day counts for every active employee in the range. --}}
@extends('pdf.layout')

@section('content')
    <table>
        <thead>
            <tr>
                <th style="width: 26%;">Employee</th>
                <th style="width: 11%;">Code</th>
                <th style="width: 17%;">Department</th>
                <th style="width: 9%;" class="num">Present</th>
                <th style="width: 9%;" class="num">Absent</th>
                <th style="width: 8%;" class="num">Late</th>
                <th style="width: 9%;" class="num">Excused</th>
                <th style="width: 11%;" class="num">Payroll h</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td class="mono">{{ $row['employee_code'] }}</td>
                    <td>{{ $row['department'] ?? '—' }}</td>
                    <td class="num">{{ $row['present_days'] }}</td>
                    <td class="num">{{ $row['absent_days'] }}</td>
                    <td class="num">{{ $row['late_days'] }}</td>
                    <td class="num">{{ $row['excused_days'] }}</td>
                    <td class="num">{{ number_format($row['total_payroll_hours'], 1) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">No active employees match this filter.</td></tr>
            @endforelse
            <tr class="totals">
                <td colspan="7">{{ number_format($totals['employees']) }} employees</td>
                <td class="num">{{ number_format($totals['payroll_hours'], 1) }}</td>
            </tr>
        </tbody>
    </table>

    <p class="muted" style="margin-top: 10px;">
        Active employees only. Absences excused by a holiday or a waiver are
        credited the full shift, so their payroll hours are not reduced.
    </p>
@endsection
