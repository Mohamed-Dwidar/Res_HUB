{{--
    The placeholder chips by group (the template page, a contract's draft page): a chip inserts {name} in the editor used last
    (custom.js, [data-contract-placeholder]); the box stays in view while the long texts scroll. $sample empty = a note.
--}}
<div class="contract-placeholders">
    <div class="contract-placeholders__head">Placeholders <span>click one to insert it where the cursor is in the text you used last · hover for what it is</span></div>
    @foreach (config('contractmodule.placeholders') as $group => $items)
        <div class="contract-placeholders__group">
            <span class="contract-placeholders__label">{{ $group }}</span>
            @foreach ($items as $name => $description)
                <button type="button" class="message-placeholders__item" data-contract-placeholder="{{ $name }}" title="{{ $description }}">{{ '{' . $name . '}' }}</button>
            @endforeach
        </div>
    @endforeach
    @if (empty($sample))
        <div class="mt-1 text-xs text-slate-500">No reservation yet: the preview shows the placeholders.</div>
    @endif
</div>
