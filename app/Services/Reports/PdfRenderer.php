<?php

namespace App\Services\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * Renders a PDF export: one place for the page furniture every export shares.
 *
 * Moved out of ExportController on 2026-10-18 because a queued job
 * (BuildEmployeeReports) renders the same documents with no request and no
 * signed-in user — so the tenant's name is passed in, never read from auth().
 */
class PdfRenderer
{
    /**
     * Where each layout's footer text sits, in points: its top measured from the
     * page's bottom edge, the right margin, and the footer's font size. Derived from
     * the @page margins and footer boxes in pdf.layout and pdf.report.layout — change
     * those and these move with them.
     */
    private const FOOTER_PLAIN = ['bottom' => 37.5, 'right' => 24.0, 'size' => 6.0];

    private const FOOTER_REPORT = ['bottom' => 25.5, 'right' => 27.0, 'size' => 5.6];

    /** A laid-out, page-numbered A4 document; landscape for a list wider than portrait holds. */
    public function make(string $view, array $data, ?string $tenantName, string $orientation = 'portrait'): DomPdf
    {
        $tz = config('attendance.timezone');

        $pdf = Pdf::loadView($view, [
            ...$data,
            'tenantName' => $tenantName,
            'timezone' => $tz,
            'generatedAt' => now($tz)->format('Y-m-d g:i A'),
        ])->setPaper('a4', $orientation)
            // Embed only the glyphs used: DejaVu whole is ~800 KB per PDF.
            ->setOption('isFontSubsettingEnabled', true);

        $this->numberPages($pdf, str_starts_with($view, 'pdf.report.') ? self::FOOTER_REPORT : self::FOOTER_PLAIN);

        return $pdf;
    }

    public function pageCount(DomPdf $pdf): int
    {
        return $pdf->getDomPDF()->getCanvas()->get_page_count();
    }

    /**
     * "Page N of M" in the footer's right corner, on every page.
     *
     * Drawn on the canvas after layout because dompdf never resolves CSS
     * counter(pages) — every export printed "Page 1 of 0" until 2026-10-18.
     *
     * @param  array{bottom:float, right:float, size:float}  $at
     */
    private function numberPages(DomPdf $pdf, array $at): void
    {
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont('DejaVu Sans');
        // Measured with two-digit numbers so the right edge stays inside the margin.
        $width = $metrics->getTextWidth('Page 00 of 00', $font, $at['size']);

        $canvas->page_text($canvas->get_width() - $at['right'] - $width, $canvas->get_height() - $at['bottom'],
            'Page {PAGE_NUM} of {PAGE_COUNT}', $font, $at['size'], [0.278, 0.333, 0.412]);
    }
}
