{{--
    The unit form's "Contract Template" section (UnitModule Account/Unit/partials/form, ContractModule 2026-10-31): the template whose
    contracts this unit's reservations get, one at most (the same assignment as the template form's units). Sends
    contract_template_id (+ the marker contract_template_sent, so "None" clears it). $unit: null on add.
--}}
@php($contractForm = app(\Modules\ContractModule\app\Services\ContractService::class)->unitFormData(Auth::user()->userable->ownerAccountId(), $unit?->id))
<div class="form-section mt-8">Contract Template</div>
<input type="hidden" name="contract_template_sent" value="1">
@if (empty($contractForm['options']))
    <div class="text-slate-500" data-field="contract_template_id">No contract templates yet: add them in <a href="{{ route('account.settings.contracts.edit') }}" class="text-primary">Settings › Contracts</a>.</div>
@else
    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
        @include('layoutmodule::partials.field', ['name' => 'contract_template_id', 'label' => 'Template', 'type' => 'select',
            'options' => ['' => '- None: no contracts -'] + $contractForm['options'], 'value' => $contractForm['selected'],
            'hint' => 'The contract text of this unit\'s reservations. A template can serve many units.'])
    </div>
@endif
