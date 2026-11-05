@include('layoutmodule::partials.row-menu', [
    'links' => [['label' => 'Edit', 'icon' => 'pencil', 'url' => route('account.hr.allowance-types.edit', $allowanceType->id), 'modal' => true]],
    'actions' => [['view' => 'payrollmodule::Setting.partials.allowance-delete', 'data' => ['allowanceType' => $allowanceType]]],
])
