@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@include('layoutmodule::partials.row-menu', [
    'links' => [
        ['label' => 'View', 'icon' => 'eye', 'url' => route($area . '.packages.show', $package->id), 'modal' => true],
        ['label' => 'Edit', 'icon' => 'pencil', 'url' => route($area . '.packages.edit', $package->id), 'modal' => true],
        // always: a package may be paid beyond its due (credit, 2026-10-21)
        ['label' => 'Add Cash In', 'icon' => 'wallet', 'url' => route($area . '.payments.create', ['payable' => 'package', 'payable_id' => $package->id]), 'modal' => true],
    ],
    'actions' => [
        ['view' => 'packagemodule::Package.partials.delete-form', 'data' => ['package' => $package], 'can' => 'packages.delete'],
    ],
])
