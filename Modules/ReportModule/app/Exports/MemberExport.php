<?php

namespace Modules\ReportModule\app\Exports;

use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\ReportModule\app\Services\MemberReport;
use Modules\ReservationModule\app\Models\Reservation;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

// Report 8 Excel: the chosen tab's rows (the page's query, chunked) + the totals row; By occupation = one row per occupation
class MemberExport implements FromQuery, WithHeadings, WithMapping, WithColumnFormatting, WithEvents, WithTitle, ShouldAutoSize
{
    public function __construct(private $query, private object $totals, private string $view, private string $range)
    {
    }

    public function query()
    {
        return $this->query;
    }

    public function title(): string
    {
        return 'Members';
    }

    private function occupations(): bool
    {
        return $this->view === 'occupation';
    }

    public function headings(): array
    {
        return [['Members · ' . MemberReport::TABS[$this->view] . ' · ' . $this->range], $this->occupations()
            ? ['Occupation', 'Members', 'New members', 'Paid']
            : ['No.', 'Member', 'Phone', 'Reservations', 'Commitment %', 'First visit', 'Last visit', 'Paid', 'Balance']];
    }

    public function map($row): array
    {
        if ($this->occupations()) {
            return [$row->occupation, (int) $row->members, (int) $row->new_members, (float) $row->paid];
        }
        $date = fn ($value) => $value ? Carbon::parse($value)->format(Reservation::DATE_FORMAT) : null;
        return [$row->member_id, $row->name, $row->phone, (int) $row->reservations, $row->commitment === null ? null : (int) $row->commitment,
            $date($row->first_visit), $date($row->last_visit), (float) $row->paid, (float) $row->balance];
    }

    public function columnFormats(): array
    {
        return $this->occupations() ? ['D' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1]
            : ['H' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, 'I' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1];
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            $last = $this->occupations() ? 'D' : 'I';
            $sheet->getStyle('A1:' . $last . '2')->getFont()->setBold(true);
            $row = $sheet->getHighestRow() + 1;
            $sheet->fromArray([$this->occupations()
                ? ['Total', (int) $this->totals->members, (int) $this->totals->new_members, $this->totals->paid]
                : ['Total (' . (int) $this->totals->members . ')', '', '', '', '', '', '', $this->totals->paid, $this->totals->balance]], null, 'A' . $row);
            $sheet->getStyle('A' . $row . ':' . $last . $row)->getFont()->setBold(true);
            $sheet->getStyle(($this->occupations() ? 'D' : 'H') . $row . ':' . $last . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
            $sheet->freezePane('A3');
        }];
    }
}
