<?php

namespace Modules\PaymentModule\app\Http\Controllers\Account;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\PaymentModule\app\Http\Requests\ExtraRequest;
use Modules\PaymentModule\app\Http\Controllers\Concerns\ExtraActions;
use Modules\PaymentModule\app\Services\ExtraService;

// logged in account: the extras / adjustments of its packages and reservations (extras only): add (ExtraActions, shared with employees) + edit / delete
// in the popup, from the package view; after saving, the popup shows the package view again (json "modal")
class ExtraAccountModuleController extends Controller
{
    use ExtraActions;

    protected function area(): string
    {
        return 'account';
    }

    public function edit($id)
    {
        $extra = $this->extraService->findOne($this->accountId(), $id);
        $kind = ExtraService::kindOf($extra->extrable);
        return view('paymentmodule::Extra.edit', [
            'kind' => $kind,
            'extrable' => $extra->extrable,
            'extra' => $extra,
            'categories' => $this->categoryOptions($kind, $extra->income_category_id),
        ]);
    }

    public function update(ExtraRequest $request, $id)
    {
        $extra = $this->extraService->update($this->accountId(), $id, $request->validated());
        return $this->answer('The ' . strtolower($extra->type_label) . ' has been updated.', $extra);
    }

    public function destroy($id)
    {
        $extra = $this->extraService->deleteOne($this->accountId(), $id);
        return $this->answer('The ' . strtolower($extra->type_label) . ' has been deleted.', $extra);
    }
}
