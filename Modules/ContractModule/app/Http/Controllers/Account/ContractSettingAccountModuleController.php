<?php

namespace Modules\ContractModule\app\Http\Controllers\Account;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\ContractModule\app\Http\Requests\ContractSettingRequest;
use Modules\ContractModule\app\Http\Requests\ContractTemplateRequest;
use Modules\ContractModule\app\Models\ContractTemplate;
use Modules\ContractModule\app\Services\ContractService;

/**
 * Settings › Contracts (the account only, docs/contracts-plan.md): the lessor data + first contract number, and the templates table
 * (none until the owner adds one; each = name + title + rich-text body + renewal annex (Arabic, optional English) + term + on / off
 * + its units). A template opens as a full page (the editor needs room), saved by ajax.
 */
class ContractSettingAccountModuleController extends Controller
{
    private $contractService;

    public function __construct(ContractService $contractService)
    {
        $this->contractService = $contractService;
    }

    private function accountId()
    {
        return Auth::user()->userable->id;
    }

    public function edit()
    {
        $templates = $this->contractService->templates($this->accountId());
        $templates->load(['units' => fn ($query) => $query->with('space')]);

        return view('contractmodule::Setting.contracts', [
            'settings' => $this->contractService->settings($this->accountId()),
            'templates' => $templates,
        ]);
    }

    public function update(ContractSettingRequest $request)
    {
        $this->contractService->saveSettings($this->accountId(), $request->validated());

        return response()->json([
            'message' => 'The settings have been saved.',
            'redirect' => route('account.settings.contracts.edit'),
        ]);
    }

    // + New Template; ?from=<id> = Duplicate: the form filled from that template (nothing saved until Add, see duplicateOf())
    public function createTemplate(Request $request)
    {
        $copy = $request->filled('from') ? $this->contractService->duplicateOf($this->accountId(), $request->query('from')) : null;
        $copyOf = $copy ? $this->contractService->findTemplate($this->accountId(), $request->query('from'))->name : null;

        return view('contractmodule::Setting.template-create', $this->templateFormData(null) + ['template' => null, 'copy' => $copy, 'copyOf' => $copyOf]);
    }

    public function storeTemplate(ContractTemplateRequest $request)
    {
        $this->contractService->createTemplate($this->accountId(), $request->validated(), Auth::user());

        return response()->json([
            'message' => 'The template has been added.',
            'redirect' => route('account.settings.contracts.edit'),
        ]);
    }

    public function editTemplate($id)
    {
        $template = $this->contractService->findTemplate($this->accountId(), $id);
        return view('contractmodule::Setting.template-edit', $this->templateFormData($template->id) + compact('template'));
    }

    public function updateTemplate(ContractTemplateRequest $request, $id)
    {
        $this->contractService->updateTemplate($this->accountId(), $id, $request->validated());

        return response()->json([
            'message' => 'The template has been saved.',
            'redirect' => route('account.settings.contracts.edit'),
        ]);
    }

    public function destroyTemplate($id)
    {
        $this->contractService->deleteTemplate($this->accountId(), $id);

        return response()->json([
            'message' => 'The template has been deleted.',
            'redirect' => route('account.settings.contracts.edit'),
        ]);
    }

    // the form's lists: the spaces with their units, the units of other templates (hints), the preview values
    private function templateFormData(?int $templateId): array
    {
        return [
            'spaces' => $this->contractService->spacesWithUnits($this->accountId()),
            'taken' => $this->contractService->unitsTaken($this->accountId(), $templateId),
            'sample' => $this->contractService->sampleVariables($this->accountId()),
            'terms' => collect(config('contractmodule.terms'))->mapWithKeys(fn ($months) => [$months => ContractTemplate::termLabel($months)])->all(),
        ];
    }
}
