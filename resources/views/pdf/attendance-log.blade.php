{{-- The punch log exactly as filtered on screen. --}}
@extends('pdf.layout')

@section('content')
    @if ($truncated)
        {{--
          | Never print a partial log as if it were the whole one. An attendance
          | record that stops early reads as absence, which is the same class of
          | lie rule 10 forbids about fingerprint counts.
        --}}
        <div class="notice">
            <strong>This export is incomplete.</strong>
            {{ number_format($total) }} punches match these filters and only the
            {{ number_format($shown) }} most recent are printed
            ({{ number_format($maxRows) }} is the per-file limit).
            Narrow the date range and export again to get the rest.
        </div>
    @endif

    <p class="muted" style="margin: 0 0 8px 0;">
        {{ number_format($shown) }} punch{{ $shown === 1 ? '' : 'es' }}, newest first.
    </p>

    {{--
      | One table per block of rows (2026-11-15): dompdf lays a whole table out in memory before it
      | breaks it into pages, and a 5,000-punch log (Gems WorkShop, once its 16,277 punches were
      | read) ran past 256 MB in one table. The blocks follow on directly, each repeating the
      | header, so the pages read the same. Same fix as pdf.employee-roster.
    --}}
    @php($blocks = count($rows) ? array_chunk(is_array($rows) ? $rows : $rows->all(), 60) : [[]])
    @foreach ($blocks as $block)
    <table>
        <thead>
            <tr>
                <th style="width: 28%;">Employee</th>
                <th style="width: 12%;">Code</th>
                <th style="width: 22%;">Device</th>
                <th style="width: 14%;">Method</th>
                <th style="width: 13%;">Date</th>
                <th style="width: 11%;" class="num">Time</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($block as $row)
                <tr>
                    {{-- A punch the sync could not attribute to anybody stays
                         "Unmatched" here too, rather than being dropped. --}}
                    <td>{{ $row['name'] ?? 'Unmatched' }}</td>
                    <td class="mono">{{ $row['code'] ?? '—' }}</td>
                    <td>{{ $row['device'] ?? '—' }}</td>
                    <td>{{ $row['method'] }}</td>
                    <td>{{ $row['date'] ?? '—' }}</td>
                    <td class="num">{{ $row['time'] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No punches match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
    @endforeach
@endsection
