<?php

namespace Modules\ReportModule\app\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\ReportModule\app\Services\OccupancyReport;

// Report 6 Excel — Performance (was Occupancy, 2026-11-18): the page's table — by space, its units, its subtotals per mode, the
// overall totals; occupancy columns then the money (Standalone, From packages, Revenue [+ compared + change];
// Remaining / Per booked hour removed 2026-11-18)
class OccupancyExport implements FromArray, WithEvents, WithTitle, ShouldAutoSize
{
    private array $boldRows = [1, 2];

    public function __construct(private array $data, private string $range, private ?string $cmpRange = null)
    {
    }

    public function title(): string
    {
        return 'Performance';
    }

    public function array(): array
    {
        $modes = OccupancyReport::MODES;
        $line = fn ($label, $row) => [$label, $modes[$row['mode']], $row['capacity'], $row['reservations'], $row['occupied'], $row['free'],
            $row['booked'], $row['available'], ['hourly' => 'hours', 'full_day' => 'days', 'period' => 'seats'][$row['mode']],
            $row['percent'] === null ? null : $row['percent'] / 100, $row['free_today'], $row['pending'] ?? 0,
            $row['standalone'], $row['packaged'], $row['revenue'],
            ...($this->cmpRange ? [$row['cmp_revenue'] ?? 0, round($row['revenue'] - ($row['cmp_revenue'] ?? 0), 2)] : []),
            $row['excluded'] ?? null];
        $out = [['Performance · ' . $this->range . ($this->cmpRange ? ' vs ' . $this->cmpRange : '') . ' · ' . $this->data['days'] . ' days · '
                . $this->data['hours'] . ' business hours a day · Revenue = value of bookings in the period, not money received (see Income)'],
            ['Space › Unit', 'Mode', 'Capacity', 'Reservations', 'Avg occupied', 'Avg free', 'Booked', 'Available', 'In', '%', 'Free today', 'Renewal pending',
                'Standalone', 'From packages', 'Revenue', ...($this->cmpRange ? ['Revenue · ' . $this->cmpRange, 'Change'] : []),
                'Units left out of the %']];
        foreach ($this->data['spaces'] as $space) {
            foreach ($space['units'] as $row) {
                        $out[] = $line($space['space'] . ' › ' . $row['unit'] . (!empty($row['inactive']) ? ' (inactive)' : ''), $row);
            }
            foreach ($space['totals'] as $total) {
                $out[] = $line($space['space'] . ' total', $total);
                $this->boldRows[] = count($out);
            }
        }
        foreach ($this->data['totals'] as $total) {
            $out[] = $line('All spaces', $total);
            $this->boldRows[] = count($out);
        }
        // the grand revenue (all modes)
        $grand = $this->data['grand'];
        $out[] = ['Revenue (all modes)', null, null, null, null, null, null, null, null, null, null, null, $grand['standalone'], $grand['packaged'], $grand['revenue'],
            ...($this->cmpRange ? [$this->data['cmp_grand']['revenue'] ?? 0, round($grand['revenue'] - ($this->data['cmp_grand']['revenue'] ?? 0), 2)] : []),
            null];
        $this->boldRows[] = count($out);

        return $out;
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            foreach ($this->boldRows as $row) {
                $sheet->getStyle('A' . $row . ':' . $sheet->getHighestColumn() . $row)->getFont()->setBold(true);
            }
            $sheet->getStyle('J3:J' . $sheet->getHighestRow())->getNumberFormat()->setFormatCode('0.0%');
            $sheet->getStyle('M3:' . $sheet->getHighestColumn() . $sheet->getHighestRow())->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->freezePane('A3');
        }];
    }
}
