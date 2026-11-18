<?php

namespace Modules\ReportModule\app\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\ReportModule\app\Services\OccupancyReport;

// Report 6 Excel — Performance › Over time (2026-11-18, OccupancyReport::overTime()): one line per row with this period, the same
// days last year and the change of each figure; a Total line (sums; % and seats = the average of the rows) + an Average line (per
// period, the running row left out)
class OccupancyTimeExport implements FromArray, WithEvents, WithTitle, ShouldAutoSize
{
    // the figures of a row, each written as: value | last year | change %
    const FIGURES = ['reservations' => 'Reservations', 'hourly' => 'Hours booked', 'full_day' => 'Days booked', 'period' => 'Seats',
        'percent' => 'Occupancy %', 'standalone' => 'Standalone', 'packaged' => 'From packages', 'revenue' => 'Revenue'];

    private array $boldRows = [1, 2];

    public function __construct(private array $data, private string $range)
    {
    }

    public function title(): string
    {
        return 'Performance over time';
    }

    private function figures(): array
    {
        // the booked columns of the modes the selection has only
        return array_filter(self::FIGURES, fn ($key) => !array_key_exists($key, OccupancyReport::MODES) || in_array($key, $this->data['modes'], true), ARRAY_FILTER_USE_KEY);
    }

    private function value(array $row, string $key)
    {
        if (array_key_exists($key, OccupancyReport::MODES)) {
            return $row['booked'][$key] ?? null;
        }
        return $key === 'percent' ? ($row['percent'] === null ? null : $row['percent'] / 100) : $row[$key];
    }

    public function array(): array
    {
        $header = [OccupancyReport::ROWS[$this->data['size']], 'Dates', 'Compared with'];
        foreach ($this->figures() as $label) {
            array_push($header, $label, $label . ' · last year', $label . ' · change');
        }
        $out = [['Performance over time · ' . $this->data['selection'] . ' · ' . $this->range
            . ' · each row vs the same days one year earlier · Revenue = value of bookings, not money received (see Income)'], $header];
        $line = function ($label, $dates, $cmpDates, $row) {
            $cells = [$label, $dates, $cmpDates];
            foreach (array_keys($this->figures()) as $key) {
                $current = $this->value($row, $key);
                $previous = $this->value($row['cmp'], $key);
                array_push($cells, $current, $previous, $current !== null && $previous ? round(($current - $previous) / abs($previous), 4) : null);
            }
            return $cells;
        };
        foreach ($this->data['rows'] as $row) {
            $out[] = $line($row['label'] . ($row['running'] ? ' · to date, not in the average' : ''), $row['range'], $row['cmp_range'], $row);
        }
        if ($this->data['totals']) {
            $out[] = $line('Total', $this->range, null, $this->data['totals']);
            $this->boldRows[] = count($out);
        }
        // per period, the running (to-date) row left out (2026-11-18)
        if ($average = $this->data['average'] ?? null) {
            $out[] = $line('Average per ' . strtolower(rtrim(OccupancyReport::ROWS[$this->data['size']], 's')), 'of ' . $average['count'] . ' '
                . ($average['count'] === 1 ? 'period' : 'periods') . (count($this->data['rows']) > $average['count'] ? ' (to-date period left out)' : ''), null, $average);
            $this->boldRows[] = count($out);
        }

        return $out;
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            foreach ($this->boldRows as $row) {
                $sheet->getStyle('A' . $row . ':' . $sheet->getHighestColumn() . $row)->getFont()->setBold(true);
            }
            $last = $sheet->getHighestRow();
            $column = 4;
            foreach (array_keys($this->figures()) as $key) {
                $letters = array_map(fn ($i) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column + $i), [0, 1, 2]);
                $format = $key === 'percent' ? '0.0%' : (in_array($key, ['standalone', 'packaged', 'revenue']) ? '#,##0.00' : '#,##0.##');
                $sheet->getStyle($letters[0] . '3:' . $letters[1] . $last)->getNumberFormat()->setFormatCode($format);
                $sheet->getStyle($letters[2] . '3:' . $letters[2] . $last)->getNumberFormat()->setFormatCode('+0.0%;-0.0%;0.0%');
                $column += 3;
            }
            $sheet->freezePane('B3');
        }];
    }
}
