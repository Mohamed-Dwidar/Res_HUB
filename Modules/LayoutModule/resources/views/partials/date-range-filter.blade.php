{{--
    ONE date field of a DataTables filters form, synced with its tabs (partials.filter-tabs): flatpickr range mode
    (MIT, Arabic locale l10n/ar; local copy public/assets/vendor/flatpickr), one shared init in custom.js (dateRangeFilter).
    Sends date_from / date_to (hidden). One day clicked = that day, two days = the range between them; × clears it (= the All tab).
    A tab click shows its days (data-date-select) or its own rule text (data-date-label); picking days highlights the matching tab,
    other days = the All tab with that range, no tab highlighted.
    $single (optional): one day only, no range (the employees' payments list).
    $default (optional, Y-m-d): the day it starts on and goes back to (× / Clear), never empty — a list with no tabs (employees' payments).
--}}
<div class="date-range-filter" data-date-range @if (!empty($single)) data-date-single @endif @if (!empty($default)) data-date-default="{{ $default }}" @endif>
    <input type="hidden" name="date_from" value="{{ $default ?? '' }}">
    <input type="hidden" name="date_to" value="{{ $default ?? '' }}">
    <input type="text" class="{{ config('layoutmodule.form.input') }}" data-date-range-input placeholder="{{ $placeholder ?? 'All dates' }}" aria-label="Date" autocomplete="off" readonly>
    <button type="button" class="date-range-filter__clear" data-date-range-clear title="Clear the date" hidden><i data-lucide="x"></i></button>
</div>
{{-- flatpickr: loaded by the main layout (every page, 2026-11-01: every date field = dd/mm/yyyy) --}}
