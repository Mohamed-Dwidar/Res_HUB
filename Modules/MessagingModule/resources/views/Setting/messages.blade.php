@extends('layoutmodule::account.main')

{{--
    Settings › WhatsApp Messages (the account only, MessagingModule): the settings (messages on / off, default language, deposit %,
    payment details) + the texts: per type its default (all spaces; edit, switch off) and space versions (used instead of the
    default for that space's reservations; add / edit / delete in the popup with a live preview).
--}}
@section('title')
    WhatsApp Messages
@endsection

@section('content')
    <form method="POST" action="{{ route('account.settings.messages.update') }}" data-ajax
        data-confirm="The settings apply to you and all your employees."
        data-confirm-title="Save the settings?"
        data-confirm-button="Save"
        data-confirm-variant="success">
        @csrf
        @method('PUT')

        <div class="form-section">Settings</div>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
            @include('layoutmodule::partials.checkbox', ['name' => 'is_enabled', 'label' => 'WhatsApp messages', 'checked' => $settings->is_enabled,
                'hint' => 'Off: no WhatsApp icon anywhere. The messages already sent stay in the history.'])
            @include('layoutmodule::partials.field', ['name' => 'default_language', 'label' => 'Default Language', 'type' => 'select', 'required' => true,
                'options' => config('messagingmodule.languages'), 'value' => $settings->default_language,
                'hint' => 'For members without their own WhatsApp language.'])
            @include('layoutmodule::partials.field', ['name' => 'deposit_percent', 'label' => 'Deposit %', 'type' => 'number', 'required' => true,
                'value' => $settings->deposit_percent, 'attrs' => 'min=0 max=100 step=0.01',
                'hint' => '{deposit_percent}; {deposit_amount} = the reservation total × this %.'])
        </div>
        <div class="mt-5">
            @include('layoutmodule::partials.field', ['name' => 'payment_details', 'label' => 'Payment Details', 'type' => 'textarea',
                'value' => $settings->payment_details, 'attrs' => 'maxlength="2000" dir="auto"',
                'hint' => '{payment_details}: how to pay (InstaPay handle / link, bank transfer number …), one per line.'])
        </div>

        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.dashboard')])
    </form>

    <div class="message-texts-head">
        <div class="form-section">Texts</div>
        <a href="{{ route('account.settings.messages.templates.create') }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="plus"></i> Space Version</a>
    </div>
    <div class="mb-3 text-sm text-slate-500">Each type has a default text for all spaces. A space version replaces it for that space's reservations (switched off there = the type is off for that space).</div>
    <div class="table-wrap">
        <table class="data-table message-templates">
            <thead><tr><th>Type</th><th>Space</th><th>Languages</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @foreach ($groups as $type => $templates)
                    @foreach ($templates as $template)
                        <tr @class(['message-templates__first' => $loop->first])>
                            <td>
                                @if ($loop->first)
                                    <div class="font-medium">{{ $template->type_label }}</div>
                                    <div class="text-xs text-slate-500">{{ config('messagingmodule.types.' . $type . '.hint') }}</div>
                                @endif
                            </td>
                            <td>{{ $template->isDefault() ? 'All spaces (default)' : ($template->space?->name ?? '-') }}</td>
                            <td>AR @if ($template->body_en) · EN @else <span class="text-xs text-slate-400">· EN = Arabic</span> @endif</td>
                            <td>
                                <span class="badge {{ $template->is_enabled ? 'badge-status-success' : 'badge-inactive' }}">{{ $template->is_enabled ? 'On' : 'Off' }}</span>
                            </td>
                            <td class="actions whitespace-nowrap">
                                <a href="{{ route('account.settings.messages.templates.edit', $template->id) }}" data-modal class="btn btn-secondary btn-sm"><i data-lucide="pencil"></i> Edit</a>
                                @unless ($template->isDefault())
                                    <form method="POST" action="{{ route('account.settings.messages.templates.destroy', $template->id) }}" class="inline-form" data-ajax
                                        data-confirm="The {{ $template->space?->name }} version of &quot;{{ $template->type_label }}&quot; will be deleted: its reservations get the default text again."
                                        data-confirm-title="Delete this space version?"
                                        data-confirm-button="Delete"
                                        data-confirm-variant="danger">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-secondary btn-sm" title="Delete" aria-label="Delete"><i data-lucide="trash-2"></i></button>
                                    </form>
                                @endunless
                                @if ($loop->first)
                                    <a href="{{ route('account.settings.messages.templates.create', ['type' => $type]) }}" data-modal class="btn btn-secondary btn-sm" title="Add a space version of this type"><i data-lucide="plus"></i> Space</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
