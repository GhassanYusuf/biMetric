<?php

namespace App\Services\Reports;

use DateTimeImmutable;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The Monthly Statement as an Excel workbook (owner, 2026-10-19: "all employees PDF or
 * all employees Excel" from the Reports screen).
 *
 * Same figures as the PDF: it is handed MonthlyStatement::build()'s output and adds no
 * arithmetic of its own. Same layout as the ZKTeco export the company already files
 * (draft/OTR-SEP.xlsx) — a block per employee on one sheet — plus a Summary sheet with
 * one row of totals per person, which is what payroll actually types from.
 *
 * Unlike ZKTeco's file, values are real Excel values, not text: dates are dates, punches
 * are times, durations are [h]:mm. So a column can be summed, filtered or re-formatted
 * without retyping, and a total over 24 hours stays "199:12" instead of wrapping.
 *
 * Written with OpenSpout, which streams rows to disk: a whole company (235 people,
 * ~8,500 rows) stays within the PHP memory limit, which PhpSpreadsheet would not.
 */
class MonthlyStatementWorkbook
{
    private const HEADINGS = ['Date', 'Week', 'Timetable', 'In', 'Out', 'In', 'Out', 'Break', 'Late', 'Early', 'Work', 'Absent', 'OT1', 'OT2', 'OT3', 'Exception', 'Holiday'];

    private const LABELS = ['break' => 'Break', 'late' => 'Late', 'early' => 'Early', 'work' => 'Work', 'absent' => 'Absent', 'ot1' => 'OT1', 'ot2' => 'OT2', 'ot3' => 'OT3', 'exception' => 'Exception', 'holiday' => 'Holiday'];

    /** @param  array  $statement  MonthlyStatement::build() */
    public function write(array $statement, ?string $company, string $path, string $generatedAt): void
    {
        $writer = new Writer(new Options());
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet()->setName('Statement');
        $sheet->setColumnWidth(12, 1);
        $sheet->setColumnWidth(8, 2);
        $sheet->setColumnWidth(14, 3);
        $sheet->setColumnWidthForRange(8, 4, 17);

        $bold = (new Style())->setFontBold();
        $title = (new Style())->setFontBold()->setFontSize(14);
        $head = (new Style())->setFontBold()->setBackgroundColor('E2E8F0')->setCellAlignment(CellAlignment::CENTER)
            ->setBorder(new Border(new BorderPart(Border::BOTTOM, '94A3B8', Border::WIDTH_THIN)));
        $date = (new Style())->setFormat('dd/mm/yyyy');
        $clock = (new Style())->setFormat('hh:mm')->setCellAlignment(CellAlignment::CENTER);
        $duration = (new Style())->setFormat('[h]:mm')->setCellAlignment(CellAlignment::CENTER);
        $total = (new Style())->setFontBold()->setFormat('[h]:mm')->setCellAlignment(CellAlignment::CENTER)
            ->setBorder(new Border(new BorderPart(Border::TOP, '64748B', Border::WIDTH_THIN)));
        $flag = (new Style())->setFontColor('B45309')->setFontBold()->setCellAlignment(CellAlignment::CENTER);

        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $statement['from']);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', $statement['to']);
        $shift = $statement['shift'];

        $writer->addRow(Row::fromValues([$company ?: 'Company'], $bold));
        $writer->addRow(Row::fromValues(['Monthly Statement Report'], $title));
        $writer->addRow(Row::fromValues(['From '.$from->format('d/m/Y').' To '.$to->format('d/m/Y')]));
        $writer->addRow(Row::fromValues([
            "Shift {$shift['start']}-{$shift['end']}. Late after {$shift['grace']} min; Left early more than "
            ."{$shift['early_leave_grace']} min before the end; OT1 once {$shift['leave_late_after']} min past it. "
            .'OT2 is time worked on a day off. "None" under Out: one punch only, not counted as worked.',
        ]));
        $writer->addRow(Row::fromValues([]));

        $durationCols = array_fill_keys(range(7, 16), $duration);

        foreach ($statement['employees'] as $block) {
            $e = $block['employee'];
            $writer->addRow(Row::fromValues(['User ID', (string) $e['employee_code'], 'Full Name', $e['name'], '', '', '', '', 'Department', $e['department'] ?? ''], $bold));
            $writer->addRow(Row::fromValues(self::HEADINGS, $head));

            foreach ($block['rows'] as $r) {
                $values = [
                    DateTimeImmutable::createFromFormat('!d/m/Y', $r['date']),
                    $r['week'],
                    $r['timetable'],
                    self::dayFraction($r['in']),
                    $r['missed_punch_out'] ? 'None' : self::dayFraction($r['out']),
                    null,
                    null,
                ];
                foreach (MonthlyStatement::DURATIONS as $col) {
                    $values[] = $r[$col] / 1440;
                }

                $writer->addRow(Row::fromValuesWithStyles($values, null, [
                    0 => $date, 3 => $clock, 4 => $r['missed_punch_out'] ? $flag : $clock, ...$durationCols,
                ]));
            }

            $totals = ['Total', null, null, null, null, null, null];
            foreach (MonthlyStatement::DURATIONS as $col) {
                $totals[] = $block['totals'][$col] / 1440;
            }
            $writer->addRow(Row::fromValuesWithStyles($totals, null, array_fill_keys(range(0, 16), $total)));
            $writer->addRow(Row::fromValues([]));
        }

        $writer->addRow(Row::fromValues(['Checked By', '', '', '', '', '', '', '', '', 'Approved By'], $bold));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(["Date/Time: {$generatedAt}"]));

        // One row per person: the totals payroll works from, without scrolling every block.
        $summary = $writer->addNewSheetAndMakeItCurrent()->setName('Summary');
        $summary->setColumnWidth(12, 1);
        $summary->setColumnWidth(30, 2);
        $summary->setColumnWidth(20, 3);
        $summary->setColumnWidthForRange(10, 4, 13);
        $writer->addRow(Row::fromValues(['User ID', 'Full Name', 'Department', ...array_values(self::LABELS)], $head));
        foreach ($statement['employees'] as $block) {
            $values = [(string) $block['employee']['employee_code'], $block['employee']['name'], $block['employee']['department'] ?? ''];
            foreach (array_keys(self::LABELS) as $col) {
                $values[] = $block['totals'][$col] / 1440;
            }
            $writer->addRow(Row::fromValuesWithStyles($values, null, array_fill_keys(range(3, 12), $duration)));
        }

        $writer->close();
    }

    /** "07:48" → the fraction of a day Excel stores a time as; null stays empty. */
    private static function dayFraction(?string $hhmm): ?float
    {
        if ($hhmm === null) {
            return null;
        }
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return ($h * 60 + $m) / 1440;
    }
}
