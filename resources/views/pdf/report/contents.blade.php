{{--
| The first page(s) of the bulk employee-reports file (BuildEmployeeReports): what the
| file covers, and one line per person with their headline figures and the page their
| report starts on. People left out are named, with why.
--}}
@extends('pdf.report.layout')

@php
    use App\Support\ReportFormat as F;

    $included = array_values(array_filter($rows, fn ($r) => $r['file'] !== null));
    $failed = array_values(array_filter($rows, fn ($r) => $r['file'] === null));
@endphp

@section('content')
    <div class="hero">
        <div class="eyebrow">{{ $tenantName ?? 'Attendance' }} · Employee attendance reports</div>
        <h1>{{ $params['scope'] ?? 'All companies and departments' }}</h1>
        <div class="sub">{{ F::range($params['from'], $params['to']) }} · {{ count($included) }} {{ count($included) === 1 ? 'person' : 'people' }}</div>
        <div class="fine">One report per person, each starting on a new page, in department and name order. Every report's figures are the same as that person's own Employee Report for these dates.</div>
    </div>
    <div class="accent"></div>

    <div class="section">
        <h2>Contents</h2>
        <p class="lead">Attendance and on-time rates are each person's own. Below 80% attendance is marked.</p>
        <table class="data">
            <thead>
                <tr>
                    <th style="width: 31%;">Employee</th>
                    <th style="width: 11%;">Code</th>
                    <th class="num" style="width: 9%;">Present</th>
                    <th class="num" style="width: 11%;">Attendance</th>
                    <th class="num" style="width: 9%;">On time</th>
                    <th class="num" style="width: 6%;">Late</th>
                    <th class="num" style="width: 8%;">Absent</th>
                    <th class="num" style="width: 7%;">No out</th>
                    <th class="num" style="width: 8%;">Page</th>
                </tr>
            </thead>
            <tbody>
                @foreach (collect($included)->groupBy('department') as $department => $people)
                    <tr class="group"><td colspan="9">{{ $department }} <span class="muted" style="font-weight: normal;">· {{ $people->count() }}</span></td></tr>
                    @foreach ($people as $r)
                        @php $s = $r['stats']; @endphp
                        <tr>
                            <td>{{ $r['name'] }}</td>
                            <td class="mono">{{ $r['employee_code'] }}</td>
                            <td class="num">{{ $s['present_days'] }}<span class="muted">/{{ $s['expected_days'] }}</span></td>
                            @if ($s['expected_days'] > 0 && $s['present_days'] === 0)
                                {{-- Seen on no terminal: usually not syncing or not enrolled (see the company report). --}}
                                <td class="num muted" colspan="5">no punch in this period</td>
                            @else
                                <td class="num" style="{{ $s['attendance_rate'] !== null && $s['attendance_rate'] < 0.8 ? 'color: #be123c; font-weight: bold;' : '' }}">{{ F::pct($s['attendance_rate'], 0) }}</td>
                                <td class="num">{{ F::pct($s['on_time_rate'], 0) }}</td>
                                <td class="num">{{ $s['late_arrivals'] ?: '' }}</td>
                                <td class="num">{{ $r['absent'] ?: '' }}</td>
                                <td class="num">{{ $s['missed_punch_outs'] ?: '' }}</td>
                            @endif
                            <td class="num"><b>{{ $r['page'] }}</b></td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($failed)
        <div class="section avoid">
            <div class="callout">
                <b>{{ count($failed) }} {{ count($failed) === 1 ? 'report' : 'reports' }} could not be drawn and {{ count($failed) === 1 ? 'is' : 'are' }} not in this file:&nbsp;</b>
                @foreach ($failed as $r){{ $r['name'] }} ({{ $r['employee_code'] }}){{ $loop->last ? '.' : ', ' }}@endforeach
                Export them one at a time from their Employee Report page.
            </div>
        </div>
    @endif

    @if (! empty($params['not_tracked']))
        <div class="section avoid">
            <h2>Not included ({{ count($params['not_tracked']) }})</h2>
            <p class="lead">Active, but with no hire date and no punch by {{ F::date($params['to']) }}, so there is no day to report on. A hire date brings them in.</p>
            <p class="muted" style="margin-left: 10px; font-size: 8px;">
                @foreach ($params['not_tracked'] as $n){{ $n['name'] }} <span class="mono">{{ $n['employee_code'] }}</span>{{ $loop->last ? '' : ' · ' }}@endforeach
            </p>
        </div>
    @endif
@endsection
