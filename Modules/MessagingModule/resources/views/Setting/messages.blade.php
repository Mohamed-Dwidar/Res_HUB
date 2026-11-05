@extends('layoutmodule::account.main')

{{--
    Settings › WhatsApp Messages (the account only, MessagingModule): the settings (messages on / off, default language) + the messages (2026-10-31): name, "Suggest when", units (count + spaces, expandable), languages, on / off; add / edit /
    delete in the popup with a live preview. None until the owner adds one.
--}}
@section('title')
    WhatsApp Messages
@endsection

@section('content')
    <form method="POST" action="{{ route('account.settings.messages.update') }}" data-ajax
        data-confirm="The settings apply to you and all your users."
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
        </div>

        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.dashboard')])
    </form>

    <div class="message-texts-head">
        <div class="form-section">Messages</div>
        <a href="{{ route('account.settings.messages.templates.create') }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="plus"></i> New Message</a>
    </div>
    <div class="mb-3 text-sm text-slate-500">A message is offered for the reservations of its units. "Suggest when" puts it first (★) in the right list and opens it after a new reservation / Mark left; a unit has one message for each "Suggest when" at most (Never: any number). A unit without messages shows no WhatsApp icon. Units can also be chosen from the unit's form.</div>
    @if ($templates->isEmpty())
        <div class="notice">No messages yet. Add one with <b>+ New Message</b> (start from a suggested text or write your own), then choose its units.</div>
    @else
        <div class="table-wrap">
            <table class="data-table message-templates">
                <thead><tr><th>Name</th><th>Suggest When</th><th>Units</th><th>AR / EN</th><th>Enabled</th><th></th></tr></thead>
                <tbody>
                    @foreach ($templates as $template)
                        @php($bySpace = $template->units->groupBy(fn ($unit) => $unit->space?->name ?? '-'))
                        <tr>
                            <td><a href="{{ route('account.settings.messages.templates.edit', $template->id) }}" data-modal class="font-medium">{{ $template->name }}</a></td>
                            <td @class(['text-slate-400' => !$template->suggest_when])>{{ $template->suggest_label }}</td>
                            <td>
                                @if ($template->units->isEmpty())
                                    <span class="text-slate-400">No units (not sent)</span>
                                @else
                                    <details class="message-units">
                                        <summary>{{ $template->units->count() }} {{ Str::plural('unit', $template->units->count()) }} · {{ $bySpace->keys()->implode(', ') }}</summary>
                                        @foreach ($bySpace as $spaceName => $units)
                                            <div class="message-units__space"><b>{{ $spaceName }}:</b> {{ $units->pluck('name')->implode(', ') }}</div>
                                        @endforeach
                                    </details>
                                @endif
                            </td>
                            <td>AR @if ($template->body_en) · EN @else <span class="text-xs text-slate-400">· EN = Arabic</span> @endif</td>
                            <td>
                                <span class="badge {{ $template->is_enabled ? 'badge-status-success' : 'badge-inactive' }}">{{ $template->is_enabled ? 'On' : 'Off' }}</span>
                            </td>
                            <td class="actions whitespace-nowrap">
                                <a href="{{ route('account.settings.messages.templates.edit', $template->id) }}" data-modal class="btn btn-secondary btn-sm"><i data-lucide="pencil"></i> Edit</a>
                                <form method="POST" action="{{ route('account.settings.messages.templates.destroy', $template->id) }}" class="inline-form" data-ajax
                                    data-confirm="&quot;{{ $template->name }}&quot; will be deleted{{ $template->units->isNotEmpty() ? ' and removed from ' . $template->units->count() . ' ' . Str::plural('unit', $template->units->count()) . ' (' . $bySpace->keys()->implode(', ') . ')' : '' }}. The messages already sent stay in the history."
                                    data-confirm-title="Delete this message?"
                                    data-confirm-button="Delete"
                                    data-confirm-variant="danger">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-secondary btn-sm" title="Delete" aria-label="Delete"><i data-lucide="trash-2"></i></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
