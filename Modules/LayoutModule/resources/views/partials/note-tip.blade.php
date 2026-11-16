{{--
    A note as an (i) icon: the text shows on hover / focus (2026-11-16, user: every cash in, cash out, refund, extra … with a note).
    Nothing when the note is empty. $side = right (default: the tip opens to the right, for early columns) | left (last columns).
    @include('layoutmodule::partials.note-tip', ['note' => $payment->notes])
--}}
@php($note = trim((string) ($note ?? '')))
@if ($note !== '')
    <span class="info-tip info-tip--{{ ($side ?? 'right') === 'left' ? 'left' : 'right' }}" tabindex="0" data-tip="{{ $note }}" aria-label="{{ $note }}" title=""><i data-lucide="info"></i></span>
@endif
