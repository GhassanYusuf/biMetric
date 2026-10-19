{{--
| The page furniture for the visual reports (2026-10-18): the company attendance
| report and the employee report. The plain exports keep pdf.layout.
|
| Same promise as pdf.layout: every page says whose it is, what range, in which
| timezone and when it was made, in a header and footer dompdf repeats.
|
| dompdf limits that shape everything here: no flexbox or grid (layout is tables),
| no CSS gradients, no SVG arcs (see App\Support\PdfCharts). DejaVu Sans is the only
| bundled font with broad Unicode coverage, and it does not shape Arabic.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 58px 36px 52px 36px; }

        body { font-family: "DejaVu Sans", sans-serif; font-size: 9px; color: #0f172a; margin: 0; line-height: 1.35; }

        header { position: fixed; top: -40px; left: 0; right: 0; height: 22px; border-bottom: 1px solid #e2e8f0; font-size: 8px; color: #475569; }
        header .mark { display: inline-block; width: 8px; height: 8px; background: #7c3aed; border-radius: 2px; margin-right: 5px; }
        header .who { font-weight: bold; color: #0f172a; text-transform: uppercase; letter-spacing: 0.06em; }
        footer { position: fixed; bottom: -36px; left: 0; right: 0; height: 20px; border-top: 1px solid #e2e8f0; padding-top: 5px; font-size: 7.5px; color: #475569; }

        .hero { background: #0f172a; color: #ffffff; border-radius: 10px; padding: 18px 22px 16px 22px; }
        .hero .eyebrow { font-size: 8px; letter-spacing: 0.12em; text-transform: uppercase; color: #c4b5fd; font-weight: bold; }
        .hero h1 { font-size: 23px; margin: 4px 0 2px 0; font-weight: bold; color: #ffffff; }
        .hero .sub { font-size: 10px; color: #e2e8f0; }
        .hero .fine { font-size: 8px; color: #cbd5e1; margin-top: 6px; }
        .accent { height: 3px; background: #7c3aed; border-radius: 2px; margin: 0 22px; }

        .grid { width: 100%; border-collapse: separate; border-spacing: 7px 7px; margin: -7px; table-layout: fixed; }
        .grid td { vertical-align: top; padding: 0; }

        .tile { background: #f8fafc; border-radius: 8px; padding: 9px 11px 9px 11px; height: 66px; }
        .tile .label { font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.06em; color: #475569; font-weight: bold; }
        .tile .value { font-size: 18px; font-weight: bold; color: #0f172a; margin-top: 3px; }
        .tile .value small { font-size: 9px; color: #475569; font-weight: normal; }
        .tile .note { font-size: 7.5px; color: #475569; margin-top: 2px; }
        .good { color: #15803d; }
        .bad { color: #be123c; }

        h2 { font-size: 12.5px; margin: 0 0 2px 0; padding-left: 7px; border-left: 3px solid #7c3aed; color: #0f172a; }
        .lead { font-size: 8.5px; color: #475569; margin: 0 0 8px 10px; }
        .section { margin-top: 18px; }
        .panel { background: #f8fafc; border-radius: 8px; padding: 10px 12px; }
        .panel h3 { font-size: 9.5px; margin: 0 0 6px 0; color: #0f172a; }

        .legend { font-size: 8px; color: #475569; margin-top: 6px; }
        .legend span.item { margin-right: 12px; white-space: nowrap; }
        .sw { display: inline-block; width: 8px; height: 8px; border-radius: 2px; margin-right: 4px; }
        .dot { display: inline-block; width: 7px; height: 7px; border-radius: 4px; margin-right: 4px; }

        .track { background: #e2e8f0; height: 6px; border-radius: 3px; }
        .fill { height: 6px; border-radius: 3px; }

        table.data { width: 100%; border-collapse: collapse; }
        table.data thead { display: table-header-group; }
        table.data th { text-align: left; font-size: 7px; text-transform: uppercase; letter-spacing: 0.05em; color: #475569; padding: 5px 5px; border-bottom: 1px solid #cbd5e1; font-weight: bold; }
        table.data td { padding: 3px 5px; border-bottom: 1px solid #eef2f6; vertical-align: middle; }
        table.data tr { page-break-inside: avoid; }
        table.data .group td { background: #f1f5f9; font-weight: bold; font-size: 8.5px; border-bottom: none; padding-top: 5px; }
        .num { text-align: right; }
        .muted { color: #475569; }
        .mono { font-family: "DejaVu Sans Mono", monospace; font-size: 8px; }
        .rank { color: #64748b; width: 15px; }

        .callout { border-left: 3px solid #b45309; background: #fffbeb; color: #451a03; padding: 8px 11px; border-radius: 0 6px 6px 0; }
        .callout b { color: #78350f; }

        .alert { border-radius: 6px; padding: 6px 9px; margin-bottom: 5px; background: #f8fafc; }
        .alert .lvl { font-size: 7px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.06em; }
        .critical { color: #be123c; }
        .serious { color: #c2410c; }
        .warning { color: #a16207; }

        .break { page-break-before: always; }
        .avoid { page-break-inside: avoid; }
        .definitions td { padding: 3px 6px 3px 0; vertical-align: top; font-size: 8px; color: #334155; }
        .definitions td.term { font-weight: bold; color: #0f172a; white-space: nowrap; }
    </style>
</head>
<body>
    <header>
        <span class="mark"></span><span class="who">{{ $tenantName ?? 'Attendance' }}</span>
        <span> · {{ $title }}</span>
        <span style="float: right;">{{ $rangeLabel }}</span>
    </header>

    <footer>
        {{-- The zone on every page: office time, not UTC, not the reader's browser. --}}
        {{-- "Page N of M" is drawn by ExportController::render(): dompdf leaves
             counter(pages) at 0, so CSS cannot print the total. --}}
        Times in office time ({{ $timezone }}) · Generated {{ $generatedAt }}
    </footer>

    @yield('content')
</body>
</html>
