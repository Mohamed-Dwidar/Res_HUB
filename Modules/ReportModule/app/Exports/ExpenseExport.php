<?php

namespace Modules\ReportModule\app\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\ReportModule\app\Services\ExpenseReport;
use Modules\ReportModule\app\Services\ReportPeriod;
use Modules\ReservationModule\app\Models\Reservation;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

// Report 4 Excel (docs/reports-plan.md §4): sheet "By category" (Shift block + subtotal, Company block + subtotal, grand total) and
// sheet "Details" (every Cash Out, chunked) — the page's filters. A comparison (2026-11-12, $cmpFilters + $cmpTotals): By category
// gets the comparison's amount + Change + Change % on every line (categories found only there come in at 0); Details = current only
class ExpenseExport implements WithMultipleSheets
{
    public function __construct(private ExpenseReport $report, private $accountId, private array $filters, private object $totals,
        private ?array $cmpFilters = null, private ?object $cmpTotals = null)
    {
    }

    public function sheets(): array
    {
        $rows = $this->cmpFilters ? $this->report->byCategoryCompared($this->accountId, $this->filters, $this->cmpFilters)
            : $this->report->byCategory($this->accountId, $this->filters);
        return [
            new ExpenseCategorySheet($rows->orderBy('is_company')->orderBy('category')->get(), $this->totals, $this->report->rangeText($this->filters),
                $this->cmpFilters ? $this->report->rangeText($this->cmpFilters) : null, $this->cmpTotals),
            new ExpenseDetailSheet($this->report->details($this->accountId, $this->filters)->orderByDesc('expenses.spent_at')->orderByDesc('expenses.id')),
        ];
    }
}

class ExpenseCategorySheet implements FromArray, WithTitle, WithColumnFormatting, WithEvents, ShouldAutoSize
{
    private array $boldRows = [];

    public function __construct(private $rows, private object $totals, private string $range, private ?string $cmpRange = null,
        private ?object $cmpTotals = null)
    {
    }

    public function title(): string
    {
        return 'By category';
    }

    // the columns after Amount: the comparison's amount, the change, the change % (a fraction, shown as %)
    private function compare($amount, $cmpAmount): array
    {
        if (!$this->cmpRange) {
            return [];
        }
        $change = ReportPeriod::change($amount, $cmpAmount, false);
        return [round((float) $cmpAmount, 2), $change['amount'], $change['percent'] === null ? null : $change['percent'] / 100];
    }

    public function array(): array
    {
        $out = [['Expenses · ' . $this->range . ($this->cmpRange ? ' vs ' . $this->cmpRange : '')],
            array_merge(['Block', 'Category', 'Count', 'Amount'], $this->cmpRange ? ['Amount · ' . $this->cmpRange, 'Change', 'Change %'] : [])];
        $this->boldRows = [1, 2];
        foreach ([0 => 'Shift Cash Out', 1 => 'Company Cash Out'] as $company => $block) {
            $rows = $this->rows->where('is_company', $company);
            if ($rows->isEmpty()) {
                continue;
            }
            foreach ($rows as $row) {
                $out[] = array_merge([$block, $row->category, (int) $row->count, (float) $row->amount], $this->compare($row->amount, $row->cmp_amount ?? 0));
            }
            $out[] = array_merge([$block . ' subtotal', '', (int) $rows->sum('count'), round((float) $rows->sum('amount'), 2)],
                $this->compare($rows->sum('amount'), $rows->sum('cmp_amount')));
            $this->boldRows[] = count($out);
        }
        $out[] = array_merge(['Total', '', (int) $this->totals->count, $this->totals->total],
            $this->cmpTotals ? $this->compare($this->totals->total, $this->cmpTotals->total) : []);
        $this->boldRows[] = count($out);

        return $out;
    }

    public function columnFormats(): array
    {
        return ['D' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1] + ($this->cmpRange
            ? ['E' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, 'F' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, 'G' => '0.0%'] : []);
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            $last = $this->cmpRange ? 'G' : 'D';
            foreach ($this->boldRows as $row) {
                $sheet->getStyle('A' . $row . ':' . $last . $row)->getFont()->setBold(true);
            }
            $sheet->freezePane('A3');
        }];
    }
}

class ExpenseDetailSheet implements FromQuery, WithTitle, WithHeadings, WithMapping, WithColumnFormatting, WithEvents, ShouldAutoSize
{
    public function __construct(private $query)
    {
    }

    public function query()
    {
        return $this->query;
    }

    public function title(): string
    {
        return 'Details';
    }

    public function headings(): array
    {
        return ['No.', 'Date', 'Block', 'Category', 'Item', 'Method', 'By', 'Amount'];
    }

    public function map($expense): array
    {
        return [$expense->expense_no, $expense->spent_at?->format(Reservation::DATE_FORMAT), $expense->is_company ? 'Company' : 'Shift',
            $expense->expenseCategory?->name, $expense->item, $expense->method_name, $expense->creatable?->userable?->name, (float) $expense->amount];
    }

    public function columnFormats(): array
    {
        return ['H' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1];
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $event->sheet->getDelegate()->getStyle('A1:H1')->getFont()->setBold(true);
            $event->sheet->getDelegate()->freezePane('A2');
        }];
    }
}
