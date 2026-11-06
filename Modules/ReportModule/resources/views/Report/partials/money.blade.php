{{-- an amount cell: 0 = grey "0.00", else number_format with $class (money-out / money-withholding / ...) --}}
@if (round((float) $value, 2) == 0)
    <span class="money-zero">0.00</span>
@else
    <span class="{{ $class ?? '' }}">{{ number_format((float) $value, 2) }}</span>
@endif
