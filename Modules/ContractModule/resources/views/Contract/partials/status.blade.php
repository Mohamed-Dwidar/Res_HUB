{{-- A contract's status badge (+ Renewed when a not cancelled renewal annex points to it, + "No scan" on a signed one without its file) --}}
<span class="badge badge-status-{{ $contract->status_color }}">{{ $contract->status_label }}</span>
@if ($contract->relationLoaded('renewals') && $contract->renewals->isNotEmpty())
    <span class="badge badge-status-info">Renewed</span>
@endif
@if ($contract->status === 'signed' && !$contract->signed_file)
    <span class="badge badge-status-warning" title="Signed without its scan">No scan</span>
@endif
