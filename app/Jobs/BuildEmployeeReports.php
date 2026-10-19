<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\ReportExport;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Realtime\MqttPublisher;
use App\Services\Realtime\TopicBuilder;
use App\Services\Reports\PdfRenderer;
use App\Services\Reports\PersonalReport;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Builds the bulk employee-reports PDF a few people at a time (2026-10-18).
 *
 * WHY CHUNKS THAT RE-QUEUE THEMSELVES: there is ONE queue worker on this server and it
 * also runs every device command. A single job rendering 130 people would hold the
 * terminals' syncs for minutes. Each job renders CHUNK people (a few seconds) and then
 * queues the next chunk at the BACK of the queue, so a device command queued meanwhile
 * runs in between. Laravel's job batches would do the bookkeeping, but their table was
 * dropped on 2026-09-27 as unused; the row's `done` counter is the bookkeeping instead.
 *
 * Each person is rendered ALONE, exactly as the single-person export renders them —
 * same view, same numbering ("Page 2 of 3" is within that person). The last chunk
 * renders the contents page and joins everything with `pdfunite`. One dompdf document
 * of 400+ pages would not fit in memory; 130 small ones and a join do.
 *
 * A person who fails to render is listed on the contents page as not included, with
 * the reason — the file never silently skips somebody. A failure of the job itself
 * marks the row failed with an operator message and the technical error apart.
 */
class BuildEmployeeReports implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const CHUNK = 6;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $exportId, public int $offset = 0) {}

    public function handle(PersonalReport $reports, PdfRenderer $pdf, NotificationService $notifications, MqttPublisher $mqtt): void
    {
        $export = ReportExport::withoutGlobalScopes()->find($this->exportId);
        if ($export === null || $export->isFinished()) {
            return;
        }

        // No request, so no resolved tenant: restore it from the row (rule 11).
        Tenancy::set($export->tenant_id);
        $tenantName = Tenant::query()->whereKey($export->tenant_id)->value('name');
        $params = $export->params;

        $ids = array_slice($params['employee_ids'], $this->offset, self::CHUNK);
        $employees = Employee::query()->whereIn('id', $ids)->get()->sortBy(fn ($e) => array_search($e->id, $ids, true))->values();
        $manifest = $export->manifest ?? [];

        foreach ($reports->build($employees, $params['from'], $params['to']) as $p) {
            $seq = count($manifest) + 1;
            $line = [
                'name' => $p['employee']['name'],
                'employee_code' => $p['employee']['employee_code'],
                'department' => $p['employee']['department'] ?? 'Unassigned',
                'stats' => array_intersect_key($p['stats'], array_flip(['attendance_rate', 'on_time_rate', 'late_arrivals', 'missed_punch_outs', 'present_days', 'expected_days', 'payroll_hours'])),
                'absent' => $p['stats']['outcomes']['absent'],
            ];

            // Only DRAWING one person may fail alone. A disk that will not take the file
            // is the export's failure, not 132 people's — so the write is outside.
            try {
                $doc = $pdf->make('pdf.report.employee', [
                    'title' => 'Employee report · '.$p['employee']['name'],
                    'rangeLabel' => self::range($params['from'], $params['to']),
                    'p' => $p,
                ], $tenantName);
                $bytes = $doc->output();
                $pages = $pdf->pageCount($doc);
            } catch (\Throwable $e) {
                Log::warning('Employee report did not render', ['export' => $export->id, 'employee' => $p['employee']['id'], 'error' => $e->getMessage()]);
                $manifest[] = $line + ['file' => null, 'pages' => 0, 'failed' => 'Could not be drawn: '.Str::limit($e->getMessage(), 160)];

                continue;
            } finally {
                unset($doc);
            }

            $path = sprintf('%s/part-%04d.pdf', $export->directory(), $seq);
            if (! Storage::disk('local')->put($path, $bytes)) {
                throw new \RuntimeException("Could not write {$path} to the local disk.");
            }
            $manifest[] = $line + ['file' => $path, 'pages' => $pages];
        }

        $done = min($this->offset + self::CHUNK, $export->total);
        $export->forceFill([
            'status' => ReportExport::STATUS_RUNNING,
            'done' => $done,
            'manifest' => $manifest,
            'message' => "Drawing reports: {$done} of {$export->total}.",
        ])->save();
        $this->publish($mqtt, $export);

        if ($done < $export->total) {
            self::dispatch($export->id, $done);

            return;
        }

        $this->finish($export, $pdf, $tenantName);
        $this->publish($mqtt, $export);
        $this->tell($notifications, $export, true);
    }

    /** Contents page first, then every person's file, joined into one PDF. */
    private function finish(ReportExport $export, PdfRenderer $pdf, ?string $tenantName): void
    {
        $export->forceFill(['message' => 'Joining the reports into one file.'])->save();
        $params = $export->params;
        $disk = Storage::disk('local');
        $rendered = array_values(array_filter($export->manifest, fn ($m) => $m['file'] !== null));

        // Two passes: the contents page's own length moves every page number after it.
        $contentsPages = 1;
        for ($pass = 0; $pass < 3; $pass++) {
            $page = $contentsPages + 1;
            $rows = array_map(function ($m) use (&$page) {
                $m['page'] = $m['file'] !== null ? $page : null;
                $page += $m['pages'];

                return $m;
            }, $export->manifest);

            $doc = $pdf->make('pdf.report.contents', [
                'title' => 'Employee reports',
                'rangeLabel' => self::range($params['from'], $params['to']),
                'params' => $params,
                'rows' => $rows,
            ], $tenantName);

            if ($pdf->pageCount($doc) === $contentsPages) {
                break;
            }
            $contentsPages = $pdf->pageCount($doc);
        }

        $contentsPath = $export->directory().'/contents.pdf';
        $disk->put($contentsPath, $doc->output());

        $filename = implode('_', array_filter([
            'employee-reports', $params['from'], $params['to'],
            $params['scope'] ? Str::slug(Str::afterLast($params['scope'], '›')) : null,
        ])).'.pdf';
        $outPath = $export->directory().'/'.$filename;

        $process = new Process(['pdfunite', $disk->path($contentsPath), ...array_map(fn ($m) => $disk->path($m['file']), $rendered), $disk->path($outPath)]);
        $process->setTimeout(600)->mustRun();

        foreach ($rendered as $m) {
            $disk->delete($m['file']);
        }
        $disk->delete($contentsPath);

        $skipped = count($export->manifest) - count($rendered);
        $export->forceFill([
            'status' => ReportExport::STATUS_DONE,
            'file_path' => $outPath,
            'filename' => $filename,
            'file_size' => $disk->size($outPath),
            'finished_at' => now(),
            'message' => count($rendered).' reports ready'.($skipped ? ", {$skipped} could not be drawn (named on the contents page)" : '').'.',
        ])->save();
    }

    public function failed(\Throwable $e): void
    {
        $export = ReportExport::withoutGlobalScopes()->find($this->exportId);
        if ($export === null) {
            return;
        }

        Tenancy::set($export->tenant_id);
        $export->forceFill([
            'status' => ReportExport::STATUS_FAILED,
            'finished_at' => now(),
            'message' => "The export stopped after {$export->done} of {$export->total} reports. Start it again; if it stops again, the technical detail is recorded.",
            'error' => Str::limit($e->getMessage(), 2000),
        ])->save();

        $this->publish(app(MqttPublisher::class), $export);
        $this->tell(app(NotificationService::class), $export, false);
    }

    /** Best-effort live update for open Reports screens (rule 18); never fails the job. */
    private function publish(MqttPublisher $mqtt, ReportExport $export): void
    {
        try {
            $mqtt->publish(TopicBuilder::module($export->tenant_id, 'reports', $export->id), [
                'id' => $export->id, 'status' => $export->status, 'done' => $export->done, 'total' => $export->total,
            ]);
        } catch (\Throwable) {
            // MQTT enhances; the row and /api/live carry the truth.
        }
    }

    private function tell(NotificationService $notifications, ReportExport $export, bool $ok): void
    {
        $user = $export->user_id ? User::withoutGlobalScopes()->find($export->user_id) : null;
        if ($user === null) {
            return;
        }

        $params = $export->params;
        $notifications->notify($user, [
            'type' => 'report_export',
            'level' => $ok ? 'success' : 'error',
            'title' => $ok ? 'Employee reports are ready' : 'Employee reports export failed',
            'body' => self::range($params['from'], $params['to']).' — '.$export->message.($ok ? ' Download it from the Reports page.' : ''),
            'data' => ['report_export_id' => $export->id],
        ]);
    }

    private static function range(string $from, string $to): string
    {
        return Carbon::parse($from)->format('j M Y').' – '.Carbon::parse($to)->format('j M Y');
    }
}
