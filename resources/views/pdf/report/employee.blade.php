{{--
| One person's attendance report for any period (App\Services\Reports\PersonalReport).
| Rendered alone for a single export, and once per person by the bulk export
| (BuildEmployeeReports), which joins the files — so both are exactly this page.
|
| $p is one PersonalReport entry.
--}}
@extends('pdf.report.layout')

@php
    use App\Support\PdfCharts as C;
    use App\Support\ReportFormat as F;

    $W = 722;
    $e = $p['employee'];
    $S = $p['stats'];
    $P = $p['previous_stats'];
    $shift = $p['shift'];
    $period = $p['period'];
    $short = $period['days'] <= 62;
    $kpis = [
        ['Attendance', F::pct($S['attendance_rate']), F::delta($S['attendance_rate'], $P['attendance_rate'], 'pts', 'up'), $S['present_days'].' of '.$S['expected_days'].' expected days'],
        ['On-time arrivals', F::pct($S['on_time_rate']), F::delta($S['on_time_rate'], $P['on_time_rate'], 'pts', 'up'),
            $S['late_arrivals'] ? $S['late_arrivals'].' late arrival'.($S['late_arrivals'] === 1 ? '' : 's') : ($S['present_days'] ? 'never late' : 'no days present')],
        ['Average arrival', C::clock($S['average_arrival_minutes']), F::delta($S['average_arrival_minutes'], $P['average_arrival_minutes'], 'min', 'down'), 'shift starts '.C::clock($shift['start_minutes'])],
        ['Average departure', C::clock($S['average_departure_minutes']), null, 'shift ends '.C::clock($shift['end_minutes'])],
        ['Missed punch-outs', $S['present_days'] ? (string) $S['missed_punch_outs'] : '—', F::delta($S['missed_punch_outs'], $P['missed_punch_outs'], 'n', 'down'), $S['missed_punch_outs'] ? 'hours not counted on those days' : ($S['present_days'] ? 'every day closed' : 'no days present')],
        ['Payroll hours', $S['work_days'] ? F::num($S['payroll_hours'], 1) : '—', null, 'inside the shift window'],
    ];
    $states = ['never_read' => 'never read', 'unknown' => 'unknown'];
@endphp

@section('content')
    <div class="hero">
        <div class="eyebrow">{{ $tenantName ?? 'Attendance' }} · Employee attendance report</div>
        <h1>{{ $e['name'] }}</h1>
        <div class="sub">
            {{ $e['job_title'] ?: 'No job title on file' }} · {{ $e['department'] ?? 'No department' }}@if (! $e['is_active']) · <b>Inactive</b>@endif
        </div>
        <div class="fine">
            Code {{ $e['employee_code'] }} · {{ $e['hired_date'] ? 'Hired '.F::date($e['hired_date']) : 'No hire date on file' }}
            · {{ F::range($period['from'], $period['to']) }} ({{ $period['days'] }} days), compared with {{ F::range($p['previous']['from'], $p['previous']['to']) }}
            @if ($p['tracking_from'] && $p['tracking_from'] > $period['from']) · tracked from {{ F::date($p['tracking_from']) }} @endif
        </div>
    </div>
    <div class="accent"></div>

    <table class="grid" style="margin-top: 14px;">
        @foreach (array_chunk($kpis, 3) as $row)
            <tr>
                @foreach ($row as [$label, $value, $delta, $note])
                    <td>
                        <div class="tile">
                            <div class="label">{{ $label }}</div>
                            <div class="value">{{ $value }}</div>
                            <div class="note">@if ($delta && $S['present_days'])<span class="{{ $delta['tone'] }}">{{ $delta['text'] }}</span>@else&nbsp;@endif</div>
                            <div class="note">{{ $note }}</div>
                        </div>
                    </td>
                @endforeach
            </tr>
        @endforeach
    </table>

    @if ($p['tracking_from'] === null || $p['tracking_from'] > $period['to'])
        <div class="callout" style="margin-top: 12px;">
            <b>Not tracked in this period.&nbsp;</b>No hire date on or before {{ F::date($period['to']) }} and no punch recorded by then, so there is no day to count. Every figure is blank rather than zero.
        </div>
    @endif

    @if ($p['alerts'])
        <div class="section avoid">
            <h2>Needs attention</h2>
            <table class="grid">
                @foreach (array_chunk($p['alerts'], 3) as $row)
                    <tr>
                        @foreach ($row as $a)
                            <td>
                                <div class="alert">
                                    <span class="lvl {{ $a['tone'] }}">{{ $a['level'] }}</span>
                                    <div style="font-weight: bold; font-size: 9px; margin-top: 1px;">{{ $a['title'] }}</div>
                                    <div class="muted" style="font-size: 8px;">{{ $a['detail'] }}</div>
                                </div>
                            </td>
                        @endforeach
                        @for ($i = count($row); $i < 3; $i++)<td></td>@endfor
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    <div class="section avoid">
        <h2>How the days went</h2>
        <p class="lead">{{ $S['work_days'] }} tracked work days in the period.</p>
        {!! C::splitBar($S['outcomes'], $W, 18) !!}
        @include('pdf.report.outcome-legend', ['counts' => $S['outcomes']])
    </div>

    <div class="section avoid">
        <h2>{{ $short ? 'The period at a glance' : 'Every day of the period' }}</h2>
        @if ($short)
            <p class="lead">Each work day coloured by what happened, with the time of the first punch. Pale days are not work days.</p>
            {!! C::monthCalendar($p['calendar'], $p['first_minutes'], $period['from'], $period['to'], $W) !!}
        @else
            <p class="lead">Weeks across, weekdays down. Pale squares are non-work days; outlined squares are before this person was tracked.</p>
            {!! C::calendar($p['calendar'], $period['from'], $period['to'], $W, 11) !!}
            @include('pdf.report.outcome-legend', ['counts' => $S['outcomes']])
        @endif
    </div>

    {{-- ── Patterns ─────────────────────────────────────────────────────── --}}
    <div class="break"></div>
    <h2>Arrivals and departures</h2>
    <p class="lead">A filled dot at each day's first punch, coloured by the day's outcome; a ring at the last. The shaded band is the shift; the dashed line is the late mark, {{ C::clock($shift['late_after_minutes']) }}. Absent and excused days are a tick on the baseline.</p>
    @if ($p['days'])
        {!! C::dayTimes($p['days'], $shift['start_minutes'], $shift['end_minutes'], $shift['late_after_minutes'], $W, 200) !!}
    @else
        <p class="muted" style="margin-left: 10px;">No tracked work days in this period.</p>
    @endif

    <div class="section avoid">
        <table class="grid">
            <tr>
                <td>
                    <div class="panel">
                        <h3>Attendance by weekday</h3>
                        {!! C::columns(array_map(fn ($w) => ['label' => $w['label'], 'value' => $w['attendance_rate']], $p['weekdays']), 330, 115, C::ACCENT) !!}
                    </div>
                </td>
                <td>
                    <div class="panel">
                        <h3>Late arrivals by weekday</h3>
                        {!! C::columns(array_map(fn ($w) => ['label' => $w['label'], 'value' => $w['late']], $p['weekdays']), 330, 115, C::OUTCOMES['late']['color'], false) !!}
                    </div>
                </td>
            </tr>
            @if ($p['months'])
                <tr>
                    <td colspan="2">
                        <div class="panel">
                            <h3>On-time arrivals by month</h3>
                            {!! C::columns($p['months'], 690, 115, C::OUTCOMES['on_time']['color']) !!}
                        </div>
                    </td>
                </tr>
            @endif
        </table>
    </div>

    <div class="section avoid">
        <table class="grid">
            <tr>
                @foreach ([['Where — punches by terminal', $p['where']['devices'], C::ACCENT], ['How — verification method', $p['where']['methods'], '#0f172a']] as [$heading, $rows, $color])
                    <td>
                        <div class="panel">
                            <h3>{{ $heading }}</h3>
                            @forelse ($rows as $row)
                                <table style="width: 100%; border-collapse: collapse; margin-bottom: 4px;"><tr>
                                    <td style="padding: 0; width: 42%;">{{ $row['label'] }}</td>
                                    <td style="padding: 0 6px;"><div class="track"><div class="fill" style="width: {{ max(round($row['share'] * 100, 1), 1) }}%; background: {{ $color }};"></div></div></td>
                                    <td class="num" style="padding: 0; width: 18%;"><b>{{ number_format($row['count']) }}</b></td>
                                </tr></table>
                            @empty
                                <p class="muted">No punches in the period.</p>
                            @endforelse
                        </div>
                    </td>
                @endforeach
            </tr>
        </table>
    </div>

    @if ($p['enrollment'])
        <div class="section avoid">
            <h2>Enrollment on terminals</h2>
            <p class="lead">As each terminal last reported it. "Never read" and "unknown" are not zero.</p>
            <table class="data">
                <thead><tr><th>Terminal</th><th>Fingerprints</th><th>Card</th><th>Last read</th></tr></thead>
                <tbody>
                    @foreach ($p['enrollment'] as $en)
                        <tr>
                            <td>{{ $en['device'] ?? 'Unknown terminal' }}</td>
                            <td>@if ($en['state'] === 'read'){{ $en['fingerprints'] }}@else<span class="muted">{{ $states[$en['state']] }}</span>@endif</td>
                            <td>{{ $en['state'] === 'never_read' ? '' : ($en['has_card'] ? 'Yes' : 'No') }}</td>
                            <td class="muted">{{ $en['synced_at'] ?? 'never' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ── Every punch ──────────────────────────────────────────────────── --}}
    <div class="break"></div>
    <h2>Punches, day by day</h2>
    <p class="lead">Every tracked work day of the period, in order, with every punch recorded that day — its time, terminal and how it was verified.</p>
    <table class="data">
        <thead>
            <tr>
                <th style="width: 13%;">Date</th>
                <th style="width: 17%;">Outcome</th>
                <th class="num" style="width: 9%;">In</th>
                <th class="num" style="width: 9%;">Out</th>
                <th class="num" style="width: 7%;">Hours</th>
                <th class="num" style="width: 7%;">Payroll</th>
                <th style="width: 38%; padding-left: 12px;">Punches</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($p['days'] as $d)
                <tr>
                    <td><b>{{ F::date($d['date'], 'D') }}</b> {{ F::date($d['date'], 'j M Y') }}</td>
                    <td>
                        <span class="dot" style="background: {{ C::OUTCOMES[$d['outcome']]['color'] }};"></span>{{ C::OUTCOMES[$d['outcome']]['label'] }}
                        @if ($d['outcome'] === 'late')<span class="muted"> · {{ round($d['first_minutes'] - $shift['start_minutes']) }} min</span>@endif
                    </td>
                    <td class="num">{{ $d['first_minutes'] !== null ? C::clock($d['first_minutes']) : '' }}</td>
                    {{-- One punch is no departure: "missing", never a copy of the arrival. --}}
                    <td class="num">{{ $d['outcome'] === 'missed_punch_out' ? 'missing' : ($d['last_minutes'] !== null ? C::clock($d['last_minutes']) : '') }}</td>
                    <td class="num">{{ $d['hours'] !== null ? number_format($d['hours'], 1) : '' }}</td>
                    <td class="num">{{ $d['payroll_hours'] ? number_format($d['payroll_hours'], 1) : '' }}</td>
                    <td style="padding-left: 12px;">
                        @forelse ($d['punches'] as $punch)
                            <div style="white-space: nowrap;"><span class="mono" style="color: #0f172a;">{{ C::clock($punch['minutes']) }}</span>
                                <span class="muted" style="font-size: 7.5px;">&nbsp;{{ $punch['device'] }}@if ($punch['method']) · {{ $punch['method'] }}@endif</span></div>
                        @empty
                            <span class="muted">{{ $d['excused_reason'] ?? 'No punches' }}</span>
                        @endforelse
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">No tracked work days in this period.</td></tr>
            @endforelse
            @if ($p['days'])
                <tr>
                    <td colspan="2" style="border-top: 1.5px solid #94a3b8; font-weight: bold;">{{ $S['present_days'] }} days present of {{ $S['expected_days'] }} expected</td>
                    <td colspan="2" class="num" style="border-top: 1.5px solid #94a3b8;">{{ number_format($p['where']['total']) }} punches</td>
                    <td class="num" style="border-top: 1.5px solid #94a3b8; font-weight: bold;">{{ number_format(array_sum(array_map(fn ($d) => $d['hours'] ?? 0, $p['days'])), 1) }}</td>
                    <td class="num" style="border-top: 1.5px solid #94a3b8; font-weight: bold;">{{ F::num($S['payroll_hours'], 1) }}</td>
                    <td style="border-top: 1.5px solid #94a3b8;"></td>
                </tr>
            @endif
        </tbody>
    </table>
@endsection
