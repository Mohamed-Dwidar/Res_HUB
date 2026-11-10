{{-- a system category can't be deleted (no Delete) --}}
@include('layoutmodule::partials.row-menu', [
    'links' => [
        ['label' => 'Edit', 'icon' => 'pencil', 'url' => route('account.income-categories.edit', $category->id), 'modal' => true],
    ],
    'actions' => $category->isGenerated() ? [] : [['view' => 'paymentmodule::Category.partials.delete-form', 'data' => ['category' => $category]]],
])
