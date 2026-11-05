@include('layoutmodule::partials.row-menu', [
    'links' => array_filter([
        ['label' => 'Open', 'icon' => 'folder-open', 'url' => route($area . '.hr.staff.show', $staff->id)],
        ['label' => 'Edit', 'icon' => 'pencil', 'url' => route($area . '.hr.staff.edit', $staff->id), 'modal' => true],
        ['label' => 'Change Status', 'icon' => 'user-cog', 'url' => route($area . '.hr.staff.status', $staff->id), 'modal' => true],
    ]),
    'actions' => $area === 'account' ? [['view' => 'hrmodule::Staff.partials.delete-form', 'data' => ['staff' => $staff]]] : [],
])
