{{-- the payments list Amount cell: bold; a refund as minus in red; withholding with a small badge (no Type column) --}}
<span class="whitespace-nowrap font-bold {{ $payment->type === 'refund' ? 'text-danger' : '' }}">{{ number_format($payment->signed_amount, 2) }}</span>
@if ($payment->type === 'withholding')
    <span class="badge badge-status-info badge-small">Withholding</span>
@endif
@include('layoutmodule::partials.note-tip', ['note' => $payment->note_tip])
