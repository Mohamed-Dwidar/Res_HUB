<?php

namespace Modules\ReportModule\app\Exports;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

// Report 1 Excel (docs/reports-plan.md §1): one row per member (the same query as the page, chunked) + the totals row
class OutstandingExport implements FromQuery, WithHeadings, WithMapping, WithColumnFormatting, WithEvents, WithTitle, ShouldAutoSize
{
    public function __construct(private $query, private object $totals)
    {
    }

    public function query()
    {
        return $this->query->orderByDesc('owed')->orderBy('member_id');
    }

    public function title(): string
    {
        return 'Outstanding & credits';
    }

    public function headings(): array
    {
        return ['Member', 'Phone', 'Company', 'Owed', 'Credit', 'Net', 'Periods', 'Oldest due since'];
    }

    public function map($row): array
    {
        return [
            $row->member_id ? $row->member_name : 'No member',
            $row->phone,
            $row->company,
            (float) $row->owed,
            (float) $row->credit,
            (float) $row->net,
            (int) $row->periods,
            $row->oldest ? Carbon::parse($row->oldest)->format('d-m-Y') : '',
        ];
    }

    public function columnFormats(): array
    {
        return ['D' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, 'E' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'F' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->getStyle('A1:H1')->getFont()->setBold(true);
                $row = $sheet->getHighestRow() + 1;
                $sheet->fromArray([['Total (' . $this->totals->members . ' members)', '', '', (float) $this->totals->owed,
                    (float) $this->totals->credit, (float) $this->totals->net, (int) $this->totals->periods, '']], null, 'A' . $row);
                $sheet->getStyle('A' . $row . ':H' . $row)->getFont()->setBold(true);
                $sheet->getStyle('D' . $row . ':F' . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                $sheet->freezePane('A2');
            },
        ];
    }
}
