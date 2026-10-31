{{-- The unit view's "Contract Template" (UnitModule Account/Unit/show, ContractModule 2026-10-31): the template of its contracts. --}}
@php($unitContract = app(\Modules\ContractModule\app\Services\ContractService::class)->unitTemplate((int) $unit->account_id, (int) $unit->id))
<div class="form-section mt-8">Contract Template</div>
@if ($unitContract)
    <a href="{{ route('account.settings.contracts.templates.edit', $unitContract->id) }}" class="badge badge-inactive mr-1">{{ $unitContract->name }} · {{ $unitContract->term_label }}@unless ($unitContract->is_enabled) (Off)@endunless</a>
@else
    <div class="text-slate-500">None: no contracts for this unit's reservations.</div>
@endif
