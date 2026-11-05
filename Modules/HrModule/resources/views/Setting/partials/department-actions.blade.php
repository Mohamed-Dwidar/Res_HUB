@include('layoutmodule::partials.row-menu', [
    'links' => [['label' => 'Edit', 'icon' => 'pencil', 'url' => route('account.hr.departments.edit', $department->id), 'modal' => true]],
    'actions' => [['view' => 'hrmodule::Setting.partials.department-delete', 'data' => ['department' => $department]]],
])
