{{-- the Member cell: the name opens his rows (popup, the same filters), phone + company under it; member 0 = "No member" --}}
<a href="{{ route($area . '.reports.outstanding.member', ['member' => (int) $row->member_id] + array_filter(Arr::except($filters, 'search'))) }}" data-modal class="font-medium">{{ $row->member_id ? ($row->member_name ?? 'Member #' . $row->member_id) : 'No member' }}</a>
@if ($row->phone || $row->company)
    <div class="text-xs text-slate-500">{{ collect([$row->phone, $row->company])->filter()->implode(' · ') }}</div>
@endif
