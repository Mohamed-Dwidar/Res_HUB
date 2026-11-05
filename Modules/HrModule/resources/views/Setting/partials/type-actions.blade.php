@include('layoutmodule::partials.row-menu', [
    'links' => [['label' => 'Edit', 'icon' => 'pencil', 'url' => route('account.hr.document-types.edit', $documentType->id), 'modal' => true]],
    'actions' => [['view' => 'hrmodule::Setting.partials.type-delete', 'data' => ['documentType' => $documentType]]],
])
