@include('layoutmodule::partials.row-menu', [
    'links' => [['label' => 'Edit', 'icon' => 'pencil', 'url' => route('account.hr.adjustment-types.edit', $adjustmentType->id), 'modal' => true]],
    'actions' => [['view' => 'payrollmodule::Setting.partials.adjustment-delete', 'data' => ['adjustmentType' => $adjustmentType]]],
])
