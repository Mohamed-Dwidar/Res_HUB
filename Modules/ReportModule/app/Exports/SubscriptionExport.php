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
use Modules\ReservationModule\app\Models\Reservation;
use Modules\ReservationModule\app\Services\RenewalView;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

// Report 5 Excel (docs/reports-plan.md §5): the chosen tab's rows (the page's query, chunked) + the totals row
class SubscriptionExport implements FromQuery, WithHeadings, WithMapping, WithColumnFormatting, WithEvents, WithTitle, ShouldAutoSize
{
    private Carbon $today;

    public function __construct(private $query, private object $totals, private string $tab, private string $range, private string $kind)
    {
        $this->today = Reservation::businessToday()[0];
    }

    public function query()
    {
        return $this->query;
    }

    public function title(): string
    {
        return 'Subscriptions';
    }

    public function headings(): array
    {
        return [['Subscriptions · ' . $this->tab . ' · ' . $this->range], ['No.', 'Member', 'Phone', 'Space › Unit', 'Plan', 'From', 'To', 'Renewal date', 'Amount', 'Remaining', 'State', 'Renewal increase', 'Next renewal price']];
    }

    public function map($row): array
    {
        $view = RenewalView::make($row, $this->kind, $this->today);
        $state = $view['check'] ?? ($view['badge'][0] ?? collect($view['badges'])->pluck(0)->implode(' · '));
        $format = Reservation::DATE_FORMAT;
        return [$row->id, $row->member?->name, $row->member?->phone, ($row->space?->name ?? '-') . ' › ' . ($row->unit?->name ?? '-'), $row->plan?->name,
            $row->start_at?->format($format), $row->realEnd()?->format($format), $view['date']?->format($format), (float) $row->dueAmount(),
            $row->canTakePayments() ? (float) $row->remaining : null, trim($state . ($view['due'] ? ' · ' . $view['due'] : '')),
            // the renewal increase (2026-11-12): the next price before discount while not renewed
            $row->renewalIncreaseLabel(), $row->renewedBy ? null : $row->nextRenewalPrice()];
    }

    public function columnFormats(): array
    {
        return ['I' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, 'J' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, 'M' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1];
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            $sheet->getStyle('A1:M2')->getFont()->setBold(true);
            $row = $sheet->getHighestRow() + 1;
            $sheet->fromArray([['Total (' . (int) $this->totals->count . ')', '', '', '', '', '', '', '', $this->totals->amount, $this->totals->owed, '', '', '']], null, 'A' . $row);
            $sheet->getStyle('A' . $row . ':M' . $row)->getFont()->setBold(true);
            $sheet->getStyle('I' . $row . ':J' . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
            $sheet->freezePane('A3');
        }];
    }
}
