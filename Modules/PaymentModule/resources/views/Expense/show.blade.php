@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Cash Out #{{ $expense->expense_no }}
@endsection

@section('modal-size', 'lg')

@section('actions')
    @if ($hasAttachment)
        <a href="{{ route($area . '.expenses.attachment', $expense->id) }}" target="_blank" class="btn btn-secondary"><i data-lucide="paperclip"></i> Receipt</a>
    @endif
    {{-- edit: the owner, or the recorder while their shift is open (ExpenseService::canEdit()); delete: the account owner only --}}
    @if (app(\Modules\PaymentModule\app\Services\ExpenseService::class)->canEdit($expense, Auth::user()))
        <a href="{{ route($area . '.expenses.edit', $expense->id) }}" data-modal class="btn btn-secondary"><i data-lucide="pencil"></i> Edit</a>
    @endif
    @if ($area === 'account' && !$expense->is_company)
        <a href="{{ route('account.expenses.delete', $expense->id) }}" data-modal class="btn btn-danger"><i data-lucide="trash-2"></i> Delete</a>
    @endif
@endsection

@section('content')
    <dl class="detail-list">
        <div class="wide"><dt>Item</dt><dd class="font-medium">{{ $expense->item }}</dd></div>
        <div><dt>Category</dt><dd>{{ $expense->expenseCategory?->name ?? '-' }}</dd></div>
        <div><dt>Amount</dt><dd>@include('paymentmodule::Expense.partials.row-amount')</dd></div>
        <div><dt>Paid By</dt><dd>{{ $expense->method_name }}</dd></div>
        <div><dt>Date</dt><dd>{{ $expense->spent_at?->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) }}</dd></div>
        <div><dt>Shift</dt><dd>
            @if ($expense->is_company)
                <span class="badge badge-status-slate">Company — not in any shift</span>
            @elseif (!$expense->shift_closing_id)
                <span class="badge badge-status-warning">Open shift</span>
            @elseif ($area === 'account')
                <a href="{{ route('account.shifts.show', $expense->shift_closing_id) }}" data-modal><span class="badge badge-status-success"><i data-lucide="lock" class="inline h-3 w-3"></i> Closed</span></a>
            @else
                <span class="badge badge-status-success"><i data-lucide="lock" class="inline h-3 w-3"></i> Closed</span>
            @endif
        </dd></div>
        <div class="wide"><dt>Notes</dt><dd class="whitespace-pre-line">{{ $expense->notes ?: '-' }}</dd></div>
        <div class="wide"><dd>
            Added by <b>{{ $expense->creatable?->userable?->displayName() ?? '-' }}</b> | At {{ $expense->created_at?->format('d-m-Y h:i A') }}
        </dd></div>
    </dl>

    @unless (request()->ajax())
        <div class="form-actions">
            <a href="{{ route($area . '.payments.index', ['type' => 'out']) }}" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back to Cash Out</a>
        </div>
    @endunless
@endsection
