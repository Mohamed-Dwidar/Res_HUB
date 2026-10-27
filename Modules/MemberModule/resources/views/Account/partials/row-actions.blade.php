@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@include('layoutmodule::partials.row-menu', [
    'links' => array_merge([
        ['label' => 'View', 'icon' => 'eye', 'url' => route($area . '.members.show', $member->id), 'modal' => true],
        ['label' => 'Edit', 'icon' => 'pencil', 'url' => route($area . '.members.edit', $member->id), 'modal' => true],
        // the member's lists (2026-10-27): reservations (All tab, cancelled too); payments = the account only (employees' payments
        // list is their own collections of one day)
        ['label' => 'Reservations', 'icon' => 'calendar-check', 'url' => route($area . '.reservations.index', ['member_id' => $member->id])],
    ],
        $area === 'account' ? [['label' => 'Payments', 'icon' => 'wallet', 'url' => route('account.payments.index', ['type' => 'in', 'member_id' => $member->id])]] : [],
    ),
    // delete: the account only (a member is deleted for good)
    'actions' => $area === 'account' ? [['view' => 'membermodule::Account.partials.delete-form', 'data' => ['member' => $member]]] : [],
])
