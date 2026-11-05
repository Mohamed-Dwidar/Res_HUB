@include('layoutmodule::partials.row-menu', [
    'links' => [['label' => 'Edit', 'icon' => 'pencil', 'url' => route('account.hr.leave-types.edit', $leaveType->id), 'modal' => true]],
    'actions' => [['view' => 'hrmodule::Setting.partials.leave-type-delete', 'data' => ['leaveType' => $leaveType]]],
])
