{{--
    A contract with its renewal annexes, oldest first (Phase 3, 2026-11-25; ContractService::chain()): one box per item, full row —
    the contract / annex icon (coloured by status) + title, the status + "This period" under it, View / PDF on the right; then the
    period, value and date with icons. Built from the template's own utility classes (app.css: box, border, flex, gap …) so it looks
    right even in a popup opened from a page loaded before a custom.css change. $chain = the contracts; $current = the one to mark.
--}}
@php
    $area = request()->routeIs('employee.*') ? 'employee' : 'account';
    $dateFormat = \Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT;
    $dotColors = ['draft' => 'bg-pending', 'issued' => 'bg-primary', 'signed' => 'bg-success', 'ended' => 'bg-slate-400', 'terminated' => 'bg-slate-400', 'cancelled' => 'bg-danger'];
@endphp
<div class="grid gap-3">
    @foreach ($chain as $item)
        @php($isCurrent = isset($current) && $current?->id === $item->id)
        <div @class(['box rounded-lg border p-4', 'border-primary bg-slate-50' => $isCurrent, 'border-slate-200' => !$isCurrent])>
            <div class="flex flex-wrap items-center gap-3">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-full text-white {{ $dotColors[$item->status] ?? 'bg-primary' }}">
                    <i data-lucide="{{ $item->isRenewal() ? 'refresh-cw' : 'file-text' }}" class="h-4 w-4"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <a href="{{ route($area . '.contracts.show', $item->id) }}" data-modal class="font-semibold">{{ $item->isRenewal() ? 'Renewal annex' : 'Contract' }} {{ $item->number_label }}</a>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        @include('contractmodule::Contract.partials.status', ['contract' => $item])
                        @if ($isCurrent)<span class="badge badge-status-info">This period</span>@endif
                    </div>
                </div>
                <div class="ml-auto flex items-center gap-2">
                    <a href="{{ route($area . '.contracts.show', $item->id) }}" data-modal class="btn btn-secondary btn-sm"><i data-lucide="eye" class="h-4 w-4"></i> View</a>
                    @can('contracts.pdf')
                        <a href="{{ route($area . '.contracts.pdf', ['id' => $item->id, 'lang' => 'ar']) }}" target="_blank" class="btn btn-secondary btn-sm"><i data-lucide="file-down" class="h-4 w-4"></i> PDF</a>
                    @endcan
                </div>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-dashed border-slate-200 pt-3 text-sm">
                <span class="inline-flex items-center gap-2" title="Period">
                    <i data-lucide="calendar-range" class="h-4 w-4 text-slate-500"></i>
                    <span class="whitespace-nowrap">{{ $item->starts_on->format($dateFormat) }} → {{ $item->ends_on->format($dateFormat) }}</span>
                    @if ($item->duration_text)<span class="text-slate-500" dir="rtl">{{ $item->duration_text }}</span>@endif
                </span>
                <span class="inline-flex items-center gap-2" title="Value">
                    <i data-lucide="banknote" class="h-4 w-4 text-slate-500"></i>
                    <span class="whitespace-nowrap"><b>{{ number_format((float) $item->term_amount, 2) }}</b> <span class="text-slate-500" dir="rtl">{{ $item->currency }}</span></span>
                </span>
                <span class="inline-flex items-center gap-2" title="Contract date">
                    <i data-lucide="file-signature" class="h-4 w-4 text-slate-500"></i>
                    <span class="whitespace-nowrap">{{ $item->contract_date?->format($dateFormat) ?? 'On issue' }}</span>
                </span>
                @if (in_array($item->status, ['ended', 'terminated'], true) && $item->ended_on)
                    <span class="inline-flex items-center gap-2 text-danger"><i data-lucide="log-out" class="h-4 w-4"></i> {{ $item->status === 'terminated' ? 'Terminated' : 'Ended' }} {{ $item->ended_on->format($dateFormat) }}</span>
                @endif
                @if ($item->status === 'cancelled')
                    <span class="inline-flex items-center gap-2 text-danger"><i data-lucide="ban" class="h-4 w-4"></i> Cancelled {{ $item->cancelled_at?->format($dateFormat) }}</span>
                @endif
            </div>
        </div>
    @endforeach
</div>
