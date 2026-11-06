{{-- the oldest owed row's start: d-m-Y + (N days) ago; none = "-" --}}
@if ($date)
    @php($since = \Carbon\Carbon::parse($date))
    {{ $since->format('d-m-Y') }}
    @if ($since->isPast())
        <div class="text-xs text-slate-500">{{ (int) $since->copy()->startOfDay()->diffInDays(today()) }} days</div>
    @endif
@else
    <span class="text-slate-400">-</span>
@endif
