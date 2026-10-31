@extends('layoutmodule::account.main')

{{--
    Settings › Contracts (the account only, ContractModule, docs/contracts-plan.md): the lessor data printed on every contract + the
    first contract number, and the templates (name, title, units (count + spaces, expandable), term, languages, renewal annex, on /
    off). A template opens as a full page (the rich-text editor needs room). None until the owner adds one.
--}}
@section('title')
    Contracts
@endsection

@section('content')
    <form method="POST" action="{{ route('account.settings.contracts.update') }}" data-ajax
        data-confirm="The lessor data is printed on the contracts issued from now on (issued contracts keep theirs)."
        data-confirm-title="Save the settings?"
        data-confirm-button="Save"
        data-confirm-variant="success">
        @csrf
        @method('PUT')

        <div class="form-section">Lessor (First Party)</div>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            @include('layoutmodule::partials.field', ['name' => 'lessor_name_ar', 'label' => 'Lessor Name (Arabic)', 'required' => true,
                'value' => $settings->lessor_name_ar, 'attrs' => 'dir="rtl" maxlength="255"', 'hint' => 'The company name as written on the contract.'])
            @include('layoutmodule::partials.field', ['name' => 'lessor_name_en', 'label' => 'Lessor Name (English)', 'value' => $settings->lessor_name_en,
                'attrs' => 'maxlength="255"'])
            @include('layoutmodule::partials.field', ['name' => 'commercial_register', 'label' => 'Commercial Register', 'value' => $settings->commercial_register,
                'attrs' => 'maxlength="50"'])
            @include('layoutmodule::partials.field', ['name' => 'tax_number', 'label' => 'Tax Registration Number', 'value' => $settings->tax_number,
                'attrs' => 'maxlength="50"'])
            @include('layoutmodule::partials.field', ['name' => 'representative_name', 'label' => 'Legal Representative', 'value' => $settings->representative_name,
                'attrs' => 'dir="auto" maxlength="255"', 'hint' => 'Signs the contracts for the company.'])
            @include('layoutmodule::partials.field', ['name' => 'representative_title', 'label' => 'Representative\'s Title', 'value' => $settings->representative_title,
                'attrs' => 'dir="auto" maxlength="255"', 'hint' => 'e.g. المدير العام / General Manager'])
            @include('layoutmodule::partials.field', ['name' => 'representative_national_id', 'label' => 'Representative\'s National ID', 'value' => $settings->representative_national_id,
                'attrs' => 'maxlength="30"'])
        </div>

        <div class="form-section mt-8">Property</div>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            @include('layoutmodule::partials.field', ['name' => 'property_address_ar', 'label' => 'Property Address (Arabic)', 'type' => 'textarea',
                'value' => $settings->property_address_ar, 'attrs' => 'dir="rtl" rows="2" maxlength="1000"'])
            @include('layoutmodule::partials.field', ['name' => 'property_address_en', 'label' => 'Property Address (English)', 'type' => 'textarea',
                'value' => $settings->property_address_en, 'attrs' => 'rows="2" maxlength="1000"'])
        </div>

        <div class="form-section mt-8">Numbers</div>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
            @include('layoutmodule::partials.field', ['name' => 'number_start', 'label' => 'First Contract Number', 'type' => 'number', 'required' => true,
                'value' => $settings->number_start, 'attrs' => 'min="1" max="99999999" step="1"',
                'hint' => 'A new contract gets the next number after the highest one, never below this. Set it when the old contracts are imported.'])
        </div>

        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.dashboard')])
    </form>

    <div class="message-texts-head">
        <div class="form-section">Templates</div>
        <a href="{{ route('account.settings.contracts.templates.create') }}" class="btn btn-primary btn-sm"><i data-lucide="plus"></i> New Template</a>
    </div>
    <div class="mb-3 text-sm text-slate-500">A template is the contract text of its units: their reservations get contracts from it. A unit has one template at most; units can also be chosen from the unit's form. Editing a template never changes contracts already issued.</div>
    @if ($templates->isEmpty())
        <div class="notice">No templates yet. Add one with <b>+ New Template</b>, write its text, then choose its units.</div>
    @else
        <div class="table-wrap">
            <table class="data-table message-templates">
                <thead><tr><th>Name</th><th>Title</th><th>Units</th><th>Term</th><th>AR / EN</th><th>Renewal Annex</th><th>Enabled</th><th></th></tr></thead>
                <tbody>
                    @foreach ($templates as $template)
                        @php($bySpace = $template->units->groupBy(fn ($unit) => $unit->space?->name ?? '-'))
                        <tr>
                            <td class="font-medium">{{ $template->name }}</td>
                            <td dir="rtl">{{ $template->title_ar }}</td>
                            <td>
                                @if ($template->units->isEmpty())
                                    <span class="text-slate-400">No units (not used)</span>
                                @else
                                    <details class="message-units">
                                        <summary>{{ $template->units->count() }} {{ Str::plural('unit', $template->units->count()) }} · {{ $bySpace->keys()->implode(', ') }}</summary>
                                        @foreach ($bySpace as $spaceName => $units)
                                            <div class="message-units__space"><b>{{ $spaceName }}:</b> {{ $units->pluck('name')->implode(', ') }}</div>
                                        @endforeach
                                    </details>
                                @endif
                            </td>
                            <td>{{ $template->term_label }}</td>
                            <td>AR @if ($template->body_en) · EN @else <span class="text-xs text-slate-400">· no EN</span> @endif</td>
                            <td>
                                @if ($template->renewal_body_ar)
                                    AR @if ($template->renewal_body_en) · EN @endif
                                @else
                                    <span class="text-slate-400">None</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $template->is_enabled ? 'badge-status-success' : 'badge-inactive' }}">{{ $template->is_enabled ? 'On' : 'Off' }}</span>
                            </td>
                            <td class="actions whitespace-nowrap">
                                <a href="{{ route('account.settings.contracts.templates.edit', $template->id) }}" class="btn btn-secondary btn-sm"><i data-lucide="pencil"></i> Edit</a>
                                {{-- a New Template form filled from this one (texts, titles, term; no units): nothing is saved until Add --}}
                                <a href="{{ route('account.settings.contracts.templates.create', ['from' => $template->id]) }}" class="btn btn-secondary btn-sm" title="Duplicate: a new template from this one's texts"><i data-lucide="copy"></i> Duplicate</a>
                                <form method="POST" action="{{ route('account.settings.contracts.templates.destroy', $template->id) }}" class="inline-form" data-ajax
                                    data-confirm="&quot;{{ $template->name }}&quot; will be deleted{{ $template->units->isNotEmpty() ? ' and removed from ' . $template->units->count() . ' ' . Str::plural('unit', $template->units->count()) . ' (' . $bySpace->keys()->implode(', ') . ')' : '' }}. Contracts already issued keep their text."
                                    data-confirm-title="Delete this template?"
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
