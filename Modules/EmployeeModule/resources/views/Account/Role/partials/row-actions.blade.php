@include('layoutmodule::partials.row-menu', [
    'links' => [
        ['label' => 'Edit', 'icon' => 'pencil', 'url' => route('account.settings.roles.edit', $role->id), 'modal' => true],
        ['label' => 'Duplicate', 'icon' => 'copy', 'url' => route('account.settings.roles.create', ['from' => $role->id]), 'modal' => true],
    ],
    'actions' => [['view' => 'employeemodule::Account.Role.partials.delete-form', 'data' => ['role' => $role]]],
])
