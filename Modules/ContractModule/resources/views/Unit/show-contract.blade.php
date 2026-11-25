{{-- The unit view's "Contract Templates" (UnitModule Account/Unit/show): its contract template and its renewal annex template. --}}
@php
    $contractService = app(\Modules\ContractModule\app\Services\ContractService::class);
    $unitTemplates = ['Contract' => $contractService->unitTemplate((int) $unit->account_id, (int) $unit->id), 'Renewal annex' => $contractService->unitTemplate((int) $unit->account_id, (int) $unit->id, 'renewal')];
@endphp
<div class="form-section mt-8">Contract Templates</div>
@if (!array_filter($unitTemplates))
    <div class="text-slate-500">None: no contracts for this unit's reservations.</div>
@else
    @foreach ($unitTemplates as $label => $unitContract)
        <div class="mb-1">{{ $label }}:
            @if ($unitContract)
                <a href="{{ route('account.settings.contracts.templates.edit', $unitContract->id) }}" class="badge badge-inactive mr-1">{{ $unitContract->name }}@if ($label === 'Contract') · {{ $unitContract->term_label }}@endif @unless ($unitContract->is_enabled) (Off)@endunless</a>
            @else
                <span class="text-slate-500">None</span>
            @endif
        </div>
    @endforeach
@endif
