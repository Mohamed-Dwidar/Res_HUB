<?php

namespace Modules\PaymentModule\app\Services\Tax;

/**
 * The ONE tax calculation (docs/payments-plan.md §1), in whole cents (no float rounding), each amount rounded to 2 decimals
 * half up:
 *   table tax = net × table rate;  VAT = (net + table tax) × VAT rate;  total = net + table tax + VAT
 * Discount before tax. Exclusive price: net = price − discount. Inclusive price: total = price − discount and the net is worked
 * back: net = total ÷ ((1 + table) × (1 + VAT)), then the nearest net (± 2 cents) whose taxes add up to the total exactly. About
 * 13% of totals (table tax + VAT) can't be reached by any net: the rule's rounding option decides who absorbs the cent —
 * vat (VAT = total − net − table tax; the table tax when VAT is exempt), net (net = total − taxes) or extra_discount (the smallest net above, the excess as an
 * after-tax discount). Negative amounts (returns) = the same split, negated.
 */
class TaxCalculator
{
    public function calculate(float $price, float $discount, TaxRule $rule): TaxResult
    {
        $priceCents = self::cents($price);
        $afterCents = $priceCents - self::cents($discount);
        $table = $rule->tableBasisPoints();
        $vat = $rule->vatBasisPoints();

        if (!$rule->inclusive) {
            [$tableTax, $vatAmount] = self::taxes($afterCents, $table, $vat);
            return $this->result($priceCents, $afterCents, $tableTax, $vatAmount, 0, false, $rule);
        }

        // inclusive: the price before tax of the full price (sales) and of the discounted price (net), the discount = the gap
        [$net, $tableTax, $vatAmount, $extra, $adjusted] = self::split($afterCents, $table, $vat, $rule->rounding);
        $sales = $priceCents === $afterCents ? $net : self::split($priceCents, $table, $vat, $rule->rounding)[0];

        return $this->result(max($sales, $net), $net, $tableTax, $vatAmount, $extra, $adjusted, $rule);
    }

    // [net, table tax, VAT, extra discount, adjusted] of an inclusive total (cents)
    private static function split(int $total, int $table, int $vat, string $rounding): array
    {
        $sign = $total < 0 ? -1 : 1;
        $total = abs($total);
        if ($table === 0 && $vat === 0) {
            return [$sign * $total, 0, 0, 0, false];
        }

        // the nearest net: total × 10000² ÷ ((10000 + table) × (10000 + vat)), half up (fits in 64 bits up to 99,999,999.99)
        $denominator = (10000 + $table) * (10000 + $vat);
        $guess = intdiv(2 * $total * 100000000 + $denominator, 2 * $denominator);

        foreach ([0, -1, 1, -2, 2] as $step) {
            $net = $guess + $step;
            [$tableTax, $vatAmount] = self::taxes($net, $table, $vat);
            if ($net + $tableTax + $vatAmount === $total) {
                return [$sign * $net, $sign * $tableTax, $sign * $vatAmount, 0, false];
            }
        }

        [$tableTax, $vatAmount] = self::taxes($guess, $table, $vat);
        if ($rounding === 'net') {
            $net = $total - $tableTax - $vatAmount;
        } elseif ($rounding === 'extra_discount') {
            // the smallest net whose total passes the price; the excess (≤ 2 cents) = an after-tax discount
            $net = $guess - 3;
            do {
                [$tableTax, $vatAmount] = self::taxes(++$net, $table, $vat);
            } while ($net + $tableTax + $vatAmount < $total);
            return [$sign * $net, $sign * $tableTax, $sign * $vatAmount, $sign * ($net + $tableTax + $vatAmount - $total), true];
        } elseif ($vat === 0) {
            // 'vat' on a VAT exempt line: the last tax applied (the table tax) takes the cent, never a VAT on an exempt line
            $net = $guess;
            $tableTax = $total - $net;
        } else {
            $net = $guess;
            $vatAmount = $total - $net - $tableTax;
        }

        return [$sign * $net, $sign * $tableTax, $sign * $vatAmount, 0, true];
    }

    // [table tax, VAT] of a net (cents), the ETA order: VAT on net + table tax
    private static function taxes(int $net, int $table, int $vat): array
    {
        $tableTax = self::percent($net, $table);
        return [$tableTax, self::percent($net + $tableTax, $vat)];
    }

    // cents × rate (hundredths of a percent), rounded half away from zero
    private static function percent(int $cents, int $basisPoints): int
    {
        $value = intdiv(abs($cents) * $basisPoints + 5000, 10000);
        return $cents < 0 ? -$value : $value;
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function result(int $sales, int $net, int $tableTax, int $vat, int $extra, bool $adjusted, TaxRule $rule): TaxResult
    {
        return new TaxResult(
            $sales / 100, ($sales - $net) / 100, $net / 100, $tableTax / 100, $vat / 100, $extra / 100,
            ($net + $tableTax + $vat - $extra) / 100, $adjusted, $rule,
        );
    }
}
