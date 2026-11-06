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
use Modules\ReservationModule\app\Models\Reservation;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

// Report 4 Excel (docs/reports-plan.md §4): sheet "By category" (Shift block + subtotal, Company block + subtotal, grand total) and
// sheet "Details" (every Cash Out, chunked) — the page's filters
class ExpenseExport implements WithMultipleSheets
{
    public function __construct(private ExpenseReport $report, private $accountId, private array $filters, private object $totals)
    {
    }

    public function sheets(): array
    {
        return [
            new ExpenseCategorySheet($this->report->byCategory($this->accountId, $this->filters)->orderBy('is_company')->orderBy('category')->get(),
                $this->totals, $this->report->rangeText($this->filters)),
            new ExpenseDetailSheet($this->report->details($this->accountId, $this->filters)->orderByDesc('expenses.spent_at')->orderByDesc('expenses.id')),
        ];
    }
}

class ExpenseCategorySheet implements FromArray, WithTitle, WithColumnFormatting, WithEvents, ShouldAutoSize
{
    private array $boldRows = [];

    public function __construct(private $rows, private object $totals, private string $range)
    {
    }

    public function title(): string
    {
        return 'By category';
    }

    public function array(): array
    {
        $out = [['Expenses · ' . $this->range], ['Block', 'Category', 'Count', 'Amount']];
        $this->boldRows = [1, 2];
        foreach ([0 => 'Shift Cash Out', 1 => 'Company Cash Out'] as $company => $block) {
            $rows = $this->rows->where('is_company', $company);
            if ($rows->isEmpty()) {
                continue;
            }
            foreach ($rows as $row) {
                $out[] = [$block, $row->category, (int) $row->count, (float) $row->amount];
            }
            $out[] = [$block . ' subtotal', '', (int) $rows->sum('count'), round((float) $rows->sum('amount'), 2)];
            $this->boldRows[] = count($out);
        }
        $out[] = ['Total', '', (int) $this->totals->count, $this->totals->total];
        $this->boldRows[] = count($out);

        return $out;
    }

    public function columnFormats(): array
    {
        return ['D' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1];
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            foreach ($this->boldRows as $row) {
                $sheet->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true);
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
