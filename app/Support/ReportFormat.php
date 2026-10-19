<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Number and delta formatting for the PDF reports, so the company and employee
 * reports print a rate, a clock time and a change the same way.
 *
 * Null stays "—" everywhere (rule 3): a rate with no days under it is not 0%.
 */
final class ReportFormat
{
    public static function pct(?float $rate, int $decimals = 1): string
    {
        return $rate === null ? '—' : number_format($rate * 100, $decimals).'%';
    }

    public static function num(int|float|null $n, int $decimals = 0): string
    {
        return $n === null ? '—' : number_format($n, $decimals);
    }

    public static function date(string $ymd, string $format = 'j M Y'): string
    {
        return Carbon::parse($ymd)->format($format);
    }

    public static function range(string $from, string $to): string
    {
        return self::date($from).' – '.self::date($to);
    }

    /**
     * A change against the previous period, and whether it is good news. The text
     * is the whole phrase ("▲ 4.3 pts vs previous"), so it reads on its own.
     *
     * $better says which direction is good: 'up' (attendance), 'down' (absences,
     * arrival time). The tone is null when either side is missing or nothing moved,
     * so the template prints it neutral rather than green for "no data".
     *
     * @return array{text:string, tone:?string}
     */
    public static function delta(?float $now, ?float $before, string $kind, string $better): array
    {
        if ($now === null || $before === null) {
            return ['text' => 'nothing earlier to compare', 'tone' => null];
        }

        $d = $now - $before;
        [$text, $zero] = match ($kind) {
            'pts' => [number_format(abs($d) * 100, 1).' pts', round(abs($d) * 100, 1) == 0.0],
            'min' => [round(abs($d)).' min '.($d < 0 ? 'earlier' : 'later'), round(abs($d)) == 0],
            default => [number_format(abs($d)), round(abs($d)) == 0],
        };

        if ($zero) {
            return ['text' => 'no change on previous', 'tone' => null];
        }

        $arrow = $d > 0 ? '▲' : '▼';
        $good = ($d > 0) === ($better === 'up');

        return ['text' => ($kind === 'min' ? $text : $arrow.' '.$text).' vs previous', 'tone' => $good ? 'good' : 'bad'];
    }
}
