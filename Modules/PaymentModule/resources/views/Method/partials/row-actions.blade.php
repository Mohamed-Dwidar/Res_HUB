{{-- a system method (Offset): no actions --}}
@unless ($method->isGenerated())
@include('layoutmodule::partials.row-menu', [
    'links' => [
        ['label' => 'Edit', 'icon' => 'pencil', 'url' => route('account.payment-methods.edit', $method->id), 'modal' => true],
    ],
    'actions' => [['view' => 'paymentmodule::Method.partials.delete-form', 'data' => ['method' => $method]]],
])
@endunless
