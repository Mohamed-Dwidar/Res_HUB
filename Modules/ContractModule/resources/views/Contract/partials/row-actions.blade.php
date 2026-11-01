@php
    $area = request()->routeIs('employee.*') ? 'employee' : 'account';
    $contractService = app(\Modules\ContractModule\app\Services\ContractService::class);
@endphp
@include('layoutmodule::partials.row-menu', [
    'links' => [
        ['label' => 'View', 'icon' => 'eye', 'url' => route($area . '.contracts.show', $contract->id), 'modal' => true],
        ['label' => 'PDF (AR)', 'icon' => 'file-down', 'url' => route($area . '.contracts.pdf', ['id' => $contract->id, 'lang' => 'ar']), 'blank' => true],
        ...($contractService->can($contract, 'edit', Auth::user()) ? [['label' => 'Edit Draft', 'icon' => 'pencil', 'url' => route($area . '.contracts.edit', $contract->id)]] : []),
        ...($contractService->can($contract, 'sign', Auth::user()) ? [['label' => 'Upload Signed', 'icon' => 'upload', 'url' => route($area . '.contracts.signed', $contract->id), 'modal' => true]] : []),
        ...($contract->reservation_id ? [['label' => 'Reservation', 'icon' => 'calendar-check', 'url' => route($area . '.reservations.show', $contract->reservation_id), 'modal' => true]] : []),
    ],
])
