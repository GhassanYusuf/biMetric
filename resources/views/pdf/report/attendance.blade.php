{{--
| The company attendance report (App\Services\Reports\AttendanceReport).
| Management first: headline figures, how the period went, where and who; the full
| roster is the appendix. Every rate is over the people who punched at least once —
| the page says so, and prints the all-in rate beside it.
--}}
@extends('pdf.report.layout')

@php
    use App\Support\PdfCharts as C;
    use App\Support\ReportFormat as F;

    $S = $r['stats'];
    $P = $r['previous_stats'];
    $A = $r['all_in_stats'];
    $W = 722;
    $noShow = count($r['attention']['no_show']);
    $kpis = [
        ['Attendance', F::pct($S['attendance_rate']), F::delta($S['attendance_rate'], $P['attendance_rate'], 'pts', 'up'), 'of expected days attended'],
        ['On-time arrivals', F::pct($S['on_time_rate']), F::delta($S['on_time_rate'], $P['on_time_rate'], 'pts', 'up'), 'of days present'],
        ['Average arrival', C::clock($S['average_arrival_minutes']), F::delta($S['average_arrival_minutes'], $P['average_arrival_minutes'], 'min', 'down'), 'shift starts '.C::clock($r['shift']['start_minutes'])],
        ['People punching', $r['punching'].' <small>of '.$r['headcount'].'</small>', null, $noShow ? $noShow.' with no punch in the period' : 'everybody punched'],
        ['Late arrivals', F::num($S['late_arrivals']), F::delta($S['late_arrivals'], $P['late_arrivals'], 'n', 'down'), 'after '.C::clock($r['shift']['late_after_minutes'])],
        ['Unexplained absences', F::num($S['outcomes']['absent']), F::delta($S['outcomes']['absent'], $P['outcomes']['absent'], 'n', 'down'), 'person-days, no holiday or waiver'],
        ['Missed punch-outs', F::num($S['missed_punch_outs']), F::delta($S['missed_punch_outs'], $P['missed_punch_outs'], 'n', 'down'), 'hours not counted on those days'],
        ['Payroll hours', F::num($S['payroll_hours']), null, 'inside the shift window'],
    ];
    $outcomeTotal = array_sum($S['outcomes']);
@endphp

@section('content')
    {{-- ── Cover ─────────────────────────────────────────────────────────── --}}
    <div class="hero">
        <div class="eyebrow">{{ $tenantName ?? 'Attendance' }} · Attendance report</div>
        <h1>{{ $r['scope'] ?? 'All companies and departments' }}</h1>
        <div class="sub">{{ F::range($r['from'], $r['to']) }} · {{ $r['days'] }} days · {{ count($r['timeline']['buckets']) }} {{ $r['timeline']['grain'] === 'week' ? 'weeks' : 'work days' }} · {{ $r['headcount'] }} people</div>
        <div class="fine">
            Compared with the {{ $r['days'] }} days before ({{ F::range($r['previous']['from'], $r['previous']['to']) }}).
            Shift {{ C::clock($r['shift']['start_minutes']) }} – {{ C::clock($r['shift']['end_minutes']) }}, late after {{ $r['shift']['grace_minutes'] }} minutes' grace.
            Active employees only.
        </div>
    </div>
    <div class="accent"></div>

    <table class="grid" style="margin-top: 14px;">
        @foreach (array_chunk($kpis, 4) as $row)
            <tr>
                @foreach ($row as [$label, $value, $delta, $note])
                    <td>
                        <div class="tile">
                            <div class="label">{{ $label }}</div>
                            <div class="value">{!! $value !!}</div>
                            <div class="note">@if ($delta)<span class="{{ $delta['tone'] }}">{{ $delta['text'] }}</span>@else&nbsp;@endif</div>
                            <div class="note">{{ $note }}</div>
                        </div>
                    </td>
                @endforeach
            </tr>
        @endforeach
    </table>

    @if ($noShow)
        <div class="callout" style="margin-top: 12px;">
            <b>{{ $noShow }} {{ $noShow === 1 ? 'person has' : 'people have' }} no punch on any terminal in this period.&nbsp;</b>That is usually a terminal that is not syncing or a person who is not enrolled, rather than {{ $S['work_days'] && $r['punching'] ? round($A['expected_days'] / max($r['headcount'], 1)) : 'every' }} days of absence,
            so the rates in this report cover the {{ $r['punching'] }} people who did punch. Counting everybody, attendance is <b>&nbsp;{{ F::pct($A['attendance_rate']) }}</b>. They are named on the "Needs attention" page.
        </div>
    @endif

    <div class="section avoid">
        <h2>How the days went</h2>
        <p class="lead">Every expected person-day in the period, by what happened. {{ number_format($outcomeTotal) }} person-days.</p>
        {!! C::splitBar($S['outcomes'], $W, 18) !!}
        @include('pdf.report.outcome-legend', ['counts' => $S['outcomes']])
    </div>

    <div class="section avoid">
        <h2>{{ $r['timeline']['grain'] === 'week' ? 'Week by week' : 'Day by day' }}</h2>
        <p class="lead">Share of the people tracked on each {{ $r['timeline']['grain'] === 'week' ? 'week' : 'work day' }}, by outcome. Columns are shares, not headcounts.</p>
        @if ($r['timeline']['buckets'])
            {!! C::stackedColumns($r['timeline']['buckets'], $W, 175) !!}
        @else
            <p class="muted">No work days with anybody tracked in this period.</p>
        @endif
    </div>

    {{-- ── Where and when ─────────────────────────────────────────────────── --}}
    <div class="break"></div>
    <h2>Departments</h2>
    <p class="lead">Best attendance first. Rates cover the people in each department who punched; "Punching" shows how many that is.</p>
    <table class="data">
        <thead>
            <tr>
                <th style="width: 27%;">Department</th>
                <th class="num" style="width: 9%;">Punching</th>
                <th style="width: 22%;">Attendance</th>
                <th class="num" style="width: 9%;">On time</th>
                <th class="num" style="width: 8%;">Late</th>
                <th class="num" style="width: 8%;">Absent</th>
                <th class="num" style="width: 8%;">No out</th>
                <th class="num" style="width: 9%;">Payroll h</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($r['departments'] as $d)
                @php $rate = $d['stats']['attendance_rate']; @endphp
                <tr>
                    <td>{{ $d['name'] }}</td>
                    <td class="num">{{ $d['punching'] }} <span class="muted">/ {{ $d['headcount'] }}</span></td>
                    <td>
                        @if ($rate === null)
                            <span class="muted">no punches</span>
                        @else
                            <table style="width: 100%; border-collapse: collapse;"><tr>
                                <td style="padding: 0; border: none; width: 70%;"><div class="track"><div class="fill" style="width: {{ max(round($rate * 100, 1), 1) }}%; background: {{ C::ACCENT }};"></div></div></td>
                                <td style="padding: 0 0 0 6px; border: none;" class="num"><b>{{ F::pct($rate) }}</b></td>
                            </tr></table>
                        @endif
                    </td>
                    <td class="num">{{ F::pct($d['stats']['on_time_rate'], 0) }}</td>
                    <td class="num">{{ $d['stats']['late_arrivals'] }}</td>
                    <td class="num">{{ $d['stats']['outcomes']['absent'] }}</td>
                    <td class="num">{{ $d['stats']['missed_punch_outs'] }}</td>
                    <td class="num">{{ F::num($d['stats']['payroll_hours']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="section avoid">
        <h2>When people arrive</h2>
        <p class="lead">Each day's first punch, in 10-minute bins. {{ number_format(count($r['arrivals'])) }} arrivals; orange bins are late.</p>
        {!! C::arrivalHistogram($r['arrivals'], $r['shift']['start_minutes'], $r['shift']['late_after_minutes'], $W, 160) !!}
    </div>

    <div class="section avoid">
        <h2>By day of the week</h2>
        <p class="lead">Attendance and on-time arrival for each work day of the week, across the period.</p>
        <table class="grid">
            <tr>
                <td>
                    <div class="panel">
                        <h3>Attendance</h3>
                        {!! C::columns(array_map(fn ($w) => ['label' => $w['label'], 'value' => $w['attendance_rate']], $r['weekdays']), 330, 110, C::ACCENT) !!}
                    </div>
                </td>
                <td>
                    <div class="panel">
                        <h3>On-time arrivals</h3>
                        {!! C::columns(array_map(fn ($w) => ['label' => $w['label'], 'value' => $w['on_time_rate']], $r['weekdays']), 330, 110, C::OUTCOMES['on_time']['color']) !!}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- ── Who ───────────────────────────────────────────────────────────── --}}
    <div class="break"></div>
    <h2>Needs attention</h2>
    <p class="lead">The ten people with the most of each, among those who punched. A list only names people for whom it happened.</p>

    @php
        $lists = [
            ['Most late arrivals', $r['attention']['late'], fn ($p) => $p['stats']['late_arrivals'], 'late'],
            ['Most unexplained absences', $r['attention']['absent'], fn ($p) => $p['stats']['outcomes']['absent'], 'days'],
            ['Most missed punch-outs', $r['attention']['missed'], fn ($p) => $p['stats']['missed_punch_outs'], 'days'],
        ];
    @endphp
    <table class="grid">
        <tr>
            @foreach ($lists as [$heading, $people, $value, $unit])
                <td>
                    <div class="panel" style="min-height: 190px;">
                        <h3>{{ $heading }}</h3>
                        @forelse ($people as $i => $p)
                            <table style="width: 100%; border-collapse: collapse; margin-bottom: 3px;"><tr>
                                <td class="rank" style="padding: 0; vertical-align: top;">{{ $i + 1 }}</td>
                                <td style="padding: 0 4px 0 0; vertical-align: top;">
                                    <div style="font-size: 8.5px;">{{ $p['name'] }}</div>
                                    <div class="muted" style="font-size: 7px;">{{ $p['department'] ?? 'Unassigned' }}</div>
                                </td>
                                <td class="num" style="padding: 0; vertical-align: top; white-space: nowrap;"><b>{{ $value($p) }}</b> <span class="muted" style="font-size: 7px;">{{ $unit }}</span></td>
                            </tr></table>
                        @empty
                            <p class="muted">Nobody — none in this period.</p>
                        @endforelse
                    </div>
                </td>
            @endforeach
        </tr>
    </table>

    <div class="section avoid">
        <h2>Perfect attendance</h2>
        <p class="lead">Present on every expected day, never late, every day closed with a punch-out.</p>
        @if ($r['attention']['perfect'])
            <div class="panel">
                <table style="width: 100%; border-collapse: collapse; table-layout: fixed;">
                    @foreach (array_chunk($r['attention']['perfect'], 3) as $row)
                        <tr>
                            @foreach ($row as $p)
                                <td style="padding: 2px 6px 2px 0;"><span class="dot" style="background: {{ C::OUTCOMES['on_time']['color'] }};"></span>{{ $p['name'] }} <span class="muted" style="font-size: 7px;">· {{ $p['department'] ?? 'Unassigned' }}</span></td>
                            @endforeach
                            @for ($i = count($row); $i < 3; $i++)<td></td>@endfor
                        </tr>
                    @endforeach
                </table>
            </div>
        @else
            <p class="muted" style="margin-left: 10px;">Nobody met all three this period.</p>
        @endif
    </div>

    @if ($noShow)
        <div class="section">
            <h2>No punch in this period ({{ $noShow }})</h2>
            <p class="lead">Expected at work and seen on no terminal. Check whether each still works here, and whether their terminal is syncing.</p>
            <table class="data">
                <thead><tr><th style="width: 36%;">Name</th><th style="width: 16%;">Code</th><th>Department</th><th class="num">Expected days</th></tr></thead>
                <tbody>
                    @foreach ($r['attention']['no_show'] as $p)
                        <tr><td>{{ $p['name'] }}</td><td class="mono">{{ $p['employee_code'] }}</td><td>{{ $p['department'] ?? 'Unassigned' }}</td><td class="num">{{ $p['stats']['expected_days'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($r['never_tracked'])
        <div class="section avoid">
            <p class="muted" style="margin-left: 10px;">
                <b>{{ count($r['never_tracked']) }} active {{ count($r['never_tracked']) === 1 ? 'employee is' : 'employees are' }} not in this report at all:&nbsp;</b>
                no hire date on file and no punch ever recorded, so there is no day from which to count them. Adding a hire date brings them in.
            </p>
        </div>
    @endif

    {{-- ── Appendix ──────────────────────────────────────────────────────── --}}
    <div class="break"></div>
    <h2>Everyone, by department</h2>
    <p class="lead">{{ $r['headcount'] }} people tracked in the period. Attendance and on-time rates are each person's own.</p>
    <table class="data">
        <thead>
            <tr>
                <th style="width: 29%;">Employee</th>
                <th style="width: 10%;">Code</th>
                <th class="num" style="width: 8%;">Present</th>
                <th class="num" style="width: 10%;">Attendance</th>
                <th class="num" style="width: 8%;">On time</th>
                <th class="num" style="width: 6%;">Late</th>
                <th class="num" style="width: 7%;">Absent</th>
                <th class="num" style="width: 7%;">No out</th>
                <th class="num" style="width: 9%;">Avg in</th>
                <th class="num" style="width: 6%;">Hours</th>
            </tr>
        </thead>
        <tbody>
            @foreach (collect($r['people'])->sortBy([['department', 'asc'], ['name', 'asc']])->groupBy(fn ($p) => $p['department'] ?? 'Unassigned') as $department => $people)
                <tr class="group"><td colspan="10">{{ $department }} <span class="muted" style="font-weight: normal;">· {{ $people->count() }}</span></td></tr>
                @foreach ($people as $p)
                    @php $s = $p['stats']; @endphp
                    <tr>
                        <td>{{ $p['name'] }}</td>
                        <td class="mono">{{ $p['employee_code'] }}</td>
                        <td class="num">{{ $s['present_days'] }}<span class="muted">/{{ $s['expected_days'] }}</span></td>
                        @if ($s['expected_days'] > 0 && $s['present_days'] === 0)
                            {{-- A no-show: counted nowhere above, so not a red 0% here either. --}}
                            <td class="num muted" colspan="7">no punch in this period</td>
                        @else
                            <td class="num" style="{{ $s['attendance_rate'] !== null && $s['attendance_rate'] < 0.8 ? 'color: #be123c; font-weight: bold;' : '' }}">{{ F::pct($s['attendance_rate'], 0) }}</td>
                            <td class="num">{{ F::pct($s['on_time_rate'], 0) }}</td>
                            <td class="num">{{ $s['late_arrivals'] ?: '' }}</td>
                            <td class="num">{{ $s['outcomes']['absent'] ?: '' }}</td>
                            <td class="num">{{ $s['missed_punch_outs'] ?: '' }}</td>
                            <td class="num">{{ C::clock($s['average_arrival_minutes']) }}</td>
                            <td class="num">{{ F::num($s['payroll_hours']) }}</td>
                        @endif
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>

    <div class="section avoid">
        <h2>How to read this report</h2>
        <table class="definitions" style="margin: 6px 0 0 10px;">
            <tr><td class="term">Attendance</td><td>Days present ÷ days expected. A work day is expected unless a holiday or a waiver excuses it; days before a person's hire date (or, without one, their first punch) do not count.</td></tr>
            <tr><td class="term">On time</td><td>Days whose first punch was by {{ C::clock($r['shift']['late_after_minutes']) }} — the shift start plus {{ $r['shift']['grace_minutes'] }} minutes' grace — ÷ days present.</td></tr>
            <tr><td class="term">Left early</td><td>A closed day whose last punch was before the shift end, {{ C::clock($r['shift']['end_minutes']) }}.</td></tr>
            <tr><td class="term">No punch-out</td><td>Only one punch that day. The day counts as present; its hours are not counted until corrected.</td></tr>
            <tr><td class="term">Payroll hours</td><td>Time between first and last punch, clipped to the shift window. Excused days are credited a full shift.</td></tr>
            <tr><td class="term">Previous period</td><td>The same number of days immediately before, over the people who punched in it.</td></tr>
        </table>
    </div>
@endsection
