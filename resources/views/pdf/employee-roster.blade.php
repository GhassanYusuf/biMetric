{{-- The employee list as filtered on the Employees screen (EmployeeRoster). --}}
@extends('pdf.layout')

@section('content')
    {{--
      | One table per block of rows (2026-11-15): dompdf lays a whole table out in memory before it
      | breaks it into pages, and Asker-WorkShop's 717 people in one table ran past 256 MB. The
      | blocks follow on directly, each repeating the header, so the pages read the same.
    --}}
    @php($blocks = count($rows) ? array_chunk($rows, 60) : [[]])
    @foreach ($blocks as $bi => $block)
    <table @if ($bi > 0) style="margin-top: 0;" @endif>
        <thead>
            <tr>
                <th style="width: 4%;" class="num">No.</th>
                <th style="width: 8%;">Code</th>
                <th style="width: 20%;">Name</th>
                <th style="width: 10%;">Company</th>
                <th style="width: 12%;">Department</th>
                <th style="width: 11%;">Job title</th>
                <th style="width: 8%;">Status</th>
                <th style="width: 8%;">Card</th>
                <th style="width: 19%;">Terminals</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($block as $row)
                <tr>
                    <td class="num muted">{{ $row['no'] }}</td>
                    <td class="mono">{{ $row['code'] ?? '—' }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['company'] ?? '—' }}</td>
                    <td>{{ $row['department'] ?? '—' }}</td>
                    <td>{{ $row['job_title'] ?? '—' }}</td>
                    <td>{{ $row['status'] }}</td>
                    <td class="mono">{{ $row['card'] ?? '—' }}</td>
                    <td>
                        @forelse ($row['terminals'] as $t)
                            <div>{{ $t['name'] }} <span class="muted">· {{ $t['fingers'] ?? '?' }} {{ $t['fingers'] === 1 ? 'finger' : 'fingers' }}{{ $t['card'] ? ' · card' : '' }}</span></div>
                        @empty
                            <span class="muted">No terminal</span>
                        @endforelse
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="muted">No employees match this filter.</td></tr>
            @endforelse
            @if ($loop->last)
            <tr class="totals">
                <td colspan="9">{{ number_format(count($rows)) }} {{ count($rows) === 1 ? 'employee' : 'employees' }}</td>
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
