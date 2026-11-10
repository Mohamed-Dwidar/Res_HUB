<?php

namespace Modules\PaymentModule\app\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

// payments:clean-overpayments group 3 (no change): one row per reservation / package with a credit, its payments, a live link
class OverpaymentsExport implements FromArray, WithHeadings, WithTitle, WithColumnFormatting, WithColumnWidths, WithEvents, WithStrictNullComparison
{
    // rows: member, phone, No., due, paid, credit, why, payments (one per line), url
    public function __construct(private array $rows)
    {
    }

    public function title(): string
    {
        return 'Credits to review';
    }

    public function headings(): array
    {
        return ['Member', 'Phone', 'Reservation / Package', 'Due', 'Paid', 'Credit', 'Why not fixed', 'Payments (No. · date · amount)', 'Open'];
    }

    public function array(): array
    {
        return array_map(fn ($row) => array_slice($row, 0, 8) + [8 => 'Open'], $this->rows);
    }

    public function columnFormats(): array
    {
        return ['B' => NumberFormat::FORMAT_TEXT, 'D' => '#,##0.00', 'E' => '#,##0.00', 'F' => '#,##0.00'];
    }

    public function columnWidths(): array
    {
        return ['A' => 30, 'B' => 16, 'C' => 20, 'D' => 12, 'E' => 12, 'F' => 12, 'G' => 30, 'H' => 42, 'I' => 10];
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            $sheet->getStyle('A1:I1')->getFont()->setBold(true);
            $sheet->freezePane('A2');
            foreach ($this->rows as $i => $row) {
                $line = $i + 2;
                $sheet->getCell('B' . $line)->setValueExplicit((string) $row[1], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->getStyle('H' . $line)->getAlignment()->setWrapText(true);
                $sheet->getCell('I' . $line)->getHyperlink()->setUrl($row[8]);
                $sheet->getStyle('I' . $line)->getFont()->setUnderline(true)->getColor()->setARGB('FF0563C1');
            }
            $sheet->getStyle('A2:I' . (count($this->rows) + 1))->getAlignment()->setVertical('top');
        }];
    }
}
