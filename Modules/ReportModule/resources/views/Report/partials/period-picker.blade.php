{{--
    The period picker (ReportPeriod, 2026-11-12): Period type → the month / quarter / half-year / year (or the date field for Custom
    range) + Compare with (previous / same period last year / a period I pick / none). Shared by the Financial Dashboard, Income,
    Expenses and P&L. Value '' = the page's default (not counted by Clear); a link from the dashboard opens it on the same period
    (data-initial: datatables.js / custom.js periodPicker() set it, Clear goes back to the defaults).
    $typeDefault ('month'), $compareDefault ('none'), $compareKeys (the Compare options in this order; default all), $presets (the Custom date field's quick ranges), $initial (its starting range)
--}}
@php
    use Modules\ReportModule\app\Services\ReportPeriod;
    use Modules\ReportModule\app\Services\ReportDates;
    $typeDefault ??= 'month';
    $compareDefault ??= 'none';
    $value = fn ($key, $default) => $key === $default ? '' : $key;
    // a value from the URL (not the page's default: its option value is '')
    $initialOf = fn ($name) => request()->filled($name) && !($name === 'period' && request($name) === $typeDefault)
        && !($name === 'compare' && request($name) === $compareDefault) ? 'data-initial=' . e(request($name)) : '';
    $options = ReportPeriod::options();
    $custom = request('period') === 'custom' && request()->filled('date_from') ? [request('date_from'), request('date_to') ?: request('date_from')] : ($initial ?? null);
@endphp
<div class="period-picker" data-period-picker>
    <select name="period" class="{{ config('layoutmodule.form.select') }}" aria-label="Period" {{ $initialOf('period') }}>
        @foreach (ReportPeriod::TYPES as $key => $label)
            <option value="{{ $value($key, $typeDefault) }}" @selected($key === $typeDefault)>{{ $label }}</option>
        @endforeach
    </select>
    @foreach ($options as $type => $list)
        <select name="period_{{ $type }}" class="{{ config('layoutmodule.form.select') }}" aria-label="{{ ReportPeriod::TYPES[$type] }}"
            data-show-if="period={{ $value($type, $typeDefault) }}" {{ $initialOf('period_' . $type) }}>
            @foreach ($list as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
    @endforeach
    <div data-show-if="period={{ $value('custom', $typeDefault) }}">
        @include('layoutmodule::partials.date-range-filter', ['initial' => $custom, 'presets' => $presets ?? ReportDates::presets()])
    </div>
    <select name="compare" class="{{ config('layoutmodule.form.select') }}" aria-label="Compare with" {{ $initialOf('compare') }}>
        @foreach (array_intersect_key(array_replace(array_flip($compareKeys ?? array_keys(ReportPeriod::COMPARES)), ReportPeriod::COMPARES), ReportPeriod::COMPARES) as $key => $label)
            <option value="{{ $value($key, $compareDefault) }}" @selected($key === $compareDefault)>{{ $key === 'none' ? 'No comparison' : 'Compare: ' . $label }}</option>
        @endforeach
    </select>
    <div class="period-picker__compare" data-show-if="compare=custom">
        <input type="date" name="cmp_from" class="{{ config('layoutmodule.form.input') }}" aria-label="Compare from" {{ $initialOf('cmp_from') }}>
        <span class="text-slate-500">→</span>
        <input type="date" name="cmp_to" class="{{ config('layoutmodule.form.input') }}" aria-label="Compare to" {{ $initialOf('cmp_to') }}>
    </div>
</div>
