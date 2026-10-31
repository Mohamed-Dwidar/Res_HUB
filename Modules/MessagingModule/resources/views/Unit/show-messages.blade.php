{{-- The unit view's "WhatsApp Messages" (UnitModule Account/Unit/show, 2026-10-31): the messages sent for its reservations. --}}
@php($unitMessages = app(\Modules\MessagingModule\app\Services\MessagingService::class)->templates((int) $unit->account_id)
    ->filter(fn ($template) => in_array((int) $unit->id, $template->unit_ids, true)))
<div class="form-section mt-8">WhatsApp Messages</div>
@forelse ($unitMessages as $template)
    <a href="{{ route('account.settings.messages.templates.edit', $template->id) }}" data-modal class="badge badge-inactive mr-1">{{ $template->name }}@if ($template->suggest_when) · {{ $template->suggest_label }}@endif @unless ($template->is_enabled) (Off)@endunless</a>
@empty
    <div class="text-slate-500">No messages: no WhatsApp icon on this unit's reservations.</div>
@endforelse
