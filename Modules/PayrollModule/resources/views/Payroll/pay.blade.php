{{-- Pay a locked line (amount ≤ its remaining) or Pay all (every remaining): method + date → company Cash Out "Salaries" --}}
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    {{ $line ? 'Pay · ' . $line->name : 'Pay All' }} · {{ $run->period->format('F Y') }}
@endsection

@section('content')
    <form method="POST" action="{{ $line ? route($area . '.payroll.runs.pay', [$run->id, $line->id]) : route($area . '.payroll.runs.pay-all', $run->id) }}" data-ajax>
        @csrf
        @unless ($canPay)
            <div class="notice notice--warning mb-4">Paying from payroll is not switched on yet: the "Salaries" Cash Out category must be made the system category first (go-live step).</div>
        @endunless
        @if ($line)
            <div class="notice notice--info mb-4">Net {{ number_format($line->net, 2) }} · paid {{ number_format($line->paid_amount, 2) }} · remaining {{ number_format($line->remaining, 2) }}</div>
        @else
            <div class="notice notice--info mb-4">{{ $lines->count() }} {{ $lines->count() == 1 ? 'salary' : 'salaries' }} · {{ number_format($lines->sum(fn ($line) => $line->remaining), 2) }} — one company Cash Out each.</div>
        @endif
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            @if ($line)
                @include('layoutmodule::partials.field', ['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => true, 'value' => $line->remaining,
                    'attrs' => 'min=0.01 step=0.01 max=' . $line->remaining])
            @endif
            @include('layoutmodule::partials.field', ['name' => 'payment_method_id', 'label' => 'Paid From', 'type' => 'select', 'required' => true, 'options' => ['' => '- Choose -'] + $methods])
            @include('layoutmodule::partials.field', ['name' => 'paid_on', 'label' => 'Date', 'type' => 'date', 'required' => true, 'value' => today()->toDateString()])
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-pay" @disabled(!$canPay)><i data-lucide="banknote"></i> Pay</button>
            <button type="button" data-tw-dismiss="modal" class="btn btn-secondary">Cancel</button>
        </div>
    </form>
@endsection
