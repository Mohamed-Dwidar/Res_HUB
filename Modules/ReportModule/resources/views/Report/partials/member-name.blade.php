{{-- Members report: the name + the commitment badge (counted ÷ all reservations: green ≥ 90%, yellow 70–89%, red < 70%) --}}
<span class="font-medium">{{ $row->name }}</span>
@if ($row->commitment !== null)
    <span @class(['badge', 'badge-status-success' => $row->commitment >= 90, 'badge-status-warning' => $row->commitment >= 70 && $row->commitment < 90,
        'badge-status-danger' => $row->commitment < 70]) title="Commitment: not cancelled ÷ all reservations">{{ (int) $row->commitment }}%</span>
@endif
