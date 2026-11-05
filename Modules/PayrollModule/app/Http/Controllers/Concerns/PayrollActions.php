<?php

namespace Modules\PayrollModule\app\Http\Controllers\Concerns;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\EmployeeModule\app\Services\AccessLogService;
use Modules\PayrollModule\app\Http\Requests\PayrollLineRequest;
use Modules\PayrollModule\app\Http\Requests\PayrollPayRequest;
use Modules\PayrollModule\app\Http\Requests\PayrollReasonRequest;
use Modules\PayrollModule\app\Models\PayrollRun;
use Modules\PayrollModule\app\Services\PayrollService;
use niklasravnsborg\LaravelPdf\Facades\Pdf;

/**
 * HR › Payroll (docs/hr-plan.md §6, H5), shared by the account and the employee controllers: the month page, Calculate /
 * Recalculate (hr.salary), a line's details / tax + notes (draft), Lock / Reopen (payroll.approve), Pay / Pay all / Undo
 * (payroll.pay), the payslip PDF (logged in the Access Log). ?month=Y-m (default this month). area() = account | employee.
 */
trait PayrollActions
{
    abstract protected function area(): string;

    public function __construct(private PayrollService $payrollService)
    {
    }

    private function accountId()
    {
        return Auth::user()->userable->ownerAccountId();
    }

    private function month($value): Carbon
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) ? Carbon::parse($value . '-01') : today()->startOfMonth();
    }

    private function runOf($runId): PayrollRun
    {
        return PayrollRun::where('account_id', $this->accountId())->findOrFail($runId);
    }

    private function page(PayrollRun $run, string $message)
    {
        return response()->json(['message' => $message, 'reload' => true, 'redirect' => route($this->area() . '.payroll.runs.index', ['month' => $run->period->format('Y-m')])]);
    }

    public function index(Request $request)
    {
        $month = $this->month($request->query('month'));
        $run = $this->payrollService->run($this->accountId(), $month);
        return view('payrollmodule::Payroll.index', [
            'month' => $month, 'run' => $run, 'area' => $this->area(),
            'lines' => $run ? $this->payrollService->lines($run) : collect(),
            'people' => $run ? null : $this->payrollService->staffFor($this->accountId(), $month)->count(),
            'canPay' => $this->payrollService->canPay($this->accountId()),
            'monthError' => $this->payrollService->monthError($this->accountId(), $month),
            'firstMonth' => $this->payrollService->firstMonth($this->accountId()),
        ]);
    }

    public function calculate(Request $request)
    {
        $month = $this->month($request->input('month'));
        $run = $this->payrollService->calculate($this->accountId(), $month, Auth::user());
        return $this->page($run, $month->format('F Y') . ' calculated: ' . ($run->totals['count'] ?? 0) . ' people, net ' . number_format($run->totals['net'] ?? 0, 2) . '.');
    }

    public function line($runId, $lineId)
    {
        $run = $this->runOf($runId);
        return view('payrollmodule::Payroll.line', ['run' => $run, 'line' => $this->payrollService->findLine($run, $lineId), 'area' => $this->area()]);
    }

    public function lineEdit($runId, $lineId)
    {
        $run = $this->runOf($runId);
        abort_if($run->isLocked(), 403, 'This payroll is locked.');
        return view('payrollmodule::Payroll.line-edit', ['run' => $run, 'line' => $this->payrollService->findLine($run, $lineId), 'area' => $this->area()]);
    }

    public function lineUpdate(PayrollLineRequest $request, $runId, $lineId)
    {
        $run = $this->runOf($runId);
        $line = $this->payrollService->updateLine($run, $lineId, $request->validated(), Auth::user());
        return $this->page($run, $line->name . ': net ' . number_format($line->net, 2) . '.');
    }

    public function lockForm($runId)
    {
        $run = $this->runOf($runId);
        return view('payrollmodule::Payroll.lock', ['run' => $run, 'area' => $this->area(),
            'negative' => $this->payrollService->negativeLines($run), 'monthError' => $this->payrollService->monthError($this->accountId(), $run->period)]);
    }

    public function lock($runId)
    {
        $run = $this->payrollService->lock($this->runOf($runId), Auth::user());
        app(AccessLogService::class)->record('action', Auth::user(), ['page' => 'Payroll ' . $run->period->format('F Y') . ' locked'], true);
        return $this->page($run, $run->period->format('F Y') . ' is locked: net ' . number_format($run->totals['net'] ?? 0, 2) . '.');
    }

    public function reopenForm($runId)
    {
        $run = $this->runOf($runId);
        return view('payrollmodule::Payroll.reason', ['run' => $run, 'area' => $this->area(), 'title' => 'Reopen ' . $run->period->format('F Y'),
            'action' => route($this->area() . '.payroll.runs.reopen', $run->id), 'error' => $this->payrollService->reopenError($run),
            'text' => 'The month goes back to draft: the advance deductions of this month are cancelled and its bonuses / deductions are free again.']);
    }

    public function reopen(PayrollReasonRequest $request, $runId)
    {
        $run = $this->payrollService->reopen($this->runOf($runId), $request->validated('reason'), Auth::user());
        app(AccessLogService::class)->record('action', Auth::user(), ['page' => 'Payroll ' . $run->period->format('F Y') . ' reopened: ' . $request->validated('reason')], true);
        return $this->page($run, $run->period->format('F Y') . ' is a draft again.');
    }

    public function payForm($runId, $lineId = null)
    {
        $run = $this->runOf($runId);
        return view('payrollmodule::Payroll.pay', [
            'run' => $run, 'area' => $this->area(), 'line' => $lineId ? $this->payrollService->findLine($run, $lineId) : null,
            'lines' => $lineId ? null : $this->payrollService->lines($run)->filter(fn ($line) => $line->remaining > 0.004),
            'methods' => app(\Modules\PaymentModule\app\Services\PaymentMethodService::class)->options($this->accountId()),
            'canPay' => $this->payrollService->canPay($this->accountId()),
        ]);
    }

    public function pay(PayrollPayRequest $request, $runId, $lineId)
    {
        $run = $this->runOf($runId);
        $payment = $this->payrollService->pay($run, $lineId, $request->validated(), Auth::user());
        return $this->page($run, 'Paid ' . number_format($payment->amount, 2) . ' (company Cash Out).');
    }

    public function payAll(PayrollPayRequest $request, $runId)
    {
        $run = $this->runOf($runId);
        $count = $this->payrollService->payAll($run, $request->validated(), Auth::user());
        return $this->page($run, $count . ' salaries paid (company Cash Out).');
    }

    public function undoForm($runId, $paymentId)
    {
        $run = $this->runOf($runId);
        $payment = $run->payments()->whereNull('cancelled_at')->findOrFail($paymentId);
        return view('payrollmodule::Payroll.reason', ['run' => $run, 'area' => $this->area(), 'title' => 'Undo Payment', 'error' => null,
            'action' => route($this->area() . '.payroll.runs.undo', [$run->id, $payment->id]),
            'text' => 'The payment of ' . number_format($payment->amount, 2) . ' (' . $payment->line?->name . ') is undone and its company Cash Out voided.']);
    }

    public function undo(PayrollReasonRequest $request, $runId, $paymentId)
    {
        $run = $this->runOf($runId);
        $this->payrollService->undoPayment($run, $paymentId, $request->validated('reason'), Auth::user());
        return $this->page($run, 'The payment has been undone.');
    }

    // the payslip (mpdf + Cairo, ?lang=ar right to left); DRAFT watermark while not locked; every open is in the Access Log
    public function payslip(Request $request, $runId, $lineId)
    {
        $run = $this->runOf($runId);
        $line = $this->payrollService->findLine($run, $lineId);
        $lang = $request->query('lang') === 'ar' ? 'ar' : 'en';
        app(AccessLogService::class)->record('download', Auth::user(), ['page' => 'Payslip ' . $run->period->format('m-Y') . ' · ' . $line->name], true);
        $draft = !$run->isLocked();
        $config = [
            'format' => 'A5', 'margin_top' => 12, 'margin_bottom' => 12,
            'instanceConfigurator' => function ($mpdf) use ($lang, $draft) {
                $mpdf->autoScriptToLang = true;
                $mpdf->autoLangToFont = true;
                if ($lang === 'ar') {
                    $mpdf->SetDirectionality('rtl');
                }
                if ($draft) {
                    $mpdf->SetWatermarkText('DRAFT', 0.08);
                    $mpdf->watermark_font = 'dejavusans';
                    $mpdf->showWatermarkText = true;
                }
            },
        ];
        if (!is_file(config('pdf.font_path') . 'Cairo-Regular.ttf')) {
            $config['default_font'] = 'dejavusans';
        }
        $pdf = Pdf::loadView('payrollmodule::Payroll.payslip-pdf', $this->payrollService->payslipData($run, $line, $lang), [], $config)->output();
        $name = 'payslip-' . $run->period->format('Y-m') . '-' . ($line->snapshot['employee_no'] ?? $line->employee_id) . ($lang === 'ar' ? '-ar' : '') . '.pdf';
        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $name . '"']);
    }
}
