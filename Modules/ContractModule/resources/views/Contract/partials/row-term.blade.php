{{-- The Contracts list "Term" cell: start → end + "ends in N days" / "ended N days ago" for an issued / signed one ($today = the business date) --}}
@php
    $dateFormat = \Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT;
    $days = (int) round($today->diffInDays($contract->ends_on, false));
@endphp
<div class="whitespace-nowrap">{{ $contract->starts_on->format($dateFormat) }} → {{ $contract->ends_on->format($dateFormat) }}</div>
@if (in_array($contract->status, ['issued', 'signed'], true))
    @if ($days < 0)
        <div class="text-xs text-danger">ended {{ abs($days) }} {{ Str::plural('day', abs($days)) }} ago</div>
    @elseif ($days <= (int) config('contractmodule.ending_days'))
        <div class="text-xs text-warning">ends {{ $days === 0 ? 'today' : 'in ' . $days . ' ' . Str::plural('day', $days) }}</div>
    @endif
@endif
