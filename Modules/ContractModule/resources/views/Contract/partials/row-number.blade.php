{{-- The Contracts list "No." cell: #251 (or Draft) → the contract popup, + "Renewal" under a renewal annex --}}
@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
<a href="{{ route($area . '.contracts.show', $contract->id) }}" data-modal class="font-medium">{{ $contract->number_label }}</a>
@if ($contract->isRenewal())
    <div class="text-xs text-slate-500">Renewal annex</div>
@endif
