{{-- the statement under the toolbar (MemberStatementReport::statement(); null = no member chosen) --}}
@php
    use Modules\ReportModule\app\Services\MemberStatementReport;
    $dateFormat = \Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT;
    $balanceClass = fn ($value) => $value > 0.004 ? 'money-out' : ($value < -0.004 ? 'money-withholding' : 'money-zero');
@endphp
@if (!$statement)
    <div class="box p-5 text-slate-500">Choose a member to see the statement.</div>
@else
    @php $member = $statement['member']; @endphp
    <div class="statement-head">
        <div>
            <div class="text-base font-medium">{{ $member->name }}
                @if ($statement['commitment']['percent'] !== null)
                    @php $cancelled = $statement['commitment']['total'] - $statement['commitment']['counted']; @endphp
                    @include('reportmodule::Report.partials.member-commitment', ['row' => (object) ['commitment' => $statement['commitment']['percent'],
                        'reservations' => $statement['commitment']['total'], 'cancelled' => $cancelled]])
                    <span class="text-xs font-normal text-slate-500">{{ $statement['commitment']['total'] }} {{ Str::plural('reservation', $statement['commitment']['total']) }} · {{ $cancelled }} cancelled</span>
                @endif
            </div>
            <div class="text-xs text-slate-500">{{ collect([$member->phone, $member->company?->name])->filter()->implode(' · ') }}</div>
            <div class="text-xs text-slate-500">
                {{ $statement['from'] ? $statement['from']->format($dateFormat) : 'From the start' }}
                → {{ $statement['to'] ? $statement['to']->format($dateFormat) : 'today and later' }}
            </div>
        </div>
        <div class="money-box">
            <div class="money-box__item money-box__item--main {{ $statement['closing'] < 0 ? 'money-box__item--credit' : 'money-box__item--owed' }}">
                <span>{{ $statement['closing'] < 0 ? 'Closing (credit)' : 'Closing (owed)' }}</span><b>{{ number_format(abs($statement['closing']), 2) }}</b>
            </div>
            <div class="money-box__item"><span>Opening</span><b>{{ number_format($statement['opening'], 2) }}</b></div>
            <div class="money-box__item money-box__item--due"><span>Charges</span><b>{{ number_format($statement['charges'], 2) }}</b></div>
            <div class="money-box__item money-box__item--paid"><span>Payments</span><b>{{ number_format($statement['payments'], 2) }}</b></div>
        </div>
    </div>

    <div class="table-wrap mt-5">
        <table class="data-table statement-lines">
            <thead>
                <tr>
                    <th class="whitespace-nowrap">Date</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th class="text-right">Charge</th>
                    <th class="text-right">Payment</th>
                    <th class="text-right">Balance</th>
                </tr>
            </thead>
            <tbody>
                <tr class="report-total">
                    <td></td>
                    <td colspan="4">Opening balance</td>
                    <td class="text-right whitespace-nowrap">@include('reportmodule::Report.partials.money', ['value' => $statement['opening'], 'class' => $balanceClass($statement['opening'])])</td>
                </tr>
                @forelse ($statement['lines'] as $line)
                    @php [$type, $text] = MemberStatementReport::describe($line); @endphp
                    <tr @class(['row-muted' => $line['kind'] === 'package_reservation' || ($line['kind'] === 'reservation' && $line['charge'] == 0)])>
                        <td class="whitespace-nowrap">{{ $line['day']->format($dateFormat) }}</td>
                        <td class="whitespace-nowrap">{{ $type }}</td>
                        <td>{{ $text }}</td>
                        <td class="text-right whitespace-nowrap">@if ($line['kind'] !== 'payment')@include('reportmodule::Report.partials.money', ['value' => $line['charge']])@endif</td>
                        <td class="text-right whitespace-nowrap">@if ($line['kind'] === 'payment')@include('reportmodule::Report.partials.money', ['value' => $line['payment'], 'class' => $line['payment'] < 0 ? 'money-out' : 'money-in'])@endif</td>
                        <td class="text-right whitespace-nowrap">@include('reportmodule::Report.partials.money', ['value' => $line['balance'], 'class' => $balanceClass($line['balance'])])</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty-state">Nothing in these dates.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="report-total">
                    <td></td>
                    <td colspan="2">Closing balance ({{ count($statement['lines']) }} lines)</td>
                    <td class="text-right whitespace-nowrap">{{ number_format($statement['charges'], 2) }}</td>
                    <td class="text-right whitespace-nowrap">{{ number_format($statement['payments'], 2) }}</td>
                    <td class="text-right whitespace-nowrap">@include('reportmodule::Report.partials.money', ['value' => $statement['closing'], 'class' => $balanceClass($statement['closing'])])</td>
                </tr>
            </tfoot>
        </table>
    </div>
@endif
