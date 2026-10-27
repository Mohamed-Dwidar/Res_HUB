{{--
    The All / Cash Out list table (DataTables, PaymentActions::cashData()): Type (centered) | Amount | Date | For / Item | Member or
    Category | Method | By (account only, $showBy) | row menu (order: user 2026-10-28). $filters: the filters form id, $direction: all | out (in the data URL).
--}}
@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
<div class="table-wrap mt-5">
    <table class="data-table" data-datatable data-url="{{ route($area . '.payments.cash-data', ['direction' => $direction]) }}"
        data-filters="{{ $filters }}" data-order='[[2, "desc"]]' data-empty="{{ $empty ?? 'Nothing here.' }}">
        <thead>
            <tr>
                <th data-data="type" data-class="text-center">Type</th>
                <th data-data="amount" data-name="amount" data-class="whitespace-nowrap">Amount</th>
                <th data-data="at_date" data-name="at_date" data-class="whitespace-nowrap">Date</th>
                <th data-data="what">For / Item</th>
                <th data-data="party">Member / Category</th>
                <th data-data="method">Method</th>
                @if (!empty($showBy))
                    <th data-data="by">By</th>
                @endif
                <th data-data="actions"></th>
            </tr>
        </thead>
    </table>
</div>
