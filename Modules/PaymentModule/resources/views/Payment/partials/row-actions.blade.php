@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
{{-- refund: the account and employees (into the refunder's shift); edit: the owner, or an employee for their own cash in while
     their shift is open (PaymentService::canEdit()); delete: the account owner only --}}
@include('layoutmodule::partials.row-menu', [
    'links' => array_merge([
        ['label' => 'View', 'icon' => 'eye', 'url' => route($area . '.payments.show', $payment->id), 'modal' => true],
        ['label' => 'Receipt', 'icon' => 'printer', 'url' => route($area . '.payments.receipt', $payment->id)],
    ],
        $payment->type === 'payment' ? [['label' => 'Refund', 'icon' => 'undo-2', 'url' => route($area . '.payments.refund', $payment->id), 'modal' => true]] : [],
        app(\Modules\PaymentModule\app\Services\PaymentService::class)->canEdit($payment, Auth::user())
            ? [['label' => 'Edit', 'icon' => 'pencil', 'url' => route($area . '.payments.edit', $payment->id), 'modal' => true]] : [],
        $area !== 'account' ? [] : [
            ['label' => 'Delete', 'icon' => 'trash-2', 'url' => route('account.payments.delete', $payment->id), 'modal' => true],
        ],
    ),
])
