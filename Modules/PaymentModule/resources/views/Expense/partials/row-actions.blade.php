@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
{{-- view + the receipt (anyone who can open it); edit: the owner, or the recorder while their shift is open
     (ExpenseService::canEdit()); delete: the account owner only --}}
@include('layoutmodule::partials.row-menu', [
    'links' => array_merge(
        [['label' => 'View', 'icon' => 'eye', 'url' => route($area . '.expenses.show', $expense->id), 'modal' => true]],
        $expense->attachment ? [['label' => 'Receipt', 'icon' => 'paperclip', 'url' => route($area . '.expenses.attachment', $expense->id)]] : [],
        app(\Modules\PaymentModule\app\Services\ExpenseService::class)->canEdit($expense, Auth::user())
            ? [['label' => 'Edit', 'icon' => 'pencil', 'url' => route($area . '.expenses.edit', $expense->id), 'modal' => true]] : [],
        $area !== 'account' || $expense->is_company ? [] : [
            ['label' => 'Delete', 'icon' => 'trash-2', 'url' => route('account.expenses.delete', $expense->id), 'modal' => true],
        ],
    ),
])
