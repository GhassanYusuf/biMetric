<?php

namespace App\Services\Reports;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The employee list as an Excel workbook (owner, 2026-10-21), written from
 * EmployeeRoster::build() exactly as the PDF is, so the two never disagree.
 *
 * One column per terminal rather than the PDF's single "Terminals" cell, so a sheet
 * can be filtered by "who is on Entrance". The cell is the fingerprint count that
 * terminal holds, "?" when it was never read or would not decode (rule 10: never a
 * 0 that was not read), and blank when the person is not on that terminal at all.
 * Codes and card numbers are text, so Excel keeps leading zeros and does not turn a
 * ten-digit card into 1.23E+09.
 */
class EmployeeRosterWorkbook
{
    /** @param  array  $roster  EmployeeRoster::build() */
    public function write(array $roster, ?string $company, string $path, string $generatedAt): void
    {
        $terminals = collect($roster['rows'])
            ->flatMap(fn (array $r) => array_column($r['terminals'], 'name'))
            ->unique()->sort()->values()->all();

        $writer = new Writer(new Options());
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet()->setName('Employees');
        $sheet->setColumnWidth(6, 1);
        $sheet->setColumnWidth(10, 2);
        $sheet->setColumnWidth(30, 3);
        $sheet->setColumnWidthForRange(22, 4, 6);
        $sheet->setColumnWidth(18, 7);
        $sheet->setColumnWidth(16, 8);
        if ($terminals !== []) {
            $sheet->setColumnWidthForRange(14, 9, 8 + count($terminals));
        }

        $bold = (new Style())->setFontBold();
        $title = (new Style())->setFontBold()->setFontSize(14);
        $head = (new Style())->setFontBold()->setBackgroundColor('E2E8F0')
            ->setBorder(new Border(new BorderPart(Border::BOTTOM, '94A3B8', Border::WIDTH_THIN)));
        $unknown = (new Style())->setFontColor('B45309');

        $writer->addRow(Row::fromValues([$company ?: 'Company'], $bold));
        $writer->addRow(Row::fromValues(['Employees'], $title));
        $writer->addRow(Row::fromValues([implode(' · ', $roster['filterLines'])]));
        $writer->addRow(Row::fromValues([
            'Under each terminal: fingerprints it holds for the person, as last read. "?" = not read or not readable (not the same as none); blank = not on that terminal; "+ card" = it holds a card for them.',
        ]));
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues(['No.', 'Code', 'Name', 'Company', 'Department', 'Job title', 'Status', 'Card number', ...$terminals], $head));

        foreach ($roster['rows'] as $r) {
            $byName = array_column($r['terminals'], null, 'name');
            $values = [$r['no'], (string) ($r['code'] ?? ''), $r['name'], $r['company'] ?? '', $r['department'] ?? '', $r['job_title'] ?? '', $r['status'], (string) ($r['card'] ?? '')];
            $styles = [];
            foreach ($terminals as $i => $name) {
                $t = $byName[$name] ?? null;
                if ($t === null) {
                    $values[] = '';
                    continue;
                }
                if ($t['fingers'] === null) {
                    $styles[8 + $i] = $unknown;
                }
                $count = $t['fingers'] ?? '?';
                $values[] = $t['card'] ? "{$count} + card" : $count;
            }
            $writer->addRow(Row::fromValuesWithStyles($values, null, $styles));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues([count($roster['rows']).' employees'], $bold));
        $writer->addRow(Row::fromValues(["Generated {$generatedAt}"]));

        $writer->close();
    }
}
