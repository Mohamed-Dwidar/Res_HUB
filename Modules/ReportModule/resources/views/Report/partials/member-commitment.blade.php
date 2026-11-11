{{-- Members report: the commitment badge (counted ÷ all reservations: green ≥ 90%, yellow 70–89%, red < 70%) --}}
@if ($row->commitment !== null)
    <span @class(['badge', 'badge-status-success' => $row->commitment >= 90, 'badge-status-warning' => $row->commitment >= 70 && $row->commitment < 90,
        'badge-status-danger' => $row->commitment < 70]) title="{{ isset($row->cancelled) ? $row->reservations . ' ' . Str::plural('reservation', (int) $row->reservations) . ' · ' . $row->cancelled . ' cancelled' : 'Not cancelled ÷ all reservations' }}">{{ (int) $row->commitment }}%</span>
@else
    -
@endif
