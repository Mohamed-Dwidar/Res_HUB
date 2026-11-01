<?php

namespace Modules\ContractModule\app\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Modules\AccountModule\app\Models\Account;
use Modules\ContractModule\app\Http\Requests\ContractRequest;
use Modules\ContractModule\app\Http\Requests\ContractSignedRequest;
use Modules\ContractModule\app\Models\ContractTemplate;
use Modules\ContractModule\app\Services\ContractService;
use Modules\SpaceModule\app\Services\SpaceService;
use Modules\UnitModule\app\Services\UnitService;
use niklasravnsborg\LaravelPdf\Facades\Pdf;
use Yajra\DataTables\Facades\DataTables;

/**
 * Contracts shared by the account and the employee controllers (ContractModule Phase 2, docs/contracts-plan.md): the list, Create /
 * Renew contract from a reservation (by hand only), the draft page (its fields + its wording in the editors: owner + employees,
 * 2026-10-31; a full page), view, Issue, PDF, Upload signed, delete a draft. What each user may do = ContractService::can().
 * The controller gives area(): "account" | "employee" (route names {area}.contracts.*). Popups as everywhere (data-modal).
 */
trait ContractActions
{
    private $contractService;

    abstract protected function area(): string;

    public function __construct(ContractService $contractService)
    {
        $this->contractService = $contractService;
    }

    // the account itself, or the account of the logged in employee
    private function accountId()
    {
        return Auth::user()->userable->ownerAccountId();
    }

    private function route(string $name, $parameters = []): string
    {
        return route($this->area() . '.contracts.' . $name, $parameters);
    }

    // the list page (the rows come from data()): search | date | space → unit | status | ending
    public function index()
    {
        return view('contractmodule::Contract.index', [
            'spaces' => app(SpaceService::class)->filterOptions($this->accountId(), $this->area() === 'employee'),
            'units' => app(UnitService::class)->filterOptions($this->accountId(), $this->area() === 'employee'),
            'endingDays' => (int) config('contractmodule.ending_days'),
        ]);
    }

    public function data(Request $request)
    {
        return $this->table(['search' => $request->input('search.value')] + $request->all())->toJson();
    }

    // No. | Tenant | Space › Unit | Term | Status | Created By | menu
    private function table(array $filters)
    {
        $query = $this->contractService->listQuery($this->accountId());
        if (!request()->filled('order')) {
            $query->orderByDesc('contracts.starts_on')->orderByDesc('contracts.id');
        }
        $today = Account::settingsOf($this->accountId())?->businessDate() ?? now()->startOfDay();

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($filters, $today) {
                $query->filter(Arr::only($filters, ['search', 'status', 'space_id', 'unit_id', 'date_from', 'date_to']));
                // Ending: issued / signed ending in the next N days | ended (before today) with no renewal annex
                match ($filters['ending'] ?? null) {
                    'soon' => $query->whereIn('contracts.status', ['issued', 'signed'])->whereDate('contracts.ends_on', '>=', $today)
                        ->whereDate('contracts.ends_on', '<=', $today->copy()->addDays((int) config('contractmodule.ending_days'))),
                    'ended' => $query->whereIn('contracts.status', ['issued', 'signed', 'ended'])->whereDate('contracts.ends_on', '<', $today)
                        ->whereDoesntHave('renewals'),
                    default => null,
                };
            })
            ->setRowId(fn ($contract) => 'row-' . $contract->id)
            ->setRowClass(fn ($contract) => $contract->status === 'cancelled' ? 'row-muted' : '')
            ->addColumn('number_html', fn ($contract) => view('contractmodule::Contract.partials.row-number', compact('contract'))->render())
            ->addColumn('tenant_html', fn ($contract) => view('contractmodule::Contract.partials.row-tenant', compact('contract'))->render())
            ->addColumn('place', fn ($contract) => e(($contract->space?->name ?? '-') . ' › ' . ($contract->unit?->name ?? $contract->unit_text ?? '-')))
            ->addColumn('term_html', fn ($contract) => view('contractmodule::Contract.partials.row-term', ['contract' => $contract, 'today' => $today])->render())
            ->addColumn('status_html', fn ($contract) => view('contractmodule::Contract.partials.status', compact('contract'))->render())
            ->addColumn('by', fn ($contract) => e($contract->creatable?->userable?->displayName() ?? '-'))
            ->addColumn('actions', fn ($contract) => view('contractmodule::Contract.partials.row-actions', compact('contract'))->render())
            ->rawColumns(['number_html', 'tenant_html', 'term_html', 'status_html', 'actions'])
            ->only(['DT_RowId', 'DT_RowClass', 'number_html', 'tenant_html', 'place', 'term_html', 'status_html', 'by', 'actions']);
    }

    // Create contract / Renew contract (?reservation_id=): the draft form, filled by ContractService::draftFor()
    public function create(Request $request)
    {
        $reservation = $this->contractService->findReservation($this->accountId(), $request->query('reservation_id'));
        try {
            $draft = $this->contractService->draftFor($reservation);
        } catch (\Illuminate\Validation\ValidationException $e) {
            abort(422, collect($e->errors())->flatten()->first());
        }
        return view('contractmodule::Contract.create', compact('reservation', 'draft') + ['terms' => $this->terms()]
            + $this->contractService->editorData($reservation, null, $draft));
    }

    public function store(ContractRequest $request)
    {
        $contract = $this->contractService->createDraft($this->accountId(), $request->input('reservation_id'), $request->validated(), Auth::user());

        return response()->json([
            'message' => ($contract->isRenewal() ? 'The renewal annex' : 'The contract') . ' draft has been created.',
            // the draft page is a full page: on to the contract (PDF / Issue from there)
            'redirect' => $this->route('show', $contract->id),
        ]);
    }

    public function show($id)
    {
        $contract = $this->contractService->findContract($this->accountId(), $id);
        return view('contractmodule::Contract.show', [
            'contract' => $contract,
            'missing' => $contract->isDraft() ? $this->contractService->missingToIssue($contract) : [],
            'languages' => $this->contractService->languages($contract),
            'hasScan' => (bool) $this->contractService->signedPath($contract),
        ]);
    }

    public function edit($id)
    {
        $contract = $this->contractService->findContract($this->accountId(), $id);
        abort_unless($this->contractService->can($contract, 'edit', Auth::user()), 403, 'Only a draft can be edited.');
        return view('contractmodule::Contract.edit', ['contract' => $contract, 'reservation' => $contract->reservation, 'terms' => $this->terms()]
            + $this->contractService->editorData($contract->reservation, $contract));
    }

    public function update(ContractRequest $request, $id)
    {
        $contract = $this->contractService->updateDraft($this->accountId(), $id, $request->validated());

        return response()->json([
            'message' => 'The draft has been saved.',
            'redirect' => $this->route('show', $contract->id),
        ]);
    }

    public function issue($id)
    {
        $contract = $this->contractService->issue($this->accountId(), $id, Auth::user());

        return response()->json([
            'message' => 'Contract #' . $contract->contract_no . ' has been issued.',
            'reload' => true,
            'redirect' => $this->route('show', $contract->id),
            'modal' => $this->route('show', $contract->id),
        ]);
    }

    // the PDF (?lang=ar | en; a new tab): mpdf A4, Cairo, RTL for Arabic, DRAFT / CANCELLED watermark
    public function pdf(Request $request, $id)
    {
        $lang = $request->query('lang') === 'en' ? 'en' : 'ar';
        $data = $this->contractService->pdfData($this->accountId(), $id, $lang);
        abort_unless($data['body'] !== null, 404, 'This contract has no ' . ($lang === 'en' ? 'English' : 'Arabic') . ' text.');
        $watermark = ['draft' => 'DRAFT', 'cancelled' => 'CANCELLED'][$data['contract']->status] ?? null;

        $config = [
            'format' => 'A4',
            'margin_top' => 30, 'margin_bottom' => 24, 'margin_header' => 8, 'margin_footer' => 8,
            'instanceConfigurator' => function ($mpdf) use ($watermark, $lang) {
                $mpdf->autoScriptToLang = true;
                $mpdf->autoLangToFont = true;
                if ($lang === 'ar') {
                    $mpdf->SetDirectionality('rtl');
                }
                if ($watermark) {
                    $mpdf->SetWatermarkText($watermark, 0.08);
                    $mpdf->watermark_font = 'dejavusans';
                    $mpdf->showWatermarkText = true;
                }
            },
        ];
        if (!is_file(config('pdf.font_path') . 'Cairo-Regular.ttf')) {
            $config['default_font'] = 'dejavusans';
        }

        $pdf = Pdf::loadView('contractmodule::Contract.pdf', $data, [], $config)->output();
        $name = 'contract-' . ($data['contract']->contract_no ?: 'draft-' . $data['contract']->id) . ($lang === 'en' ? '-en' : '') . '.pdf';
        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $name . '"']);
    }

    // Upload signed: the signing date + the scan (an issued contract; a signed one's scan = the owner)
    public function signedForm($id)
    {
        $contract = $this->contractService->findContract($this->accountId(), $id);
        abort_unless($this->contractService->can($contract, 'sign', Auth::user()) || $this->contractService->can($contract, 'replace_scan', Auth::user()), 403);
        return view('contractmodule::Contract.signed', compact('contract'));
    }

    public function signed(ContractSignedRequest $request, $id)
    {
        $contract = $this->contractService->markSigned($this->accountId(), $id, $request->validated() + ['signed_file' => $request->file('signed_file')], Auth::user());

        return response()->json([
            'message' => 'Contract ' . $contract->number_label . ' is signed.',
            'reload' => true,
            'redirect' => $this->route('show', $contract->id),
            'modal' => $this->route('show', $contract->id),
        ]);
    }

    // the signed scan: a private file, only the contract's account (and its employees) can open it
    public function signedFile($id)
    {
        $contract = $this->contractService->findContract($this->accountId(), $id);
        $path = $this->contractService->signedPath($contract) ?? abort(404);
        return Storage::disk('local')->response($path, 'contract-' . ($contract->contract_no ?: $contract->id) . '-signed.' . pathinfo($path, PATHINFO_EXTENSION));
    }

    // a draft only (the owner any draft, an employee their own)
    public function destroy($id)
    {
        $this->contractService->deleteDraft($this->accountId(), $id, Auth::user());

        return response()->json([
            'message' => 'The draft has been deleted.',
            'reload' => true,
            'redirect' => $this->route('index'),
        ]);
    }

    // the term choices: [months => "12 months"] (+ "The reservation's period" in the form)
    private function terms(): array
    {
        return collect(config('contractmodule.terms'))->mapWithKeys(fn ($months) => [$months => ContractTemplate::termLabel($months)])->all();
    }
}
