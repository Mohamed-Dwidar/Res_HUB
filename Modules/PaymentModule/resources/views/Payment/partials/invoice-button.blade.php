{{--
    The combined invoice PDF icon of a view header (2026-11-08): a reservation paid on its own / a package; a menu Invoice (EN) |
    Invoice (AR), new tab. Not shown for a reservation inside a package (the package has it) or a cancelled one
    (InvoicePdfService::error()), nor without the receipts' right (payments.receipt).
    @include('paymentmodule::Payment.partials.invoice-button', ['payable' => $reservation, 'kind' => 'reservation'])
--}}
@php($invoiceArea = request()->routeIs('employee.*') ? 'employee' : 'account')
@if (Gate::allows('payments.receipt') && !app(\Modules\PaymentModule\app\Services\InvoicePdfService::class)->error($payable))
    <div data-tw-merge="" data-tw-placement="bottom-end" class="dropdown relative">
        <button type="button" data-tw-toggle="dropdown" aria-expanded="false" class="btn btn-secondary" title="Invoice PDF">
            <i data-lucide="file-text"></i>
        </button>
        <div class="dropdown-menu js-menu absolute z-[9999]">
            <div data-tw-merge="" class="dropdown-content rounded-md border-transparent bg-white p-2 shadow-[0px_3px_10px_#00000017] dark:border-transparent dark:bg-darkmode-600 w-40">
                @foreach (['en' => 'Invoice (EN)', 'ar' => 'Invoice (AR)'] as $invoiceLang => $invoiceLabel)
                    <a href="{{ route($invoiceArea . '.payments.invoice', [$kind, $payable->id] + ($invoiceLang === 'ar' ? ['lang' => 'ar'] : [])) }}" target="_blank" rel="noopener"
                        data-tw-dismiss="dropdown" class="{{ config('layoutmodule.menu.item') }}">
                        <i data-lucide="file-text" class="{{ config('layoutmodule.menu.icon') }}"></i> {{ $invoiceLabel }}
                    </a>
                @endforeach
            </div>
        </div>
    </div>
@endif
