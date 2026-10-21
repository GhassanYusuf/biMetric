{{-- The employee list as filtered on the Employees screen (EmployeeRoster). --}}
@extends('pdf.layout')

@section('content')
    <table>
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
            @forelse ($rows as $row)
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
            <tr class="totals">
                <td colspan="9">{{ number_format(count($rows)) }} {{ count($rows) === 1 ? 'employee' : 'employees' }}</td>
            </tr>
        </tbody>
    </table>

    <p class="muted" style="margin-top: 10px;">
        Terminals: the fingerprints each terminal holds for the person, as last read;
        "?" means not read or not readable, which is not the same as none. "card" means
        the terminal holds a card for them.
    </p>
@endsection
