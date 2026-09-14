{{--
| The shared page furniture for every export.
|
| A PDF is read away from the app that made it, so the page has to answer on its
| own what it is, whose it is, over what range, in which timezone, and when it
| was produced. All five live in the fixed header/footer, which dompdf repeats on
| every page — a range printed only on page 1 is lost the moment somebody files
| page 4.
|
| Fonts: DejaVu Sans is the only font dompdf ships with broad Unicode coverage.
| It does NOT shape Arabic or other right-to-left scripts — those names will
| render as disjoint glyphs. Documented rather than hidden; a font with shaping
| has to be registered with dompdf before that can work.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 108px 32px 62px 32px; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9.5px;
            color: #1e293b;
            margin: 0;
        }

        header {
            position: fixed;
            top: -86px;
            left: 0;
            right: 0;
            height: 74px;
        }

        .brand {
            font-size: 10px;
            font-weight: bold;
            color: #7c3aed;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        h1 {
            font-size: 17px;
            margin: 3px 0 0 0;
            color: #0f172a;
        }

        .meta { margin-top: 3px; font-size: 9.5px; color: #475569; }
        .meta span { margin-right: 12px; }

        .rule { border-bottom: 1px solid #cbd5e1; margin-top: 8px; }

        footer {
            position: fixed;
            bottom: -44px;
            left: 0;
            right: 0;
            height: 32px;
            padding-top: 6px;
            border-top: 1px solid #e2e8f0;
            font-size: 8px;
            color: #475569;
        }

        .page-number:after { content: counter(page) " of " counter(pages); }

        table { width: 100%; border-collapse: collapse; }

        thead { display: table-header-group; }

        th {
            text-align: left;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #475569;
            background: #f1f5f9;
            padding: 5px 6px;
            border-bottom: 1px solid #cbd5e1;
        }

        td {
            padding: 4.5px 6px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        tr { page-break-inside: avoid; }

        .num { text-align: right; }
        .muted { color: #475569; }
        .mono { font-family: "DejaVu Sans Mono", monospace; font-size: 8.5px; }

        .notice {
            border: 1px solid #fbbf24;
            background: #fffbeb;
            color: #78350f;
            padding: 7px 9px;
            margin-bottom: 10px;
        }

        .totals td { border-top: 1.5px solid #94a3b8; border-bottom: none; font-weight: bold; }
    </style>
</head>
<body>
    <header>
        @if ($tenantName)
            <div class="brand">{{ $tenantName }}</div>
        @endif
        <h1>{{ $title }}</h1>
        <div class="meta">
            <span>{{ $rangeLabel }}</span>
            @foreach ($filterLines ?? [] as $line)
                <span>{{ $line }}</span>
            @endforeach
        </div>
        <div class="rule"></div>
    </header>

    <footer>
        {{-- The zone is stated on every page: these times are office-local, not UTC,
             and not the reader's browser timezone. --}}
        Times shown in office time ({{ $timezone }}) · Generated {{ $generatedAt }}
        <span style="float: right;">Page <span class="page-number"></span></span>
    </footer>

    @yield('content')
</body>
</html>
