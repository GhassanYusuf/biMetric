{{-- The outcome legend with each count and share, as a table so no item wraps apart. --}}
@php $total = array_sum($counts); @endphp
<table style="width: 100%; border-collapse: collapse; margin-top: 6px; table-layout: fixed;">
    <tr>
        @foreach (App\Support\PdfCharts::OUTCOMES as $key => $o)
            <td style="padding: 0; font-size: 8px; color: #475569; vertical-align: top;">
                <span class="sw" style="background: {{ $o['color'] }};"></span>{{ $o['label'] }}<br>
                <b style="color: #0f172a; font-size: 10px; margin-left: 12px;">{{ number_format($counts[$key] ?? 0) }}</b>
                @if ($total)<span style="font-size: 7.5px;">&nbsp;{{ number_format(($counts[$key] ?? 0) / $total * 100, 1) }}%</span>@endif
            </td>
        @endforeach
    </tr>
</table>
