<?php

namespace Modules\PaymentModule\app\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\PackageModule\app\Models\Package;
use Modules\PackageModule\app\Services\PackageService;
use Modules\ReservationModule\app\Models\Reservation;

/**
 * The combined invoice PDF (2026-11-08, user; paymentmodule::Payment.invoice-pdf, AR / EN; items before discount, then Subtotal,
 * Discount, Net before tax, VAT, Table tax, Total): ONE document for a reservation
 * paid on its own (the booking + its extras) or a package (the package + its extras), with the totals (discount only when
 * there is one, net before tax, VAT, table tax (> 0), total), every payment of it, paid and remaining. NOT the official tax
 * invoice: with "Invoice requested" the footer says the electronic invoice is issued through the ETA portal.
 * Amounts are read, never re-derived: a reservation's taxes = its own snapshot + its extras' (ExtraService::applyTax()),
 * the total = Reservation::totalDue() / Package::payableAmount(); a package's taxes only once Phase C fills its tax columns.
 */
class InvoicePdfService
{
    public function __construct(private ExtraService $extraService, private PackageService $packageService)
    {
    }

    // what the invoice can be made for: a reservation paid on its own (not inside a package: the package has it; counted), a package
    public function error(Model $payable): ?string
    {
        if ($payable instanceof Reservation && $payable->package_id) {
            return 'This reservation is inside a package: print the package invoice.';
        }
        if ($payable instanceof Reservation && !$payable->isCounted()) {
            return 'This reservation is cancelled: nothing is owed.';
        }
        return null;
    }

    public function data($accountId, string $kind, $id): array
    {
        $payable = $this->extraService->findExtrable($accountId, $kind, $id);
        abort_if($error = $this->error($payable), 422, $error ?? '');
        $payable->loadMissing('member.company', 'account');
        $extras = $payable->extrasList();

        if ($payable instanceof Reservation) {
            $payable->loadMissing('space', 'unit', 'plan', 'status');
            $taxed = $payable->invoice_requested && $payable->isTaxed();
            $price = (float) $payable->amount;
            $net = (float) $payable->net_amount;
            $main = [
                'kind' => 'booking',
                'title' => trim(($payable->space?->name ?? '') . ' › ' . ($payable->unit?->name ?? ''), ' ›'),
                'sub' => trim(($payable->plan?->name ?? '') . ' · ' . $payable->period, ' ·'),
                'date' => $payable->start_at,
                'amount' => $price,
                'netBeforeTax' => $taxed ? (float) $payable->tax_net : $net,
            ];
            $tax = $taxed ? ['vat' => (float) $payable->vat, 'table' => (float) $payable->table_tax, 'discount' => (float) $payable->tax_extra_discount] : null;
            $total = $payable->totalDue();
            $exempt = $taxed && $payable->tax_vat_exempt;
        } else {
            /** @var Package $payable */
            $stats = $this->packageService->statement($accountId, $payable->id);
            $taxed = $payable->invoice_requested && $payable->tax_total !== null;
            $price = (float) $payable->amount;
            $net = (float) $payable->after_discount;
            $main = [
                'kind' => 'package',
                'title' => $payable->name,
                'sub' => null,
                'period' => [$payable->date_from, $payable->date_to],
                'sessions' => $stats['reservations']->count(),
                'hours' => $stats['hours'],
                'date' => $payable->date_from,
                'amount' => $price,
                'netBeforeTax' => $taxed ? (float) $payable->tax_net : $net,
            ];
            $tax = $taxed ? ['vat' => (float) $payable->vat, 'table' => (float) $payable->table_tax, 'discount' => (float) $payable->tax_extra_discount] : null;
            $total = $payable->payableAmount();
            $exempt = false;
        }

        // extras: sales one line each (net before tax = tax_net while taxed); a package's adjustments = one "balance carried over"
        $lines = $extras->where('type', 'extra')->values();
        $adjustments = round((float) $extras->where('type', 'adjustment')->sum('amount'), 2);
        $lineNet = fn ($extra) => (float) ($extra->tax_net ?? $extra->amount);
        if ($tax) {
            foreach (['vat' => 'vat', 'table' => 'table_tax', 'discount' => 'tax_extra_discount'] as $key => $column) {
                $tax[$key] += (float) $lines->sum(fn ($extra) => (float) ($extra->{$column} ?? 0));
            }
        }

        return [
            'payable' => $payable,
            'kind' => $kind,
            'account' => $payable->account,
            'member' => $payable->member,
            'main' => $main,
            'extras' => $lines,
            'lineNet' => $lineNet,
            'adjustments' => $adjustments,
            'price' => $price,
            'discount' => round($price - $net, 2),
            'discountPercent' => rtrim(rtrim(number_format((float) $payable->discount_percentage, 2), '0'), '.'),
            // 2026-11-08 (user): items at their amount BEFORE discount → Subtotal − Discount = Net before tax (taxed: the stored
            // tax_net of the booking / package + the extras', the same figures as the view)
            'subtotal' => round($price + (float) $lines->sum('amount') + $adjustments, 2),
            'netBeforeTax' => round($main['netBeforeTax'] + (float) $lines->sum($lineNet) + $adjustments, 2),
            'tax' => $tax,
            'exempt' => $exempt,
            'total' => round($total, 2),
            'invoiceRequested' => (bool) $payable->invoice_requested,
            'payments' => $payable->payments()->with('paymentMethod')->orderBy('paid_at')->orderBy('payment_no')->get(),
            'paid' => (float) $payable->paid_amount,
            'remaining' => (float) $payable->remaining,
        ];
    }
}
