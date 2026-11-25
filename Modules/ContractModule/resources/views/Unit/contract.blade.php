{{--
    The unit form's "Contract Templates" section (UnitModule Account/Unit/partials/form, ContractModule 2026-10-31; the annex 2026-11-25):
    the contract template and the renewal annex template of this unit's reservations, one of each at most (the same assignment as the
    template form's units). Sends contract_template_id / renewal_template_id (+ the marker contract_template_sent, so "None" clears
    them). $unit: null on add.
--}}
@php
    $contractService = app(\Modules\ContractModule\app\Services\ContractService::class);
    $contractForm = $contractService->unitFormData(Auth::user()->userable->ownerAccountId(), $unit?->id);
    $annexForm = $contractService->unitFormData(Auth::user()->userable->ownerAccountId(), $unit?->id, 'renewal');
@endphp
<div class="form-section mt-8">Contract Templates</div>
<input type="hidden" name="contract_template_sent" value="1">
@if (empty($contractForm['options']) && empty($annexForm['options']))
    <div class="text-slate-500" data-field="contract_template_id">No contract templates yet: add them in <a href="{{ route('account.settings.contracts.edit') }}" class="text-primary">Settings › Contracts</a>.</div>
@else
    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
        @include('layoutmodule::partials.field', ['name' => 'contract_template_id', 'label' => 'Contract', 'type' => 'select',
            'options' => ['' => '- None: no contracts -'] + $contractForm['options'], 'value' => $contractForm['selected'],
            'hint' => 'The contract text of this unit\'s reservations. A template can serve many units.'])
        @include('layoutmodule::partials.field', ['name' => 'renewal_template_id', 'label' => 'Renewal Annex', 'type' => 'select',
            'options' => ['' => '- None -'] + $annexForm['options'], 'value' => $annexForm['selected'],
            'hint' => 'Printed when a renewed subscription\'s contract is renewed for another term.'])
        {{-- the contracts' unit numbers (2026-11-25): the Create Contract page suggests the lowest free one --}}
        @include('layoutmodule::partials.field', ['name' => 'unit_no_prefix', 'label' => 'Unit No. Prefix', 'value' => $unit?->unit_no_prefix, 'width' => 'short',
            'attrs' => 'maxlength="10" placeholder="V "', 'hint' => 'Contracts of this unit get numbers like "V 105" (end the prefix with a space for "V 105"). Empty = no numbering.'])
        @include('layoutmodule::partials.field', ['name' => 'unit_no_start', 'label' => 'First Unit No.', 'type' => 'number', 'value' => $unit?->unit_no_start,
            'attrs' => 'min="1" step="1"', 'hint' => 'Optional: the suggestion never goes below it.'])
    </div>
@endif
