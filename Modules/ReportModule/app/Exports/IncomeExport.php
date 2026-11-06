<?php

namespace Modules\ReportModule\app\Exports;

use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

// Report 3 Excel (docs/reports-plan.md §3): one row per group (the page's query, in the group's order) + the totals row
class IncomeExport implements FromQuery, WithHeadings, WithMapping, WithColumnFormatting, WithEvents, WithTitle, ShouldAutoSize
{
    public function __construct(private $query, private object $totals, private string $groupTitle, private string $range)
    {
    }

    public function query()
    {
        return $this->query->orderBy('group_sort')->orderBy('group_key');
    }

    public function title(): string
    {
        return 'Income';
    }

    public function headings(): array
    {
        return [[$this->groupTitle . ' · ' . $this->range], [$this->groupTitle, 'Payments', 'Cash In', 'Refunds', 'Withholding', 'Tax', 'Net (before tax)', 'Total']];
    }

    public function map($row): array
    {
        return [$row->group_label, (int) $row->payments, (float) $row->cash_in, (float) $row->refunds, (float) $row->withholding,
            (float) $row->tax, (float) $row->net, (float) $row->total];
    }

    public function columnFormats(): array
    {
        return array_fill_keys(['C', 'D', 'E', 'F', 'G', 'H'], NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->getStyle('A1:H2')->getFont()->setBold(true);
                $row = $sheet->getHighestRow() + 1;
                $t = $this->totals;
                $sheet->fromArray([['Total', (int) $t->payments, $t->cash_in, $t->refunds, $t->withholding, $t->tax, $t->net, $t->total]], null, 'A' . $row);
                $sheet->getStyle('A' . $row . ':H' . $row)->getFont()->setBold(true);
                $sheet->getStyle('C' . $row . ':H' . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                $sheet->freezePane('A3');
            },
        ];
    }
}
