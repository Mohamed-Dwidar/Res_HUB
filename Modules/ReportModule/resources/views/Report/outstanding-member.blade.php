@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

{{-- Report 1 popup: one member's owed / credit rows under the report's filters; a row opens its reservation / package --}}
@section('title')
    {{ $member?->name ?? 'No member' }}
    @if ($member?->phone)
        <div class="text-xs font-normal text-slate-500">{{ $member->phone }}</div>
    @endif
@endsection

@section('modal-size', 'xl')

@if ($member && Gate::allows('reports.member_statement'))
    @section('actions')
        <a href="{{ route($area . '.reports.member-statement', ['member_id' => $member->id]) }}" class="btn btn-secondary"><i data-lucide="scroll-text"></i> Statement</a>
    @endsection
@endif

@section('content')
    @php($rows = $reservations->count() + $packages->count())
    @if (!$rows)
        <div class="empty-state">Nothing owed and no credit under these filters.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>No.</th><th>For</th><th>Period</th><th class="text-right">Due</th><th class="text-right">Paid</th><th class="text-right">Remaining</th></tr>
                </thead>
                <tbody>
                    @foreach ($reservations as $reservation)
                        <tr>
                            <td class="whitespace-nowrap">
                                <a href="{{ route($area . '.reservations.show', $reservation->id) }}" data-modal>#{{ $reservation->id }}</a>
                                @if (!$reservation->status?->is_counted)
                                    <div><span class="badge badge-status-danger">{{ $reservation->status?->name ?? 'Cancelled' }}</span></div>
                                @endif
                            </td>
                            <td>
                                @include('reservationmodule::Reservation.partials.row-place')
                                <div class="text-xs text-slate-500">{{ $reservation->plan?->name }}</div>
                            </td>
                            <td class="whitespace-nowrap">
                                {{ $reservation->start_at?->format('d-m-Y') }}
                                @if ($reservation->end_at && !$reservation->end_at->isSameDay($reservation->start_at))
                                    &rarr; {{ $reservation->end_at->format('d-m-Y') }}
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap">{{ number_format($reservation->payableAmount(), 2) }}</td>
                            <td class="text-right whitespace-nowrap">{{ number_format($reservation->paid_amount, 2) }}</td>
                            <td class="text-right whitespace-nowrap">@include('reservationmodule::Reservation.partials.row-remaining')</td>
                        </tr>
                    @endforeach
                    @foreach ($packages as $package)
                        <tr>
                            <td class="whitespace-nowrap"><a href="{{ route($area . '.packages.show', $package->id) }}" data-modal>Package #{{ $package->id }}</a></td>
                            <td>{{ $package->name }}</td>
                            <td class="whitespace-nowrap">{{ $package->date_from?->format('d-m-Y') ?? '-' }} @if ($package->date_to) &rarr; {{ $package->date_to->format('d-m-Y') }} @endif</td>
                            <td class="text-right whitespace-nowrap">{{ number_format($package->payableAmount(), 2) }}</td>
                            <td class="text-right whitespace-nowrap">{{ number_format($package->paid_amount, 2) }}</td>
                            <td class="text-right whitespace-nowrap">@include('reportmodule::Report.partials.money', ['value' => $package->remaining, 'class' => $package->remaining < 0 ? 'money-withholding' : 'money-out'])</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="report-total">
                        <td colspan="5">Total ({{ $rows }})</td>
                        <td class="text-right whitespace-nowrap">{{ number_format($reservations->sum('remaining') + $packages->sum('remaining'), 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
@endsection
