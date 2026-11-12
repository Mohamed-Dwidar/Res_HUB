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
use Modules\ReportModule\app\Services\ReportPeriod;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

// Report 3 Excel (docs/reports-plan.md §3): one row per group (the page's query, in the group's order) + the totals row.
// A comparison (2026-11-12): $cmpRange + $cmpTotals → the comparison period's totals + a Change row under the total; $rowCompare =
// the query carries cmp_net (not with the Month grouping) → columns "Net · <cmp range>", Change, Change %
class IncomeExport implements FromQuery, WithHeadings, WithMapping, WithColumnFormatting, WithEvents, WithTitle, ShouldAutoSize
{
    public function __construct(private $query, private object $totals, private string $groupTitle, private string $range,
        private ?string $cmpRange = null, private ?object $cmpTotals = null, private bool $rowCompare = false)
    {
    }

    // the columns after Total: the comparison's net, the change, the change % (a fraction, shown as %)
    private function compareCells($net, $cmpNet): array
    {
        if (!$this->rowCompare) {
            return [];
        }
        $change = ReportPeriod::change($net, $cmpNet);
        return [(float) $cmpNet, $change['amount'], $change['percent'] === null ? null : $change['percent'] / 100];
    }

    private function lastColumn(): string
    {
        return $this->rowCompare ? 'K' : 'H';
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
        return [[$this->groupTitle . ' · ' . $this->range . ($this->cmpRange ? ' vs ' . $this->cmpRange : '')],
            array_merge([$this->groupTitle, 'Payments', 'Cash In', 'Refunds', 'Withholding', 'Tax', 'Net (before tax)', 'Total'],
                $this->rowCompare ? ['Net · ' . $this->cmpRange, 'Change', 'Change %'] : [])];
    }

    public function map($row): array
    {
        return array_merge([$row->group_label, (int) $row->payments, (float) $row->cash_in, (float) $row->refunds, (float) $row->withholding,
            (float) $row->tax, (float) $row->net, (float) $row->total], $this->compareCells($row->net, $row->cmp_net ?? 0));
    }

    public function columnFormats(): array
    {
        $formats = array_fill_keys(['C', 'D', 'E', 'F', 'G', 'H'], NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        return $this->rowCompare ? $formats + ['I' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, 'J' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, 'K' => '0.0%'] : $formats;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $last = $this->lastColumn();
                $sheet->getStyle('A1:' . $last . '2')->getFont()->setBold(true);
                $row = $sheet->getHighestRow() + 1;
                $t = $this->totals;
                $c = $this->cmpTotals;
                $sheet->fromArray([array_merge(['Total', (int) $t->payments, $t->cash_in, $t->refunds, $t->withholding, $t->tax, $t->net, $t->total],
                    $c ? $this->compareCells($t->net, $c->net) : [])], null, 'A' . $row);
                $sheet->getStyle('A' . $row . ':' . $last . $row)->getFont()->setBold(true);
                $sheet->getStyle('C' . $row . ':' . ($this->rowCompare ? 'J' : 'H') . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                if ($this->rowCompare) {
                    $sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('0.0%');
                }
                if ($c) {
                    // the comparison period's totals + the change of every total
                    $diff = fn ($field) => round((float) $t->$field - (float) $c->$field, 2);
                    $sheet->fromArray([
                        [$this->cmpRange, (int) $c->payments, $c->cash_in, $c->refunds, $c->withholding, $c->tax, $c->net, $c->total],
                        ['Change', (int) $t->payments - (int) $c->payments, $diff('cash_in'), $diff('refunds'), $diff('withholding'), $diff('tax'), $diff('net'), $diff('total')],
                    ], null, 'A' . ($row + 1));
                    $sheet->getStyle('C' . ($row + 1) . ':H' . ($row + 2))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                    $sheet->getStyle('A' . ($row + 2) . ':H' . ($row + 2))->getFont()->setItalic(true);
                }
                $sheet->freezePane('A3');
            },
        ];
    }
}
