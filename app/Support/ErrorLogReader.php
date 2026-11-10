<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Reads the application's own log files for the Error logs screen (owner, 2026-11-09:
 * "build two new features: audit logs and error logs").
 *
 * Three sources, all written already (rule 13: no new storage):
 *
 *   server   storage/logs/laravel.log     exceptions and warnings from the app and its jobs
 *   device   storage/logs/device-*.log    terminal communication (timeouts, refusals)
 *   browser  storage/logs/client-*.log    crashes the website caught in someone's browser
 *
 * Only `production.*` entries: the test suite writes `testing.*` entries into the same
 * laravel.log when it runs on this machine (found 2026-11-09: ~10,000 of them).
 *
 * Bounded so a large file cannot hang a request: each file is read from its END, at most
 * READ_BYTES of it, and daily files only for the days asked. A stack trace or a long context
 * is cut to a few KB. Nothing here writes.
 *
 * Monolog line format: "[Y-m-d H:i:s] env.LEVEL: message {context} {extra}", continued on the
 * following lines by a stack trace. Times are the app's time zone (UTC).
 */
final class ErrorLogReader
{
    public const SOURCES = ['server', 'device', 'browser'];

    /** Highest first; a filter "warning" means warning and everything above it. */
    public const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    private const READ_BYTES = 8 * 1024 * 1024;

    private const DETAIL_CAP = 6000;

    public function __construct(private readonly string $dir = '') {}

    private function dir(): string
    {
        return $this->dir !== '' ? $this->dir : storage_path('logs');
    }

    /**
     * @param  array{source?:?string, level?:?string, from?:?string, to?:?string, search?:?string}  $f
     *         from/to are office-local days (config attendance.timezone)
     * @return list<array{id:string, source:string, level:string, at:string, message:string, context:?array, detail:?string}>
     */
    public function entries(array $f): array
    {
        $tz = config('attendance.timezone');
        $from = Carbon::parse($f['from'] ?? now($tz)->subDays(6)->toDateString(), $tz)->startOfDay()->utc();
        $to = Carbon::parse($f['to'] ?? now($tz)->toDateString(), $tz)->endOfDay()->utc();
        $minLevel = array_search(strtolower((string) ($f['level'] ?? 'warning')), self::LEVELS, true);
        $minLevel = $minLevel === false ? 4 : $minLevel;
        $search = trim((string) ($f['search'] ?? ''));
        $sources = ! empty($f['source']) && in_array($f['source'], self::SOURCES, true) ? [$f['source']] : self::SOURCES;

        $out = [];
        foreach ($sources as $source) {
            foreach ($this->files($source, $from, $to) as $path) {
                foreach ($this->parse($path, $source) as $e) {
                    if ($e['at'] < $from || $e['at'] > $to) {
                        continue;
                    }
                    if (array_search($e['level'], self::LEVELS, true) > $minLevel) {
                        continue;
                    }
                    if ($search !== '' && stripos($e['message'].' '.json_encode($e['context']), $search) === false) {
                        continue;
                    }
                    $e['at'] = $e['at']->toIso8601String();
                    $out[] = $e;
                }
            }
        }

        usort($out, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return $out;
    }

    /**
     * Repeats folded together: the same source, level and message (numbers and quoted values
     * made generic), with how often and when first and last. City Center Hotel's timeout every
     * five minutes is one row, not 288 a day.
     *
     * @return list<array{signature:string, source:string, level:string, message:string, count:int, first_at:string, last_at:string, sample:array}>
     */
    public function grouped(array $entries): array
    {
        $groups = [];
        foreach ($entries as $e) {
            $key = $e['source'].'|'.$e['level'].'|'.self::signature($e['message']).'|'.($e['context']['device_name'] ?? '');
            if (! isset($groups[$key])) {
                $groups[$key] = ['signature' => md5($key), 'source' => $e['source'], 'level' => $e['level'], 'message' => $e['message'],
                    'count' => 0, 'first_at' => $e['at'], 'last_at' => $e['at'], 'sample' => $e];
            }
            $groups[$key]['count']++;
            $groups[$key]['first_at'] = min($groups[$key]['first_at'], $e['at']);
            $groups[$key]['last_at'] = max($groups[$key]['last_at'], $e['at']);
        }
        $list = array_values($groups);
        usort($list, fn ($a, $b) => strcmp($b['last_at'], $a['last_at']));

        return $list;
    }

    /** The newest change to any log file, for the live stamp (rule 18). */
    public function stamp(): string
    {
        $latest = 0;
        foreach (glob($this->dir().'/{laravel,device-*,client-*}.log', GLOB_BRACE) ?: [] as $p) {
            $latest = max($latest, (int) @filemtime($p));
        }

        return (string) $latest;
    }

    /**
     * The message with what varies made generic: quoted values, numbers, and long hex ids —
     * the MQTT client's random id ("[054d2b7ba22e832d9c33]") made every connection failure a
     * group of its own (2026-11-10: 55 rows for one cause).
     */
    private static function signature(string $message): string
    {
        return preg_replace(['/\b[0-9a-f]{12,}\b/i', '/"[^"]*"/', "/'[^']*'/", '/\d+/'], ['<id>', '""', "''", '#'], mb_substr($message, 0, 300));
    }

    /** @return list<string> */
    private function files(string $source, Carbon $from, Carbon $to): array
    {
        if ($source === 'server') {
            return is_file($this->dir().'/laravel.log') ? [$this->dir().'/laravel.log'] : [];
        }
        $prefix = $source === 'device' ? 'device' : 'client';
        $files = [];
        // Daily files are named by the UTC day they were written.
        for ($d = $from->copy()->startOfDay(); $d <= $to; $d->addDay()) {
            $p = $this->dir()."/{$prefix}-".$d->toDateString().'.log';
            if (is_file($p)) {
                $files[] = $p;
            }
        }

        return $files;
    }

    /** @return \Generator<array> */
    private function parse(string $path, string $source): \Generator
    {
        $size = (int) filesize($path);
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return;
        }
        $start = max(0, $size - self::READ_BYTES);
        fseek($fh, $start);
        if ($start > 0) {
            fgets($fh); // drop the partial first line
        }

        $current = null;
        $lineNo = 0;
        while (($line = fgets($fh)) !== false) {
            $lineNo++;
            if (preg_match('/^\[(\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d)[^\]]*\] (\w+)\.(\w+): (.*)$/s', $line, $m)) {
                if ($current !== null) {
                    yield $this->finish($current);
                }
                $current = $m[2] === 'production' ? [
                    'id' => md5($path.'|'.($start + $lineNo).'|'.$m[1]),
                    'source' => $source,
                    'level' => strtolower($m[3]),
                    'at' => Carbon::parse($m[1], config('app.timezone')),
                    'raw' => rtrim($m[4]),
                    'trace' => '',
                ] : null;
            } elseif ($current !== null && strlen($current['trace']) < self::DETAIL_CAP) {
                $current['trace'] .= $line;
            }
        }
        if ($current !== null) {
            yield $this->finish($current);
        }
        fclose($fh);
    }

    /** Splits "message {context} {extra}" and caps the rest. */
    private function finish(array $e): array
    {
        $raw = $e['raw'];
        $message = $raw;
        $context = null;
        $raw = preg_replace('/ \[\]$/', '', $raw);
        $pos = strpos($raw, ' {');
        if ($pos !== false) {
            $decoded = json_decode(substr($raw, $pos + 1), true);
            if (! is_array($decoded)) {
                // "{context} {extra}": try the context alone.
                $end = strrpos($raw, '} {');
                $decoded = $end !== false ? json_decode(substr($raw, $pos + 1, $end - $pos), true) : null;
            }
            if (is_array($decoded)) {
                $message = substr($raw, 0, $pos);
                $context = $decoded;
            }
        }

        // An exception logged by Laravel carries its whole trace in the context; keep the head.
        if (is_array($context) && isset($context['exception']) && is_string($context['exception']) && strlen($context['exception']) > self::DETAIL_CAP) {
            $context['exception'] = substr($context['exception'], 0, self::DETAIL_CAP).' …[cut]';
        }
        $detail = trim($e['trace']) !== '' ? mb_substr(trim($e['trace']), 0, self::DETAIL_CAP) : null;

        return [
            'id' => $e['id'],
            'source' => $e['source'],
            'level' => $e['level'],
            'at' => $e['at'],
            'message' => mb_substr(trim($message), 0, 1000),
            'context' => $context,
            'detail' => $detail,
        ];
    }
}
