<?php

namespace Tests\Unit;

use Modules\PaymentModule\app\Services\Tax\TaxCalculator;
use Modules\PaymentModule\app\Services\Tax\TaxRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// TaxCalculator (docs/payments-plan.md §1): VAT 14% on net + table tax 1%, 2 decimals, discount before tax
class TaxCalculatorTest extends TestCase
{
    private const PRICES_TO = 200000; // every price 0.01 … 2,000.00 (cents)

    private function rule(bool $vat, bool $table, bool $inclusive, string $rounding = 'vat'): TaxRule
    {
        return new TaxRule(14, !$vat, 1, $table, $inclusive, $rounding);
    }

    // cents × rate (hundredths of a percent), half up: the ETA rule written again, to check the calculator against
    private static function percent(int $cents, int $basisPoints): int
    {
        return intdiv($cents * $basisPoints + 5000, 10000);
    }

    public function test_worked_example_exclusive(): void
    {
        $result = (new TaxCalculator)->calculate(1000, 0, $this->rule(true, true, false));

        $this->assertSame([1000.0, 10.0, 141.4, 1151.4], [$result->net, $result->tableTax, $result->vat, $result->total]);
        $this->assertFalse($result->adjusted);
    }

    public function test_worked_example_inclusive(): void
    {
        $result = (new TaxCalculator)->calculate(1000, 0, $this->rule(true, true, true));

        // 1000 ÷ 1.1514 = 868.5079; 868.50: table 8.69 (8.685 half up), VAT (877.19 × 14%) 122.81
        $this->assertSame([868.5, 8.69, 122.81, 1000.0], [$result->net, $result->tableTax, $result->vat, $result->total]);
        $this->assertFalse($result->adjusted);
    }

    public function test_untaxed_and_exempt(): void
    {
        $calculator = new TaxCalculator;
        foreach ([true, false] as $inclusive) {
            $result = $calculator->calculate(1234.56, 0, $this->rule(false, false, $inclusive));
            $this->assertSame([1234.56, 0.0, 0.0, 1234.56], [$result->net, $result->tableTax, $result->vat, $result->total]);
        }
    }

    public function test_discount_before_tax(): void
    {
        $calculator = new TaxCalculator;

        // exclusive: net = price − discount, the taxes on it
        $result = $calculator->calculate(1000, 100, $this->rule(true, true, false));
        $this->assertSame([1000.0, 100.0, 900.0, 9.0, 127.26, 1036.26],
            [$result->sales, $result->discount, $result->net, $result->tableTax, $result->vat, $result->total]);

        // inclusive: the member pays price − discount; sales / discount = their values before tax (868.50 − 781.65)
        $result = $calculator->calculate(1000, 100, $this->rule(true, true, true));
        $this->assertSame(900.0, $result->total);
        $this->assertSame(868.5, $result->sales);
        $this->assertEqualsWithDelta($result->sales - $result->net, $result->discount, 0.001);
        $this->assertSame($calculator->calculate(900, 0, $this->rule(true, true, true))->net, $result->net);
    }

    public function test_negative_amount_is_the_same_split_negated(): void
    {
        $calculator = new TaxCalculator;
        foreach ([true, false] as $inclusive) {
            $plus = $calculator->calculate(5000, 0, $this->rule(true, true, $inclusive));
            $minus = $calculator->calculate(-5000, 0, $this->rule(true, true, $inclusive));
            $this->assertSame([-$plus->net, -$plus->tableTax, -$plus->vat, -$plus->total], [$minus->net, $minus->tableTax, $minus->vat, $minus->total]);
        }
    }

    public static function combinations(): array
    {
        // [VAT, table tax, prices no net can split exactly when inclusive (counted independently, see docs)]
        return [
            'table tax + VAT' => [true, true, 26298],
            'VAT only' => [true, false, 24561],
            'table tax only' => [false, true, 1980],
        ];
    }

    #[DataProvider('combinations')]
    public function test_every_exclusive_price(bool $vat, bool $table): void
    {
        $calculator = new TaxCalculator;
        $rule = $this->rule($vat, $table, false);
        [$t, $v] = [$table ? 100 : 0, $vat ? 1400 : 0];

        for ($cents = 1; $cents <= self::PRICES_TO; $cents++) {
            $result = $calculator->calculate($cents / 100, 0, $rule);
            $tableTax = self::percent($cents, $t);
            $vatAmount = self::percent($cents + $tableTax, $v);
            $this->assertSame([$cents, $tableTax, $vatAmount, $cents + $tableTax + $vatAmount],
                [self::cents($result->net), self::cents($result->tableTax), self::cents($result->vat), self::cents($result->total)], "price $cents");
        }
    }

    #[DataProvider('combinations')]
    public function test_every_inclusive_price_each_rounding(bool $vat, bool $table, int $unreachable): void
    {
        $calculator = new TaxCalculator;
        [$t, $v] = [$table ? 100 : 0, $vat ? 1400 : 0];

        foreach (array_keys(TaxRule::ROUNDINGS) as $rounding) {
            $rule = $this->rule($vat, $table, true, $rounding);
            $adjusted = 0;
            for ($cents = 1; $cents <= self::PRICES_TO; $cents++) {
                $result = $calculator->calculate($cents / 100, 0, $rule);
                [$net, $tableTax, $vatAmount, $extra] = [self::cents($result->net), self::cents($result->tableTax), self::cents($result->vat), self::cents($result->extraDiscount)];

                // always: what is owed = the price exactly
                $this->assertSame($cents, $net + $tableTax + $vatAmount - $extra, "$rounding price $cents");
                $this->assertSame($cents, self::cents($result->total), "$rounding price $cents");

                if (!$result->adjusted) {
                    // an exact split: every tax = its rate on its base
                    $this->assertSame([self::percent($net, $t), self::percent($net + $tableTax, $v), 0], [$tableTax, $vatAmount, $extra], "$rounding price $cents");
                    continue;
                }

                $adjusted++;
                if ($rounding === 'vat' && !$vat) {
                    // VAT exempt: no VAT ever, the table tax takes the cent
                    $this->assertSame(0, $vatAmount, "vat price $cents");
                    $this->assertSame(1, abs($tableTax - self::percent($net, $t)), "vat price $cents");
                } elseif ($rounding === 'vat') {
                    // net and table tax exact, VAT 0.01 off its formula
                    $this->assertSame(self::percent($net, $t), $tableTax, "vat price $cents");
                    $this->assertSame(1, abs($vatAmount - self::percent($net + $tableTax, $v)), "vat price $cents");
                } elseif ($rounding === 'net') {
                    // the taxes are 0.01 at most off their formula on the absorbing net
                    $this->assertLessThanOrEqual(1, abs($tableTax - self::percent($net, $t)), "net price $cents");
                    $this->assertLessThanOrEqual(1, abs($vatAmount - self::percent($net + $tableTax, $v)), "net price $cents");
                } else {
                    // every tax exact, the excess a small after-tax discount
                    $this->assertSame([self::percent($net, $t), self::percent($net + $tableTax, $v)], [$tableTax, $vatAmount], "extra price $cents");
                    $this->assertGreaterThan(0, $extra);
                    $this->assertLessThanOrEqual(2, $extra, "extra price $cents");
                }
            }
            $this->assertSame($unreachable, $adjusted, $rounding);
        }
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
