{{-- The employee list as filtered on the Employees screen (EmployeeRoster). --}}
@extends('pdf.layout')

@section('content')
    <style>
        /* Readability pass, 2026-11-15 (owner: "make the file more readable"): the person's photo,
           name first and large, code and job under it, company over department, a coloured status,
           alternate rows shaded so a long line can be followed across the page. */
        table.roster td { padding: 3.5px 6px; vertical-align: middle; }
        table.roster tr.alt td { background: #f8fafc; }
        /* min-height, not height: a name that wraps grows the row instead of overlapping the code. */
        .who { position: relative; min-height: 26px; }
        .avatar {
            position: absolute; left: 0; top: 0;
            width: 26px; height: 26px; overflow: hidden;
            border-radius: 13px; background: #ede9fe;
        }
        .avatar img { position: absolute; }
        /* Centred by padding: dompdf puts line-height's extra space below the text, not around it. */
        .initials {
            width: 26px; height: 18px; padding-top: 7px; text-align: center;
            font-size: 9px; line-height: 10px; font-weight: bold; color: #6d28d9;
        }
        .who-text { margin-left: 33px; padding-top: 1px; }
        .name { font-size: 10.5px; font-weight: bold; color: #0f172a; }
        .sub { font-size: 8.5px; color: #475569; margin-top: 1px; }
        .org { font-size: 9.5px; color: #1e293b; }
        .pill {
            display: inline-block; padding: 1.5px 6px; border-radius: 7px;
            font-size: 8px; font-weight: bold;
        }
        .pill-active { background: #dcfce7; color: #166534; }
        .pill-inactive { background: #f1f5f9; color: #475569; }
        .pill-resigned { background: #fee2e2; color: #991b1b; }
        .term { font-size: 9px; color: #1e293b; }
        .term + .term { margin-top: 2px; }
        .summary { font-size: 10px; color: #334155; margin: 0 0 8px 0; }
        .summary b { color: #0f172a; }
    </style>

    @php
        $total = count($rows);
        $resigned = count(array_filter($rows, fn ($r) => $r['resigned']));
        $active = count(array_filter($rows, fn ($r) => ! $r['resigned'] && $r['active']));
        $inactive = $total - $resigned - $active;
    @endphp
    <p class="summary">
        <b>{{ number_format($total) }}</b> {{ $total === 1 ? 'employee' : 'employees' }}
        · <b>{{ number_format($active) }}</b> active
        @if ($inactive) · <b>{{ number_format($inactive) }}</b> inactive @endif
        @if ($resigned) · <b>{{ number_format($resigned) }}</b> resigned @endif
    </p>

    {{--
      | One table per block of rows (2026-11-15): dompdf lays a whole table out in memory before it
      | breaks it into pages, and Asker-WorkShop's 717 people in one table ran past 256 MB. The
      | blocks follow on directly, each repeating the header, so the pages read the same.
    --}}
    @php($blocks = $total ? array_chunk($rows, 60) : [[]])
    @foreach ($blocks as $bi => $block)
    <table class="roster">
        <thead>
            <tr>
                <th style="width: 3%;" class="num">No.</th>
                <th style="width: 27%;">Employee</th>
                <th style="width: 19%;">Company · Department</th>
                <th style="width: 9%;">Status</th>
                <th style="width: 9%;">Card</th>
                <th style="width: 33%;">Terminals</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($block as $row)
                <tr @class(['alt' => $row['no'] % 2 === 0])>
                    <td class="num muted">{{ $row['no'] }}</td>
                    <td>
                        <div class="who">
                            <div class="avatar">
                                @if ($row['photo'])
                                    <img src="{{ $row['photo']['path'] }}" alt=""
                                         style="width: {{ $row['photo']['w'] }}px; height: {{ $row['photo']['h'] }}px; left: {{ $row['photo']['x'] }}px; top: {{ $row['photo']['y'] }}px;">
                                @else
                                    <div class="initials">{{ $row['initials'] }}</div>
                                @endif
                            </div>
                            <div class="who-text">
                                <div class="name">{{ $row['name'] }}</div>
                                <div class="sub">
                                    <span class="mono">{{ $row['code'] ?? 'No code' }}</span>@if ($row['job_title']) · {{ $row['job_title'] }}@endif
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="org">{{ $row['company'] ?? '—' }}</div>
                        @if ($row['department'])<div class="sub">{{ $row['department'] }}</div>@endif
                    </td>
                    <td>
                        <span @class(['pill', 'pill-resigned' => $row['resigned'], 'pill-active' => ! $row['resigned'] && $row['active'], 'pill-inactive' => ! $row['resigned'] && ! $row['active']])>{{ $row['status'] }}</span>
                    </td>
                    <td class="mono">{{ $row['card'] ?? '—' }}</td>
                    <td>
                        @forelse ($row['terminals'] as $t)
                            <div class="term">{{ $t['name'] }} <span class="muted">· {{ $t['fingers'] ?? '?' }} {{ $t['fingers'] === 1 ? 'finger' : 'fingers' }}{{ $t['card'] ? ' · card' : '' }}</span></div>
                        @empty
                            <span class="muted">No terminal</span>
                        @endforelse
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No employees match this filter.</td></tr>
            @endforelse
            @if ($loop->last)
            <tr class="totals">
                <td colspan="6">{{ number_format($total) }} {{ $total === 1 ? 'employee' : 'employees' }}</td>
            </tr>
            @endif
        </tbody>
    </table>
    @endforeach

    <p class="muted" style="margin-top: 10px;">
        Terminals: the fingerprints each terminal holds for the person, as last read;
        "?" means not read or not readable, which is not the same as none. "card" means
        the terminal holds a card for them.
    </p>
@endsection
