{{-- the extras block of a package view (Extra/partials/extras, shared with the reservation view since 2026-11-08) --}}
@include('paymentmodule::Extra.partials.extras', ['extrable' => $package, 'kind' => 'package'])
