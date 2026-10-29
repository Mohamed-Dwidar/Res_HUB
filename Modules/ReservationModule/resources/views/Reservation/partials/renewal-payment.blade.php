{{--
    The Payment cell of a renewal row ($view = RenewalView::make(), $area): the small label (+ the period's dates), the badge
    (or "✓ Renewed & paid"), "Due X of Y" in red, a note ("Not renewed"), hints (the current period's debt → pays it; "Older
    period still owes X").
--}}
@if ($view['label'] || $view['labelDates'])
    <div class="text-xs text-slate-500">{{ $view['label'] }}@if ($view['label'] && $view['labelDates']) · @endif<span class="whitespace-nowrap">{{ $view['labelDates'] }}</span></div>
@endif
@if ($view['check'])
    <span class="badge badge-status-success"><i data-lucide="check" class="inline h-3 w-3"></i> {{ $view['check'] }}</span>
@elseif ($view['badges'])
    {{-- Not renewed & Not paid: the renewal badge + the payment badge, side by side --}}
    <div class="renewal-badges">@foreach ($view['badges'] as [$text, $class])<span class="badge {{ $class }}">{{ $text }}</span>@endforeach</div>
@elseif ($view['badge'])
    <span class="badge {{ $view['badge'][1] }}">{{ $view['badge'][0] }}</span>@if ($view['note']) <span class="text-xs text-danger font-medium">· {{ $view['note'] }}</span>@endif
@endif
@if ($view['due'])
    <div class="text-xs text-danger font-medium">{{ $view['due'] }}</div>
@endif
@foreach ($view['hints'] as [$text, $row, $title])
    @if ($row)
        <a href="{{ route($area . '.payments.create', ['payable' => 'reservation', 'payable_id' => $row->id]) }}" data-modal class="block text-xs text-danger" title="{{ $title }}">{{ $text }}</a>
    @else
        <div class="text-xs text-danger">{{ $text }}</div>
    @endif
@endforeach
