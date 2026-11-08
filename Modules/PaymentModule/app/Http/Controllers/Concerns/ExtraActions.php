<?php

namespace Modules\PaymentModule\app\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\PaymentModule\app\Http\Requests\ExtraRequest;
use Modules\PaymentModule\app\Models\Extra;
use Modules\PaymentModule\app\Services\ExtraService;
use Modules\PaymentModule\app\Services\IncomeCategoryService;

/**
 * Add an extra to a package / a reservation (2026-11-08) from its view (popup), shared by the account and employees (2026-10-21).
 * Employees: the type "extra" only (a sale, positive unit price — ExtraRequest); adjustments, edit and delete = the account.
 * After saving, the popup shows the package view again (json "modal"). The controller gives area(): "account" | "employee".
 */
trait ExtraActions
{
    private ExtraService $extraService;
    private IncomeCategoryService $categoryService;

    abstract protected function area(): string;

    public function __construct(ExtraService $extraService, IncomeCategoryService $categoryService)
    {
        $this->extraService = $extraService;
        $this->categoryService = $categoryService;
    }

    private function accountId()
    {
        return Auth::user()->userable->ownerAccountId();
    }

    // after saving, the popup shows the package / reservation view again
    private function answer(string $message, Extra $extra)
    {
        $kind = ExtraService::kindOf($extra->extrable);
        $url = route($this->area() . '.' . $kind . 's.show', $extra->extrable_id);
        return response()->json(['message' => $message, 'reload' => true, 'modal' => $url, 'redirect' => $url]);
    }

    // ?package_id=X or ?reservation_id=X (2026-11-08) → [kind, the package / reservation]
    private function extrable(Request $request): array
    {
        $kind = $request->filled('reservation_id') ? 'reservation' : 'package';
        return [$kind, $this->extraService->findExtrable($this->accountId(), $kind, (int) $request->input($kind . '_id'))];
    }

    // the Income Category list of the form (reservation and package alike): every active, non-system category + default prices
    private function categoryOptions(string $kind, $currentId = null): array
    {
        return $this->categoryService->extraOptions($this->accountId(), $currentId);
    }

    public function create(Request $request)
    {
        [$kind, $extrable] = $this->extrable($request);
        if ($error = $this->extraService->extrableError($extrable)) {
            abort(422, $error);
        }
        return view('paymentmodule::Extra.create', [
            'kind' => $kind,
            'extrable' => $extrable,
            'extra' => new Extra(['type' => 'extra', 'quantity' => 1, 'date' => today()]),
            'categories' => $this->categoryOptions($kind),
        ]);
    }

    public function store(ExtraRequest $request)
    {
        [$kind, $extrable] = $this->extrable($request);
        $extra = $this->extraService->create($this->accountId(), $extrable, $request->validated(), Auth::user());

        return $this->answer('The ' . strtolower($extra->type_label) . ' has been added to the ' . $kind . '.', $extra);
    }
}
