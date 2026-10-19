<?php

namespace App\Support;

/**
 * Charts for the PDF reports, as SVG embedded in an <img> data URI.
 *
 * WHY SVG STRINGS AND NOT A LIBRARY: dompdf renders no JavaScript and this server
 * has no GD, so a raster chart is not an option. dompdf's SVG path (php-svg-lib)
 * draws vectors straight into the PDF — crisp at any zoom and in print. Checked on
 * 2026-10-18 against dompdf 3.1.6 / php-svg-lib 1.0.2: rect (with rx), line, path
 * (M/L/Z), circle, fill-opacity and text all render; the path ARC command (A) and CSS
 * gradients do NOT. So nothing here draws an arc, and nothing anywhere in the PDF
 * views uses a gradient.
 *
 * Every chart is drawn at its printed size (1 SVG unit = 1 CSS px), so a 9px label
 * is a 9px label on paper.
 *
 * COLOURS are the dashboard's attendance hues (STATUS_COLORS in shared/theme.js),
 * stepped darker for paper and validated as a set with the dataviz palette checker
 * on 2026-10-18: lightness band, contrast ≥ 3:1 and the normal-vision floor all pass.
 * Two flags are deliberate: "No punch-out" is slate because it means MISSING, as on
 * the screen; green beside orange is in the colour-blind floor band, so every chart
 * that pairs them also separates segments with gaps and carries a labelled legend
 * and printed counts. Colour is never the only carrier of a value.
 */
final class PdfCharts
{
    public const OUTCOMES = [
        'on_time' => ['label' => 'On time', 'color' => '#16a34a'],
        'late' => ['label' => 'Late arrival', 'color' => '#ea580c'],
        'left_early' => ['label' => 'Left early', 'color' => '#2563eb'],
        'excused' => ['label' => 'Excused', 'color' => '#a16207'],
        'missed_punch_out' => ['label' => 'No punch-out', 'color' => '#64748b'],
        'absent' => ['label' => 'Absent', 'color' => '#e11d48'],
    ];

    public const INK = '#0f172a';

    public const INK_2 = '#475569';

    public const MUTED = '#64748b';

    public const GRID = '#e2e8f0';

    public const ACCENT = '#7c3aed';

    private const FONT = 'font-family="DejaVu Sans"';

    /**
     * One 100% bar split by outcome — "how the days went" at a glance.
     *
     * @param  array<string,int>  $counts  outcome => days
     */
    public static function splitBar(array $counts, int $width, int $height = 16): string
    {
        $total = array_sum($counts);
        $svg = '';

        if ($total === 0) {
            $svg .= self::rect(0, 0, $width, $height, '#f1f5f9', 4);
        } else {
            $x = 0.0;
            foreach (self::OUTCOMES as $key => $o) {
                $n = $counts[$key] ?? 0;
                if ($n === 0) {
                    continue;
                }
                $w = $n / $total * $width;
                // A 2px surface gap between segments (drawn by trimming each one).
                $svg .= self::rect($x, 0, max($w - 2, 1), $height, $o['color'], 3);
                $x += $w;
            }
        }

        return self::img($svg, $width, $height);
    }

    /**
     * 100%-stacked columns, one per day (or week): the share of tracked people in
     * each outcome. Height is share, so a day with three people is as tall as a day
     * with ninety — the count prints under the axis for that reason.
     *
     * @param  list<array{label:string, outcomes:array<string,int>, tracked:int}>  $buckets
     */
    public static function stackedColumns(array $buckets, int $width, int $height = 170): string
    {
        $left = 30;
        $bottom = 30;
        $top = 6;
        $plotH = $height - $top - $bottom;
        $plotW = $width - $left - 4;
        $n = max(count($buckets), 1);
        $step = $plotW / $n;
        $barW = max(min($step - 2, 22), 1.5);

        $svg = '';
        foreach ([0, 0.25, 0.5, 0.75, 1] as $t) {
            $y = $top + $plotH * (1 - $t);
            $svg .= self::line($left, $y, $width - 4, $y, self::GRID, 0.75);
            $svg .= self::text($left - 5, $y + 3, ($t * 100).'%', 7.5, self::MUTED, 'end');
        }

        // Label every bucket when they fit, otherwise every k-th.
        $every = max(1, (int) ceil(34 / $step));

        foreach ($buckets as $i => $b) {
            $x = $left + $i * $step + ($step - $barW) / 2;
            $total = max($b['tracked'], 1);
            $y = $top + $plotH;
            foreach (array_keys(self::OUTCOMES) as $key) {
                $count = $b['outcomes'][$key] ?? 0;
                if ($count === 0) {
                    continue;
                }
                $h = $count / $total * $plotH;
                $gap = $h > 3 ? 1 : 0;
                $svg .= self::rect($x, $y - $h + $gap, $barW, max($h - $gap, 0.6), self::OUTCOMES[$key]['color'], 0);
                $y -= $h;
            }

            if ($i % $every === 0) {
                $cx = $x + $barW / 2;
                [$first, $second] = array_pad(explode(' ', $b['label'], 2), 2, '');
                $svg .= self::text($cx, $top + $plotH + 11, $first, 7, self::INK_2, 'middle');
                $svg .= self::text($cx, $top + $plotH + 20, $second, 7, self::MUTED, 'middle');
            }
        }

        return self::img($svg, $width, $height);
    }

    /**
     * When people's first punch of the day lands, in 10-minute bins around the shift
     * start, with the shift start and the late line marked. Bins past the late line
     * are orange; arrivals outside the drawn window are counted at its edges.
     *
     * @param  list<int>  $minutes  first-punch minutes after local midnight
     */
    public static function arrivalHistogram(array $minutes, int $shiftStart, int $lateAfter, int $width, int $height = 150): string
    {
        $bin = 10;
        $from = intdiv($shiftStart - 90, $bin) * $bin;
        $to = intdiv($shiftStart + 150, $bin) * $bin;
        $bins = array_fill(0, intdiv($to - $from, $bin), 0);
        $before = 0;
        $after = 0;
        foreach ($minutes as $m) {
            if ($m < $from) {
                $before++;
            } elseif ($m >= $to) {
                $after++;
            } else {
                $bins[intdiv($m - $from, $bin)]++;
            }
        }

        $left = 26;
        $bottom = 22;
        $top = 14;
        $plotW = $width - $left - 6;
        $plotH = $height - $top - $bottom;
        $max = max(max($bins ?: [0]), 1);
        $niceMax = self::niceCeil($max);
        $step = $plotW / count($bins);
        $x = fn (int $m) => $left + ($m - $from) / $bin * $step;

        $svg = '';
        foreach ([0, 0.5, 1] as $t) {
            $y = $top + $plotH * (1 - $t);
            $svg .= self::line($left, $y, $width - 6, $y, self::GRID, 0.75);
            $svg .= self::text($left - 4, $y + 3, (string) round($niceMax * $t), 7.5, self::MUTED, 'end');
        }

        foreach ($bins as $i => $count) {
            if ($count === 0) {
                continue;
            }
            $start = $from + $i * $bin;
            $h = $count / $niceMax * $plotH;
            $color = $start >= $lateAfter ? self::OUTCOMES['late']['color'] : self::OUTCOMES['on_time']['color'];
            $svg .= self::rect($left + $i * $step + 1, $top + $plotH - $h, $step - 2, $h, $color, min(2, $h / 2));
        }

        foreach ([[$shiftStart, 'Shift start', self::INK], [$lateAfter, 'Late after', self::OUTCOMES['late']['color']]] as $k => [$m, $label, $color]) {
            $lx = $x($m);
            $svg .= self::line($lx, $top - 2, $lx, $top + $plotH, $color, 1, '3 2');
            $svg .= self::text($lx + ($k === 0 ? -3 : 3), $top - 5, $label.' '.self::clock($m), 7, self::INK_2, $k === 0 ? 'end' : 'start');
        }

        for ($m = intdiv($from + 59, 60) * 60; $m <= $to; $m += 60) {
            $svg .= self::text($x($m), $top + $plotH + 11, self::clock($m), 7.5, self::INK_2, 'middle');
        }

        if ($before || $after) {
            $svg .= self::text($left, $height - 2, $before ? "{$before} earlier than ".self::clock($from) : '', 7, self::MUTED, 'start');
            $svg .= self::text($width - 6, $height - 2, $after ? "{$after} later than ".self::clock($to) : '', 7, self::MUTED, 'end');
        }

        return self::img($svg, $width, $height);
    }

    /**
     * A year (or any span) as a calendar: weeks across, weekdays down, each work day
     * coloured by its outcome. Non-work days are a pale cell; days before the person
     * was tracked are an outline only, so "not tracked" never reads as a colour.
     *
     * @param  array<string,string>  $outcomeByDate  Y-m-d => outcome (work days only)
     */
    public static function calendar(array $outcomeByDate, string $from, string $to, int $width, int $cell = 11): string
    {
        $start = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        $start = $start->modify('-'.$start->format('w').' days');
        $left = 22;
        $top = 14;
        $gap = 2;
        $weeks = (int) ceil(($end->diff($start)->days + 1) / 7);
        $cell = min($cell, (int) floor(($width - $left) / max($weeks, 1)) - $gap);
        $height = $top + 7 * ($cell + $gap);

        $svg = '';
        foreach ([1 => 'Mon', 3 => 'Wed', 5 => 'Fri'] as $row => $name) {
            $svg .= self::text($left - 4, $top + $row * ($cell + $gap) + $cell - 2, $name, 7, self::MUTED, 'end');
        }

        $lastMonth = null;
        for ($d = $start, $i = 0; $d <= $end; $d = $d->modify('+1 day'), $i++) {
            $week = intdiv($i, 7);
            $x = $left + $week * ($cell + $gap);
            $y = $top + (int) $d->format('w') * ($cell + $gap);
            $key = $d->format('Y-m-d');

            if ($key < $from) {
                continue;
            }
            if ($d->format('j') <= 7 && $d->format('w') === '0' || $lastMonth === null) {
                if ($lastMonth !== $d->format('M')) {
                    $svg .= self::text($x, $top - 4, $d->format('M'), 7, self::INK_2, 'start');
                    $lastMonth = $d->format('M');
                }
            }

            $outcome = $outcomeByDate[$key] ?? null;
            if ($outcome === null) {
                $svg .= self::rect($x, $y, $cell, $cell, '#f1f5f9', 2);
            } elseif ($outcome === 'untracked') {
                $svg .= '<rect x="'.$x.'" y="'.$y.'" width="'.$cell.'" height="'.$cell.'" rx="2" fill="#ffffff" stroke="#cbd5e1" stroke-width="0.75"/>';
            } else {
                $svg .= self::rect($x, $y, $cell, $cell, self::OUTCOMES[$outcome]['color'], 2);
            }
        }

        return self::img($svg, $left + $weeks * ($cell + $gap), $height);
    }

    /**
     * A wall-calendar view of a short period (up to ~two months): one row per week,
     * Sunday first. Each work day is a tinted cell with its date, a strip in its
     * outcome colour and — when they came in — the arrival time, so a month can be
     * read without the table. Non-work days are pale; days outside the period blank.
     *
     * @param  array<string,string>  $outcomeByDate  Y-m-d => outcome (work days only)
     * @param  array<string,?int>  $firstMinutes  Y-m-d => first punch, minutes after midnight
     */
    public static function monthCalendar(array $outcomeByDate, array $firstMinutes, string $from, string $to, int $width): string
    {
        $start = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        $gridStart = $start->modify('-'.$start->format('w').' days');
        $weeks = (int) ceil(($end->diff($gridStart)->days + 1) / 7);

        $gap = 4;
        $head = 14;
        $cellW = ($width - 6 * $gap) / 7;
        $cellH = $weeks > 6 ? 34 : 40;
        $height = $head + $weeks * ($cellH + $gap);

        $svg = '';
        foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $i => $name) {
            $svg .= self::text($i * ($cellW + $gap) + 4, 9, $name, 7.5, self::MUTED, 'start');
        }

        for ($d = $gridStart, $i = 0; $i < $weeks * 7; $d = $d->modify('+1 day'), $i++) {
            $key = $d->format('Y-m-d');
            if ($key < $from || $key > $to) {
                continue;
            }
            $x = ($i % 7) * ($cellW + $gap);
            $y = $head + intdiv($i, 7) * ($cellH + $gap);
            $outcome = $outcomeByDate[$key] ?? null;
            $label = $d->format('j') === '1' || $key === $from ? $d->format('j M') : $d->format('j');

            if ($outcome === null || $outcome === 'untracked') {
                $svg .= $outcome === 'untracked'
                    ? '<rect x="'.round($x, 2).'" y="'.$y.'" width="'.round($cellW, 2).'" height="'.$cellH.'" rx="5" fill="#ffffff" stroke="#cbd5e1" stroke-width="0.75"/>'
                    : self::rect($x, $y, $cellW, $cellH, '#f8fafc', 5);
                $svg .= self::text($x + 6, $y + 12, $label, 8, self::MUTED, 'start');

                continue;
            }

            $color = self::OUTCOMES[$outcome]['color'];
            $svg .= sprintf('<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="5" fill="%s" fill-opacity="0.13"/>', $x, $y, $cellW, $cellH, $color);
            $svg .= self::rect($x, $y + $cellH - 5, $cellW, 5, $color, 2);
            $svg .= self::text($x + 6, $y + 12, $label, 8, self::INK, 'start');
            $in = $firstMinutes[$key] ?? null;
            $svg .= self::text($x + $cellW - 6, $y + $cellH - 10, $in !== null ? self::clock($in) : self::OUTCOMES[$outcome]['label'], 7.5, self::INK_2, 'end');
        }

        return self::img($svg, $width, $height);
    }

    /**
     * Each work day's arrival and departure as dots against the shift band: filled
     * dot = first punch (coloured by the day's outcome), ring = last punch.
     *
     * @param  list<array{date:string, outcome:string, first_minutes:?int, last_minutes:?int}>  $days
     */
    public static function dayTimes(array $days, int $shiftStart, int $shiftEnd, int $lateAfter, int $width, int $height = 170): string
    {
        $present = array_values(array_filter($days, fn ($d) => $d['first_minutes'] !== null));
        $all = array_merge(array_column($present, 'first_minutes'), array_filter(array_column($present, 'last_minutes'), fn ($m) => $m !== null));
        $lo = intdiv(min([$shiftStart - 60, ...$all]), 60) * 60;
        $hi = (intdiv(max([$shiftEnd + 60, ...$all]), 60) + 1) * 60;

        $left = 40;
        $top = 6;
        $bottom = 20;
        $plotW = $width - $left - 8;
        $plotH = $height - $top - $bottom;
        $n = max(count($days), 1);
        $step = $plotW / $n;
        $y = fn (int $m) => $top + ($m - $lo) / ($hi - $lo) * $plotH; // earlier = higher
        $r = max(min($step * 0.38, 3.2), 1.3);

        $svg = self::rect($left, $y($shiftStart), $plotW, $y($shiftEnd) - $y($shiftStart), '#f5f3ff', 0);
        $span = $hi - $lo;
        $tick = $span > 12 * 60 ? 180 : ($span > 6 * 60 ? 120 : 60);
        for ($m = $lo; $m <= $hi; $m += $tick) {
            $svg .= self::line($left, $y($m), $width - 8, $y($m), self::GRID, 0.6);
            $svg .= self::text($left - 4, $y($m) + 3, self::clock($m), 7.5, self::MUTED, 'end');
        }
        $svg .= self::line($left, $y($lateAfter), $width - 8, $y($lateAfter), self::OUTCOMES['late']['color'], 0.8, '3 2');

        $every = max(1, (int) ceil(30 / $step));
        foreach ($days as $i => $d) {
            $cx = $left + ($i + 0.5) * $step;
            if ($d['first_minutes'] !== null && $d['last_minutes'] !== null) {
                $svg .= self::line($cx, $y($d['first_minutes']), $cx, $y($d['last_minutes']), '#cbd5e1', min(1.2, $r / 2));
            }
            if ($d['last_minutes'] !== null) {
                $svg .= '<circle cx="'.round($cx, 2).'" cy="'.round($y($d['last_minutes']), 2).'" r="'.$r.'" fill="#ffffff" stroke="'.self::INK_2.'" stroke-width="0.9"/>';
            }
            if ($d['first_minutes'] !== null) {
                $color = self::OUTCOMES[$d['outcome']]['color'] ?? self::INK_2;
                $svg .= '<circle cx="'.round($cx, 2).'" cy="'.round($y($d['first_minutes']), 2).'" r="'.$r.'" fill="'.$color.'"/>';
            } elseif ($d['outcome'] === 'absent' || $d['outcome'] === 'excused') {
                // No punch: a short tick on the baseline so the day is visibly there.
                $svg .= self::rect($cx - $r, $top + $plotH + 1, $r * 2, 2.5, self::OUTCOMES[$d['outcome']]['color'], 1);
            }
            if ($i % $every === 0) {
                $svg .= self::text($cx, $height - 4, (new \DateTimeImmutable($d['date']))->format('j M'), 7, self::INK_2, 'middle');
            }
        }

        return self::img($svg, $width, $height);
    }

    /**
     * Plain vertical columns with a value over each, for a short labelled series
     * (twelve months, five weekdays). A null value is a gap labelled "—", never a 0.
     *
     * @param  list<array{label:string, value:?float, note?:string}>  $series
     */
    public static function columns(array $series, int $width, int $height, string $color, bool $percent = true): string
    {
        $top = 14;
        $bottom = 16;
        $plotH = $height - $top - $bottom;
        $max = $percent ? 1.0 : max(1, ...array_map(fn ($s) => $s['value'] ?? 0, $series));
        $step = $width / max(count($series), 1);
        $barW = min($step * 0.56, 34);

        $svg = self::line(0, $top + $plotH, $width, $top + $plotH, self::GRID, 1);
        foreach ($series as $i => $s) {
            $cx = ($i + 0.5) * $step;
            if ($s['value'] !== null) {
                $h = max($s['value'] / $max * $plotH, 0.8);
                $svg .= self::rect($cx - $barW / 2, $top + $plotH - $h, $barW, $h, $color, min(3, $h / 2));
                $label = $percent ? round($s['value'] * 100).'%' : (string) round($s['value']);
                $svg .= self::text($cx, $top + $plotH - $h - 3, $label, 7.5, self::INK, 'middle');
            } else {
                $svg .= self::text($cx, $top + $plotH - 3, '—', 7.5, self::MUTED, 'middle');
            }
            $svg .= self::text($cx, $height - 4, $s['label'], 7.5, self::INK_2, 'middle');
        }

        return self::img($svg, $width, $height);
    }

    /** "9:00 AM" from minutes after midnight; "—" for null. */
    public static function clock(?float $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        $m = (int) round($minutes);
        $h = intdiv($m, 60) % 24;

        return sprintf('%d:%02d %s', $h % 12 ?: 12, $m % 60, $h < 12 ? 'AM' : 'PM');
    }

    private static function niceCeil(float $v): float
    {
        $p = 10 ** floor(log10(max($v, 1)));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($m * $p >= $v) {
                return $m * $p;
            }
        }

        return 10 * $p;
    }

    private static function rect(float $x, float $y, float $w, float $h, string $fill, float $rx): string
    {
        return sprintf('<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="%.2f" fill="%s"/>', $x, $y, $w, $h, $rx, $fill);
    }

    private static function line(float $x1, float $y1, float $x2, float $y2, string $stroke, float $width, ?string $dash = null): string
    {
        return sprintf('<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke="%s" stroke-width="%.2f"%s/>',
            $x1, $y1, $x2, $y2, $stroke, $width, $dash ? ' stroke-dasharray="'.$dash.'"' : '');
    }

    private static function text(float $x, float $y, string $text, float $size, string $fill, string $anchor): string
    {
        return sprintf('<text x="%.2f" y="%.2f" %s font-size="%.1f" fill="%s" text-anchor="%s">%s</text>',
            $x, $y, self::FONT, $size, $fill, $anchor, htmlspecialchars($text, ENT_XML1));
    }

    private static function img(string $body, float $width, float $height): string
    {
        $w = (int) ceil($width);
        $h = (int) ceil($height);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'">'.$body.'</svg>';

        return '<img src="data:image/svg+xml;base64,'.base64_encode($svg).'" width="'.$w.'" height="'.$h.'" style="display:block;">';
    }
}
