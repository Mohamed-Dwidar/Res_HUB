@include('layoutmodule::partials.row-menu', [
    'links' => [
        ['label' => 'View', 'icon' => 'eye', 'url' => route('account.employees.show', $employee->id), 'modal' => true],
        ['label' => 'Edit', 'icon' => 'pencil', 'url' => route('account.employees.edit', $employee->id), 'modal' => true],
    ],
    'actions' => [
        ['view' => 'employeemodule::Account.Employee.partials.left-form', 'data' => ['employee' => $employee]],
        ['view' => 'employeemodule::Account.Employee.partials.delete-form', 'data' => ['employee' => $employee]],
    ],
])
