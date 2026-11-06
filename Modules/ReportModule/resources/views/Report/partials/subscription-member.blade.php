{{-- the Member cell: name + phone (the row opens the reservation) --}}
<div class="font-medium">{{ $row->member?->name ?? '-' }}</div>
@if ($row->member?->phone)
    <div class="text-xs text-slate-500">{{ $row->member->phone }}</div>
@endif
