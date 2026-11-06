<?php

namespace Modules\ReportModule\app\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\ReportModule\app\Services\OccupancyReport;

// Report 6 Excel (docs/reports-plan.md §6): the page's table — by space, its units, its subtotals per mode, the overall totals
class OccupancyExport implements FromArray, WithEvents, WithTitle, ShouldAutoSize
{
    private array $boldRows = [1, 2];

    public function __construct(private array $data, private string $range)
    {
    }

    public function title(): string
    {
        return 'Occupancy';
    }

    public function array(): array
    {
        $modes = OccupancyReport::MODES;
        $line = fn ($label, $row) => [$label, $modes[$row['mode']], $row['capacity'], $row['reservations'], $row['occupied'], $row['free'],
            $row['booked'], $row['available'], ['hourly' => 'hours', 'full_day' => 'days', 'period' => 'seats'][$row['mode']],
            $row['percent'] === null ? null : $row['percent'] / 100, $row['free_today'], $row['pending'] ?? 0];
        $out = [['Occupancy · ' . $this->range . ' · ' . $this->data['days'] . ' days · ' . $this->data['hours'] . ' business hours a day'],
            ['Space › Unit', 'Mode', 'Capacity', 'Reservations', 'Avg occupied', 'Avg free', 'Booked', 'Available', 'In', '%', 'Free today', 'Renewal pending']];
        foreach ($this->data['spaces'] as $space) {
            foreach ($space['units'] as $row) {
                $out[] = $line($space['space'] . ' › ' . $row['unit'], $row);
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

        return $out;
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            foreach ($this->boldRows as $row) {
                $sheet->getStyle('A' . $row . ':L' . $row)->getFont()->setBold(true);
            }
            $sheet->getStyle('J3:J' . $sheet->getHighestRow())->getNumberFormat()->setFormatCode('0.0%');
            $sheet->freezePane('A3');
        }];
    }
}
