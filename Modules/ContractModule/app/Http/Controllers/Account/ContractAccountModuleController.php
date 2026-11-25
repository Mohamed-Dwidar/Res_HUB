<?php

namespace Modules\ContractModule\app\Http\Controllers\Account;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\ContractModule\app\Http\Controllers\Concerns\ContractActions;

// logged in account: the contracts (ContractActions)
class ContractAccountModuleController extends Controller
{
    use ContractActions;

    protected function area(): string
    {
        return 'account';
    }

    // Back to draft: the owner only (no employee route), the reason
    public function unissueForm($id)
    {
        $contract = $this->contractService->findContract($this->accountId(), $id);
        abort_unless($this->contractService->can($contract, 'unissue', Auth::user()), 403, 'Only an issued contract can be set back to draft.');
        return view('contractmodule::Contract.unissue', compact('contract'));
    }

    public function unissue(Request $request, $id)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:255']);
        $contract = $this->contractService->unissue($this->accountId(), $id, $data['reason'], Auth::user());

        return response()->json([
            'message' => 'Contract #' . $contract->contract_no . ' is a draft again.',
            'reload' => true,
            'redirect' => $this->route('show', $contract->id),
            'modal' => $this->route('show', $contract->id),
        ]);
    }

    // Cancel (Phase 3): the owner only (no employee route), a date + a reason; $action = cancel (ending = Mark left, 2026-11-25)
    public function closeForm($id, string $action)
    {
        $contract = $this->contractService->findContract($this->accountId(), $id);
        abort_unless($this->contractService->can($contract, $action, Auth::user()), 403, 'Only the latest issued / signed contract of its chain can be ended or cancelled.');
        return view('contractmodule::Contract.close', compact('contract', 'action'));
    }

    public function close(Request $request, $id, string $action)
    {
        $data = $request->validate(['date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|min:3|max:255']);
        $contract = $this->contractService->close($this->accountId(), $id, $action, $data['date'], $data['reason'], Auth::user());

        return response()->json([
            'message' => ($contract->isRenewal() ? 'Annex ' : 'Contract ') . $contract->number_label . ($action === 'end' ? ' is ended.' : ' is cancelled.'),
            'reload' => true,
            'redirect' => $this->route('show', $contract->id),
            'modal' => $this->route('show', $contract->id),
        ]);
    }
}
