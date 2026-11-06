{{-- a report's description: one short line ($text) + (i) with the details ($details) in a tooltip --}}
<div class="report-intro">
    <span>{{ $text }}</span>
    @if (!empty($details))
        <span class="info-tip" tabindex="0" data-tip="{{ $details }}" aria-label="{{ $details }}"><i data-lucide="info"></i></span>
    @endif
</div>
