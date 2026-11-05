{{-- Lock a month (popup, payroll.approve): recalculates, then freezes; advances deducted, bonuses / deductions used --}}
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Lock {{ $run->period->format('F Y') }}
@endsection

@section('content')
    <form method="POST" action="{{ route($area . '.payroll.runs.lock', $run->id) }}" data-ajax>
        @csrf
        <div class="notice notice--warning mb-4">
            The month is recalculated, then frozen: <b>each advance gets its deduction written</b>, the bonuses / deductions are marked
            as used, and the lines can no longer change (Reopen undoes it while nothing is paid).
        </div>
        <div>Now: {{ $run->totals['count'] ?? 0 }} people · net {{ number_format($run->totals['net'] ?? 0, 2) }}.</div>
        @if ($monthError)
            <div class="notice notice--warning mt-3">{{ $monthError }}</div>
        @elseif ($negative->isNotEmpty())
            <div class="notice notice--warning mt-3">Negative net — fix these lines first: {{ $negative->map(fn ($line) => $line->name . ' (' . number_format($line->net, 2) . ')')->implode(', ') }}.</div>
        @endif
        <div class="form-actions">
            <button type="submit" class="btn btn-primary" @disabled($monthError || $negative->isNotEmpty())><i data-lucide="lock"></i> Lock</button>
            <button type="button" data-tw-dismiss="modal" class="btn btn-secondary">Cancel</button>
        </div>
    </form>
@endsection
