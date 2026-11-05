{{-- a document's expiry date + its state (expired / expires in N days); the badge only for the warning states --}}
@if ($document->expiry_date)
    {{ $document->expiry_date->format('d-m-Y') }}
    @php($days = $document->daysLeft())
    @if ($document->state() === 'expired')
        <div><span class="badge badge-status-danger">Expired {{ abs($days) }} {{ abs($days) === 1 ? 'day' : 'days' }} ago</span></div>
    @elseif ($document->state() === 'expiring')
        <div><span class="badge badge-status-warning">{{ $days === 0 ? 'Expires today' : 'Expires in ' . $days . ($days === 1 ? ' day' : ' days') }}</span></div>
    @endif
@else
    <span class="text-slate-400">-</span>
@endif
