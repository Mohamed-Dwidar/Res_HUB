<?php

namespace Modules\ReportModule\app\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\ReportModule\app\Services\MemberStatementReport;
use Modules\ReservationModule\app\Models\Reservation;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

// Report 2 Excel (docs/reports-plan.md §2): one member's statement (already read: one member's lines) — header lines, opening,
// the lines with the running balance, closing
class MemberStatementExport implements FromArray, WithColumnFormatting, WithEvents, WithTitle, ShouldAutoSize
{
    private int $headRow = 5;

    public function __construct(private array $statement)
    {
    }

    public function title(): string
    {
        return 'Member statement';
    }

    public function array(): array
    {
        $s = $this->statement;
        $format = Reservation::DATE_FORMAT;
        $rows = [
            ['Member statement', $s['member']->name, $s['member']->phone],
            ['Period', ($s['from'] ? $s['from']->format($format) : 'from the start') . ' → ' . ($s['to'] ? $s['to']->format($format) : 'today and later')],
            ['Printed', now()->format($format . ' ' . Reservation::TIME_FORMAT)],
            [],
            ['Date', 'Type', 'Description', 'Charge', 'Payment', 'Balance'],
            ['', 'Opening balance', '', null, null, $s['opening']],
        ];
        foreach ($s['lines'] as $line) {
            [$type, $text] = MemberStatementReport::describe($line);
            $rows[] = [$line['day']->format($format), $type, $text,
                $line['kind'] !== 'payment' ? $line['charge'] : null, $line['kind'] === 'payment' ? $line['payment'] : null, $line['balance']];
        }
        $rows[] = ['', 'Closing balance', count($s['lines']) . ' lines', $s['charges'], $s['payments'], $s['closing']];

        return $rows;
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
                $last = $sheet->getHighestRow();
                $sheet->getStyle('A1')->getFont()->setBold(true);
                foreach ([$this->headRow, $this->headRow + 1, $last] as $row) {
                    $sheet->getStyle('A' . $row . ':F' . $row)->getFont()->setBold(true);
                }
                $sheet->freezePane('A' . ($this->headRow + 1));
            },
        ];
    }
}
