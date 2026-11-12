<?php

namespace Modules\ReportModule\app\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

// Report 7 Excel: the page's table — a column per month + Total; Income, Expenses, Net profit, Margin, advances below the line
class PnlExport implements FromArray, WithEvents, WithTitle, ShouldAutoSize
{
    private array $boldRows = [1, 2];

    // $cmpRange (2026-11-12): the comparison period → two more columns (its totals + the change)
    public function __construct(private array $data, private string $range, private ?string $cmpRange = null)
    {
    }

    private function extra(array $row): array
    {
        if (!$this->cmpRange) {
            return [];
        }
        $compare = (float) ($row['compare'] ?? 0);
        return [$compare, round((float) $row['total'] - $compare, 2)];
    }

    public function title(): string
    {
        return 'Profit & Loss';
    }

    public function array(): array
    {
        $line = fn ($label, $row) => array_merge([$label], array_values($row['months']), [$row['total']], $this->extra($row));
        $section = function ($label) use (&$out) {
            $out[] = [$label];
            $this->boldRows[] = count($out);
        };
        $total = function ($label, $row) use (&$out, $line) {
            $out[] = $line($label, $row);
            $this->boldRows[] = count($out);
        };
        $totals = $this->data['totals'];
        $out = [['Profit & Loss · ' . $this->range . ($this->cmpRange ? ' vs ' . $this->cmpRange : '') . ' · cash basis, income net before tax'],
            array_merge([''], array_values($this->data['months']), ['Total'], $this->cmpRange ? [$this->cmpRange, 'Change'] : [])];

        $section('Income');
        foreach ($this->data['income'] as $row) {
            $out[] = $line($row['label'], $row);
        }
        $total('Total income', $totals['income']);
        $section('Expenses');
        foreach ($this->data['expenses'] as $row) {
            $out[] = $line($row['label'], $row);
        }
        $total('Total expenses', $totals['expenses']);
        $total('Net profit', $totals['net']);
        $margin = $totals['margin'];
        $out[] = array_merge(['Margin'], array_map(fn ($ym) => $margin[$ym] === null ? null : $margin[$ym] / 100, array_keys($this->data['months'])),
            [$margin['total'] === null ? null : $margin['total'] / 100],
            $this->cmpRange ? [($margin['compare'] ?? null) === null ? null : $margin['compare'] / 100] : []);
        $this->marginRow = count($out);
        $out[] = [];
        $section('Below the line · advances (not in the profit)');
        foreach ($this->data['below'] as $row) {
            $out[] = $line($row['label'], $row);
        }

        return $out;
    }

    private int $marginRow = 0;

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            $last = Coordinate::stringFromColumnIndex(count($this->data['months']) + 2 + ($this->cmpRange ? 2 : 0));
            foreach ($this->boldRows as $row) {
                $sheet->getStyle('A' . $row . ':' . $last . $row)->getFont()->setBold(true);
            }
            $sheet->getStyle('B3:' . $last . $sheet->getHighestRow())->getNumberFormat()->setFormatCode('#,##0.00');
            if ($this->marginRow) {
                $sheet->getStyle('B' . $this->marginRow . ':' . $last . $this->marginRow)->getNumberFormat()->setFormatCode('0.0%');
            }
            $sheet->getStyle($last . '2:' . $last . $sheet->getHighestRow())->getFont()->setBold(true);
            $sheet->freezePane('B3');
        }];
    }
}
