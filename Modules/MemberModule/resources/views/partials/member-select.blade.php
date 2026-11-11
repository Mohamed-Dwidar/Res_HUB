{{--
    The Member field of a form (reservation, package, cash in, withholding; 2026-10-18), with server search and quick add:
    @include('membermodule::partials.member-select', ['member' => $model?->member, 'required' => true, 'hint' => '...',
        'width' => 'long', 'wrapperClass' => '...', 'placeholder' => '- Select -', 'companies' => $companies])
    $member: the chosen member (edit / pre-filled) or null — its one option is the only one rendered.
    - The drop menu has only the chosen member (edit) — the rest comes from {area}.members.search while typing (custom.js
      "Member select": 2+ characters, 250 ms, "Searching…", "Search failed — retry", the newest answer only).
    - No match → '+ Add "<typed>" as a new member', or the "+ New member" link: the inline box below (same form, not a popup):
      Name, Mobile, Company. Its inputs have NO name (not sent with the form). The mobile is checked first ({area}.members.lookup,
      by phone_key): taken → "This mobile belongs to <name>" + Use this member. Save = {area}.members.store?quick=1 (the Members
      page rules, MemberRequest), the new member is selected (its change event: the forms' own chains follow).
    $companies: [id => name] of the account (optional company of a new member).
    $commitment: true = the chosen member's commitment badge next to the field (the reservation form, 2026-11-11):
      {area}.members.commitment?member_id= on every change (custom.js memberCommitment()), the Members report rule.
--}}
@php
    $area = request()->routeIs('employee.*') ? 'employee' : 'account';
    $selected = !empty($member) ? [$member->id => $member->name . ' ' . $member->phone] : [];
    // roles (docs/roles-plan.md, R0): no quick add without members.create (no link, no box, no "+ Add" in the search)
    $canAdd = Gate::allows('members.create');
@endphp
@include('layoutmodule::partials.field', ['name' => 'member_id', 'label' => $label ?? 'Member', 'type' => 'select', 'searchable' => true,
    'required' => $required ?? false, 'width' => $width ?? 'long', 'wrapperClass' => $wrapperClass ?? null,
    'options' => ['' => $placeholder ?? '- Select -'] + $selected, 'value' => $member->id ?? null,
    'attrs' => 'data-member-select data-search-url="' . route($area . '.members.search') . '"' . (empty($required) ? ' data-member-optional' : '') . ($canAdd ? '' : ' data-member-no-add'),
    'hint' => $hint ?? 'Search by name or mobile.'])
@if ($canAdd || !empty($commitment))
<div class="member-quick-link-row">
    @if ($canAdd)
        <button type="button" class="member-quick-link" data-member-quick-open><i data-lucide="user-plus"></i> New member</button>
    @endif
    @if (!empty($commitment))
        <span class="member-commitment" data-member-commitment data-url="{{ route($area . '.members.commitment') }}" hidden></span>
    @endif
</div>
@endif
@if ($canAdd)

<div class="member-quick" data-member-quick hidden
    data-store-url="{{ route($area . '.members.store', ['quick' => 1]) }}" data-lookup-url="{{ route($area . '.members.lookup') }}">
    <div class="member-quick__title">New member</div>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <div>
            <label class="{{ config('layoutmodule.form.label') }}">Name <span class="text-danger">*</span></label>
            <input type="text" class="{{ config('layoutmodule.form.input') }}" data-quick-field="name" maxlength="255" autocomplete="off">
            <div class="{{ config('layoutmodule.form.error') }}" data-quick-error="name" hidden></div>
        </div>
        <div>
            <label class="{{ config('layoutmodule.form.label') }}">Mobile <span class="text-danger">*</span></label>
            <input type="tel" class="{{ config('layoutmodule.form.input') }}" data-quick-field="phone" maxlength="20" autocomplete="off">
            <div class="{{ config('layoutmodule.form.error') }}" data-quick-error="phone" hidden></div>
        </div>
        <div>
            <label class="{{ config('layoutmodule.form.label') }}">Company</label>
            <select class="{{ config('layoutmodule.form.select') }}" data-quick-field="company_id">
                <option value="">- None -</option>
                @foreach ($companies ?? [] as $companyId => $companyName)
                    <option value="{{ $companyId }}">{{ $companyName }}</option>
                @endforeach
            </select>
            <div class="{{ config('layoutmodule.form.error') }}" data-quick-error="company_id" hidden></div>
        </div>
    </div>
    {{-- the mobile is already a member's (lookup by phone_key) --}}
    <div class="member-quick__duplicate" data-quick-duplicate hidden>
        This mobile belongs to <b data-quick-duplicate-name></b>.
        <button type="button" class="btn btn-primary btn-sm" data-quick-use>Use this member</button>
    </div>
    <div class="member-quick__actions">
        <button type="button" class="btn btn-primary btn-sm" data-quick-save><i data-lucide="check"></i> Save member</button>
        <button type="button" class="btn btn-secondary btn-sm" data-quick-cancel>Cancel</button>
    </div>
</div>
@endif
