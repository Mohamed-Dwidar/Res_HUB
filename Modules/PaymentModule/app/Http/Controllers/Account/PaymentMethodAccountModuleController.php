<?php

namespace Modules\PaymentModule\app\Http\Controllers\Account;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Modules\PaymentModule\app\Http\Requests\PaymentMethodRequest;
use Modules\PaymentModule\app\Services\PaymentMethodService;
use Yajra\DataTables\Facades\DataTables;

// logged in account: his payment methods (only the account manages them, the payment form selects one)
// create / edit open in the popup (ajax), store / update / destroy answer JSON (custom.js ajax forms)
class PaymentMethodAccountModuleController extends Controller
{
    private $methodService;

    public function __construct(PaymentMethodService $methodService)
    {
        $this->methodService = $methodService;
    }

    private function accountId()
    {
        return Auth::user()->userable->ownerAccountId();
    }

    // the list page, the rows come from data() (DataTables)
    public function index()
    {
        return view('paymentmodule::Method.index');
    }

    // DataTables server side: search + is_active filter
    public function data(Request $request)
    {
        return $this->table(['search' => $request->input('search.value')] + $request->all())->toJson();
    }

    // the list columns, for data() and for one row after a change (row())
    private function table(array $filters, $id = null)
    {
        $query = $this->methodService->listQuery($this->accountId());
        if ($id) {
            $query->whereKey($id);
        }

        return DataTables::eloquent($query)
            ->filter(fn ($query) => $query->filter(['search' => trim((string) ($filters['search'] ?? ''))] + Arr::only($filters, ['is_active'])))
            ->setRowId(fn ($method) => 'row-' . $method->id)
            ->orderColumn('name', fn ($query, $order) => $query->orderByLocalized('name', $order))
            ->orderColumn('sort_order', fn ($query, $order) => $query->orderBy('sort_order', $order)->orderBy('id', $order))
            ->addColumn('name_html', fn ($method) => $method->isGenerated()
                // a system method (Offset, 2026-11-12): not edited / deleted
                ? '<span class="font-medium">' . e($method->name) . '</span> <span class="badge badge-inactive">System</span>'
                : '<a href="' . route('account.payment-methods.edit', $method->id) . '" data-modal class="font-medium">' . e($method->name) . '</a>')
            ->addColumn('status', fn ($method) => view('layoutmodule::partials.row-toggle', [
                'url' => route('account.payment-methods.active', $method->id), 'row' => $method->id, 'name' => 'is_active', 'checked' => $method->is_active,
            ])->render())
            ->addColumn('actions', fn ($method) => view('paymentmodule::Method.partials.row-actions', compact('method'))->render())
            ->rawColumns(['name_html', 'status', 'actions'])
            ->only(['DT_RowId', 'sort_order', 'name_html', 'payments_count', 'status', 'actions']);
    }

    // the updated row with the list filters (list[...]), null when it does not match them any more
    private function row(Request $request, $id)
    {
        return $this->table((array) $request->input('list', []), $id)->toArray()['data'][0] ?? null;
    }

    public function create()
    {
        return view('paymentmodule::Method.create');
    }

    public function store(PaymentMethodRequest $request)
    {
        $this->methodService->create($this->accountId(), $request->validated());

        return response()->json([
            'message' => 'The payment method has been added successfully.',
            'reload' => true,
            'redirect' => route('account.payment-methods.index'),
        ]);
    }

    public function edit($id)
    {
        $method = $this->methodService->findOne($this->accountId(), $id);
        return view('paymentmodule::Method.edit', compact('method'));
    }

    public function update(PaymentMethodRequest $request, $id)
    {
        $this->methodService->update($this->accountId(), $id, $request->validated());

        return response()->json([
            'message' => 'The payment method has been updated successfully.',
            'row' => $this->row($request, $id),
            'redirect' => route('account.payment-methods.index'),
        ]);
    }

    // the active switch in the list (ajax)
    public function toggleActive(Request $request, $id)
    {
        $request->validate(['is_active' => 'required|boolean']);
        $method = $this->methodService->setActive($this->accountId(), $id, $request->boolean('is_active'));

        return response()->json([
            'message' => $method->is_active ? 'The payment method has been activated.' : 'The payment method has been deactivated.',
            'row' => $this->row($request, $id),
            'redirect' => route('account.payment-methods.index'),
        ]);
    }

    public function destroy($id)
    {
        if ($error = $this->methodService->deleteOne($this->accountId(), $id)) {
            return response()->json(['message' => $error], 422);
        }

        return response()->json([
            'message' => 'The payment method has been deleted successfully.',
            'row' => null,
            'redirect' => route('account.payment-methods.index'),
        ]);
    }
}
