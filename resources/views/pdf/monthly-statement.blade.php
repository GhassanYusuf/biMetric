{{--
| The Monthly Statement Report: ZKTeco's column layout (draft/OTR-SEP.xlsx), one
| employee per page. Every figure comes from MonthlyStatement, which reads
| AttendanceSummaryService — see that class for what each column means and where
| it deliberately differs from the ZKTeco software's own rules.
--}}
@extends('pdf.layout')

@php($d = fn (int $m) => \App\Services\Reports\MonthlyStatement::duration($m))

@section('content')
    <style>
        .stmt { page-break-after: always; }
        .stmt:last-of-type { page-break-after: auto; }
        .who { width: 100%; margin-bottom: 5px; font-size: 9px; }
        .who td { border: none; padding: 0 8px 0 0; }
        .who .label { color: #475569; }
        .who .value { font-weight: bold; }
        .grid th, .grid td { font-size: 7px; padding: 2.6px 2px; text-align: center; letter-spacing: 0; }
        .grid th { text-transform: none; }
        .grid td.left, .grid th.left { text-align: left; }
        .grid .zero { color: #64748b; }
        .grid tr.off td { background: #f8fafc; }
        .flag { color: #b45309; font-weight: bold; }
        .legend { margin-top: 8px; font-size: 7.5px; color: #475569; line-height: 1.45; }
        .sign { width: 100%; margin-top: 26px; }
        .sign td { border: none; width: 50%; padding: 0; font-size: 9px; }
        .sign .line { border-top: 1px solid #64748b; width: 70%; margin-top: 24px; }
    </style>

    @forelse ($s['employees'] as $block)
        <div class="stmt">
            <table class="who">
                <tr>
                    <td class="label" style="width: 9%;">User ID</td>
                    <td class="value mono" style="width: 18%;">{{ $block['employee']['employee_code'] }}</td>
                    <td class="label" style="width: 10%;">Full Name</td>
                    <td class="value" style="width: 35%;">{{ $block['employee']['name'] }}</td>
                    <td class="label" style="width: 11%;">Department</td>
                    <td class="value">{{ $block['employee']['department'] ?? '—' }}</td>
                </tr>
            </table>

            <table class="grid">
                <thead>
                    <tr>
                        <th class="left" style="width: 9%;">Date</th>
                        <th class="left" style="width: 5%;">Week</th>
                        <th class="left" style="width: 10%;">Timetable</th>
                        <th>In</th><th>Out</th><th>In</th><th>Out</th>
                        <th>Break</th><th>Late</th><th>Early</th><th>Work</th><th>Absent</th>
                        <th>OT1</th><th>OT2</th><th>OT3</th><th>Exception</th><th>Holiday</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($block['rows'] as $row)
                        <tr class="{{ $row['timetable'] === 'Off' ? 'off' : '' }}">
                            <td class="left">{{ $row['date'] }}</td>
                            <td class="left">{{ $row['week'] }}</td>
                            <td class="left">{{ $row['timetable'] }}</td>
                            <td>{{ $row['in'] }}</td>
                            {{-- One punch is not "no work": it is a missing punch, and says so. --}}
                            <td>@if ($row['missed_punch_out'])<span class="flag">None</span>@else{{ $row['out'] }}@endif</td>
                            <td></td><td></td>
                            @foreach (\App\Services\Reports\MonthlyStatement::DURATIONS as $col)
                                <td class="{{ $row[$col] === 0 ? 'zero' : '' }}">{{ $d($row[$col]) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr class="totals">
                        <td class="left" colspan="7">Total</td>
                        @foreach (\App\Services\Reports\MonthlyStatement::DURATIONS as $col)
                            <td>{{ $d($block['totals'][$col]) }}</td>
                        @endforeach
                    </tr>
                </tbody>
            </table>

            <div class="legend">
                Work is first In to last Out, no break deducted. Late counts from {{ $s['shift']['start'] }} once past
                {{ $s['shift']['start'] }} + {{ $s['shift']['grace'] }} min; Early counts back from {{ $s['shift']['end'] }} when the
                last punch is more than {{ $s['shift']['early_leave_grace'] }} min before it. Absent, Exception (waiver) and Holiday are
                one shift, {{ $d($s['shift']['length_minutes']) }}. OT1 is time after {{ $s['shift']['end'] }} on a work day once the
                last punch is {{ $s['shift']['leave_late_after'] }} min or more past it; OT2 is all time on an Off day.
                "None" under Out means one punch only: the day is not counted as worked.
            </div>

            @if ($loop->last)
                <table class="sign">
                    <tr>
                        <td>Checked By<div class="line"></div></td>
                        <td>Approved By<div class="line"></div></td>
                    </tr>
                </table>
            @endif
        </div>
    @empty
        <p class="muted">No active employees match this filter.</p>
    @endforelse
@endsection
