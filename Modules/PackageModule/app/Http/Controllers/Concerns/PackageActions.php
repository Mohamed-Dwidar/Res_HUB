<?php

namespace Modules\PackageModule\app\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Modules\CompanyModule\app\Services\CompanyService;
use Modules\PackageModule\app\Http\Requests\PackageRequest;
use Modules\PackageModule\app\Services\PackageService;
use niklasravnsborg\LaravelPdf\Facades\Pdf;
use Yajra\DataTables\Facades\DataTables;

/**
 * Packages actions shared by the account and the employee controllers (both manage the account's packages).
 * The controller gives area(): "account" | "employee" (route names {area}.packages.*), the views read it from the route.
 * show / create / edit open in the popup (ajax), store / update / destroy answer JSON (custom.js ajax forms)
 */
trait PackageActions
{
    private PackageService $packageService;
    private CompanyService $companyService;

    abstract protected function area(): string;

    public function __construct(PackageService $packageService, CompanyService $companyService)
    {
        $this->packageService = $packageService;
        $this->companyService = $companyService;
    }

    // the account itself, or the account of the logged in employee
    private function accountId()
    {
        return Auth::user()->userable->ownerAccountId();
    }

    private function route($name, $parameters = [])
    {
        return route($this->area() . '.packages.' . $name, $parameters);
    }

    // the list page, the rows come from data() (DataTables)
    public function index()
    {
        return view('packagemodule::Package.index');
    }

    // the list tab: current (default, also '') | past | all
    private function tab(array $filters): string
    {
        $tab = (string) ($filters['tab'] ?? '');
        return isset(\Modules\PackageModule\app\Models\Package::TABS[$tab]) ? $tab : 'current';
    }

    // DataTables server side: tab + search (package, member name / phone) + dates (period overlaps) / status / payment filters
    public function data(Request $request)
    {
        return $this->table(['search' => $request->input('search.value')] + $request->all())->toJson();
    }

    /**
     * The list columns (docs/reservations-list-plan.md, packages list 2026-10-13), for data() and for one row after a change:
     * Package (+ member) | Period (From / Ends) | Reservations (counted / booked hours, two lines) | Amount (due = net + extras, + price · −discount% · extras)
     * | Paid (black bold) | Remaining | Created By | Status (package + payment badges); Activate / Deactivate in the row menu
     */
    private function table(array $filters, $id = null)
    {
        $tab = $this->tab($filters);
        // + counted_reservations / booked_minutes, + who added it (By)
        $query = $this->packageService->listQuery($this->accountId())->withReservationStats()->with('creatable.userable')
            // Σ extras in the same query (HasExtras::extrasTotal() reads extras_sum_amount): the Amount column = due
            ->withSum('extras', 'amount');
        if ($id) {
            $query->whereKey($id);
        }

        return DataTables::eloquent($query)
            // inside filter() so "of N total" counts all the packages
            ->filter(fn ($query) => $query->filter(['search' => trim((string) ($filters['search'] ?? ''))] + Arr::only($filters, ['payment_status', 'date_from', 'date_to']))->tab($tab))
            ->setRowId(fn ($package) => 'row-' . $package->id)
            ->addColumn('name_html', fn ($package) => view('packagemodule::Package.partials.row-package', compact('package'))->render())
            ->addColumn('period', fn ($package) => view('packagemodule::Package.partials.row-period', compact('package'))->render())
            ->addColumn('reservations', fn ($package) => view('packagemodule::Package.partials.row-reservations', compact('package'))->render())
            ->addColumn('status', fn ($package) => view('packagemodule::Package.partials.row-status', compact('package'))->render())
            // sorted by the due (net + extras), what the cell shows
            ->orderColumn('after_discount', fn ($query, $order) => $query->orderByRaw('packages.after_discount + COALESCE(extras_sum_amount, 0) ' . ($order === 'asc' ? 'asc' : 'desc')))
            ->editColumn('after_discount', fn ($package) => view('packagemodule::Package.partials.row-amount', compact('package'))->render())
            // black, bold (custom.css .amount-paid)
            ->editColumn('paid_amount', fn ($package) => '<span class="amount-paid">' . number_format($package->paid_amount, 2) . '</span>')
            // red when owed, a credit (negative) in blue, no badge
            ->addColumn('remaining_html', fn ($package) => view('paymentmodule::Payment.partials.remaining-amount', ['payable' => $package])->render())
            ->addColumn('by', fn ($package) => e($package->creatable?->userable?->displayName() ?? '-'))
            ->addColumn('actions', fn ($package) => view('packagemodule::Package.partials.row-actions', compact('package'))->render())
            ->rawColumns(['name_html', 'period', 'reservations', 'status', 'after_discount', 'paid_amount', 'remaining_html', 'actions'])
            ->only(['DT_RowId', 'name_html', 'period', 'reservations', 'status', 'after_discount', 'paid_amount', 'remaining_html', 'by', 'actions']);
    }

    // the updated row with the list filters (list[...]), null when it does not match them any more
    private function row(Request $request, $id)
    {
        return $this->table((array) $request->input('list', []), $id)->toArray()['data'][0] ?? null;
    }

    // Add Package: the suggested name for the chosen member (custom.js packageNameSuggest(), ?member_id=)
    public function suggestName(Request $request)
    {
        return response()->json(['name' => $this->packageService->suggestName($this->accountId(), $request->input('member_id'))]);
    }

    public function create()
    {
        // the Member field searches the server (member-select); its quick add offers the account's companies
        $companies = $this->companyService->options($this->accountId());
        return view('packagemodule::Package.create', compact('companies'));
    }

    public function store(PackageRequest $request)
    {
        $package = $this->packageService->create($this->accountId(), $request->validated(), Auth::user());

        return response()->json([
            'message' => 'The package has been added successfully.',
            'reload' => true,
            'redirect' => $this->route('show', $package->id),
        ]);
    }

    public function show($id)
    {
        $package = $this->packageService->findOne($this->accountId(), $id)
            ->load(['reservations' => fn ($query) => $query->with('unit.color', 'space', 'status', 'plan')->latest('start_at')]);
        return view('packagemodule::Package.show', compact('package'));
    }

    // the customer package statement (PDF, A4, opened in the browser) — "Package Statement", not an invoice
    public function statement($id)
    {
        $data = $this->packageService->statement($this->accountId(), $id);

        $config = [
            'format' => 'A4',
            'margin_top' => 12, 'margin_bottom' => 18, 'margin_footer' => 6,
            // Arabic (member / item names): shaped and right to left (autoScriptToLang marks the language, autoLangToFont picks a font)
            'instanceConfigurator' => function ($mpdf) {
                $mpdf->autoScriptToLang = true;
                $mpdf->autoLangToFont = true;
            },
        ];
        // the Cairo font of config/pdf.php when its files are there, else mpdf's DejaVu Sans (Arabic too)
        if (!is_file(config('pdf.font_path') . 'Cairo-Regular.ttf')) {
            $config['default_font'] = 'dejavusans';
        }

        // a normal response (the package's stream() echoes it itself, outside the Laravel response)
        $pdf = Pdf::loadView('packagemodule::Package.statement-pdf', $data, [], $config)->output();
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="package-statement-' . $data['package']->id . '.pdf"',
        ]);
    }

    public function edit($id)
    {
        $package = $this->packageService->findOne($this->accountId(), $id);
        $companies = $this->companyService->options($this->accountId());
        return view('packagemodule::Package.edit', compact('package', 'companies'));
    }

    public function update(PackageRequest $request, $id)
    {
        $this->packageService->update($this->accountId(), $id, $request->validated());

        return response()->json([
            'message' => 'The package has been updated successfully.',
            'row' => $this->row($request, $id),
            'redirect' => $this->route('show', $id),
        ]);
    }

    public function destroy($id)
    {
        if ($error = $this->packageService->deleteOne($this->accountId(), $id)) {
            return response()->json(['message' => $error], 422);
        }

        return response()->json([
            'message' => 'The package has been deleted successfully.',
            'row' => null,
            'redirect' => $this->route('index'),
        ]);
    }
}
