<?php

namespace Modules\PaymentModule\app\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\PackageModule\app\Models\Package;
use Modules\PaymentModule\app\Models\Extra;
use Modules\PaymentModule\app\Models\IncomeCategory;
use Modules\PaymentModule\app\Services\Tax\TaxCalculator;
use Modules\PaymentModule\app\Services\Tax\TaxRule;
use Modules\PaymentModule\app\Services\Tax\TaxSnapshot;
use Modules\ReservationModule\app\Models\Reservation;

/**
 * Extras of an account's packages and reservations (2026-11-08: reservations too). Every method takes the owner account id.
 * Employees add (ExtraRequest: a sale, positive price); edit / delete = the account.
 * A reservation's extra: only on a counted reservation paid on its own (inside a package → add it on the package); its taxes
 * follow the reservation (applyTax(): its tax snapshot, amounts only while its invoice is requested) and the stored
 * reservations.extras_total (Σ tax_total ?: amount) is part of what it owes (Reservation::payableAmount()). A package's extras:
 * untaxed for now (Phase C).
 * extra = a sale: needs an income category (revenue, invoiceable in phase 2); adjustment = a balance move: no category, any sign.
 * amount = quantity × unit_price; after every change the package paid / remaining are refreshed (due = net + Σ extras).
 * Rule errors = ValidationException (also safe for imports / commands).
 */
class ExtraService
{
    // the payables that can have extras => their models
    const EXTRABLES = [
        'package' => Package::class,
        'reservation' => Reservation::class,
    ];

    // the kind of an extrable model ('package' | 'reservation')
    public static function kindOf(Model $extrable): string
    {
        return $extrable instanceof Reservation ? 'reservation' : 'package';
    }

    private PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    // the package / reservation of the account (404 otherwise)
    public function findExtrable($accountId, string $kind, $id): Model
    {
        $class = self::EXTRABLES[$kind] ?? abort(404);
        return $class::where('account_id', $accountId)->when($class === Reservation::class, fn ($query) => $query->with('status'))->findOrFail($id);
    }

    // why extras can't be added to this reservation (null = they can; a package: always)
    public function extrableError(Model $extrable): ?string
    {
        if (!$extrable instanceof Reservation) {
            return null;
        }
        if (!$extrable->canTakePayments()) {
            return 'This reservation is inside a package: add it on the package.';
        }
        return $extrable->isCounted() ? null : 'This reservation is ' . strtolower((string) $extrable->status?->name) . ': extras can\'t be added.';
    }

    // 404 when it belongs to another account
    public function findOne($accountId, $id): Extra
    {
        return Extra::with('extrable', 'incomeCategory')->where('account_id', $accountId)->findOrFail($id);
    }

    // $data: type, income_category_id, item, quantity, unit_price, date, notes
    public function create($accountId, Model $extrable, array $data, Model $creator): Extra
    {
        if ($error = $this->extrableError($extrable)) {
            throw ValidationException::withMessages(['item' => $error]);
        }
        $values = $this->extraData($accountId, $data, $extrable);

        return DB::transaction(function () use ($accountId, $extrable, $values, $creator) {
            $extra = new Extra($values + ['account_id' => $accountId]);
            $extra->extrable()->associate($extrable);
            $extra->creatable()->associate($creator);
            $this->applyTax($extra, $extrable);
            $extra->save();
            $this->afterChange($extrable);

            return $extra;
        });
    }

    public function update($accountId, $id, array $data): Extra
    {
        $extra = $this->findOne($accountId, $id);
        $values = $this->extraData($accountId, $data, $extra->extrable);

        return DB::transaction(function () use ($extra, $values) {
            $extra->fill($values);
            $this->applyTax($extra, $extra->extrable);
            $extra->save();
            $this->afterChange($extra->extrable);

            return $extra;
        });
    }

    // soft delete
    public function deleteOne($accountId, $id): Extra
    {
        $extra = $this->findOne($accountId, $id);

        return DB::transaction(function () use ($extra) {
            $extra->delete();
            $this->afterChange($extra->extrable);

            return $extra;
        });
    }

    /**
     * A reservation saved (ReservationService::update()): its invoice / tax type may have changed → every extra re-snapshotted
     * from the reservation (2026-11-08 rule) + extras_total. No extras = nothing.
     */
    public function retax(Reservation $reservation): void
    {
        $extras = $reservation->extras()->get();
        if ($extras->isEmpty()) {
            return;
        }
        foreach ($extras as $extra) {
            $this->applyTax($extra, $reservation);
            if ($extra->isDirty()) {
                $extra->save();
            }
        }
        $this->syncTotal($reservation);
    }

    /**
     * The taxes of an extra (user 2026-11-08: extras follow their parent, income categories carry no taxes): a reservation's
     * extra copies the reservation's tax snapshot (VAT rate / exempt, table tax, price includes tax, rounding) and gets the
     * amounts only while the reservation's invoice is requested; no invoice / no tax type = no taxes. Package extras: none yet
     * (Phase C = the same rule with the package's snapshot).
     */
    private function applyTax(Extra $extra, Model $extrable): void
    {
        // a category flagged `untaxed` (Rounding, 2026-11-10): never taxes
        $rule = $extrable instanceof Reservation && $extrable->invoice_requested && !$this->untaxed($extra) ? TaxRule::fromSnapshot($extrable) : null;
        if (!$rule) {
            $extra->forceFill(array_fill_keys(array_merge(TaxSnapshot::TYPE, TaxSnapshot::AMOUNTS), null));
            return;
        }

        $result = app(TaxCalculator::class)->calculate((float) $extra->amount, 0, $rule);
        $extra->forceFill($rule->toColumns() + [
            'tax_net' => $result->net, 'table_tax' => $result->tableTax, 'vat' => $result->vat,
            'tax_extra_discount' => $result->extraDiscount, 'tax_total' => $result->total,
        ]);
    }

    // the reservation form's "will owe X more / less" (custom.js reservationTax()): its extras' amounts before tax — the form
    // taxes them with the same rule as the booking, like applyTax()
    // (untaxed ones left out: the invoice toggle never changes them)
    public function amountsOf(Reservation $reservation): array
    {
        return $reservation->extras()->with('incomeCategory')->get()->reject(fn ($extra) => $this->untaxed($extra))
            ->map(fn ($extra) => (float) $extra->amount)->values()->all();
    }

    // an extra of a system category flagged `untaxed` (Rounding)
    private function untaxed(Extra $extra): bool
    {
        if (!$extra->income_category_id) {
            return false;
        }
        $category = $extra->relationLoaded('incomeCategory') && $extra->incomeCategory?->id === (int) $extra->income_category_id
            ? $extra->incomeCategory : IncomeCategory::find($extra->income_category_id);
        return (bool) $category?->generatedFlag('untaxed');
    }

    // after an extra changed: a reservation's stored total, then paid / remaining
    private function afterChange(Model $extrable): void
    {
        if ($extrable instanceof Reservation) {
            $this->syncTotal($extrable);
        } else {
            $this->paymentService->refresh($extrable);
        }
    }

    // reservations.extras_total = Σ (tax_total ?: amount) of its extras (query update: no updated_at, no events), then refresh()
    private function syncTotal(Reservation $reservation): void
    {
        $total = round((float) Extra::where('extrable_type', $reservation->getMorphClass())->where('extrable_id', $reservation->id)
            ->sum(DB::raw('COALESCE(tax_total, amount)')), 2);
        $reservation->newQueryWithoutScopes()->whereKey($reservation->id)->toBase()->update(['extras_total' => $total]);
        $reservation->forceFill(['extras_total' => $total]);
        $this->paymentService->refresh($reservation->loadMissing('status'));
    }

    private function extraData($accountId, array $data, Model $extrable): array
    {
        $type = $data['type'] ?? 'extra';
        if (!isset(Extra::TYPES[$type])) {
            throw ValidationException::withMessages(['type' => 'Choose extra or adjustment.']);
        }
        // a reservation: sales only (an adjustment = a balance move: the package's tool)
        if ($type !== 'extra' && $extrable instanceof Reservation) {
            throw ValidationException::withMessages(['type' => 'A reservation takes extras (sales) only.']);
        }
        $categoryId = $type === 'extra' ? (int) ($data['income_category_id'] ?? 0) : 0;
        if ($type === 'extra' && !IncomeCategory::where('account_id', $accountId)->whereKey($categoryId)->exists()) {
            throw ValidationException::withMessages(['income_category_id' => 'Choose the income category of this extra.']);
        }

        // an adjustment = one amount (quantity 1)
        $quantity = $type === 'adjustment' ? 1.0 : round((float) ($data['quantity'] ?? 1), 2);
        $unitPrice = round((float) ($data['unit_price'] ?? 0), 2);
        $amount = round($quantity * $unitPrice, 2);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'The quantity must be more than 0.']);
        }
        if ($amount == 0) {
            throw ValidationException::withMessages(['unit_price' => 'The amount can\'t be 0.']);
        }

        return [
            'type' => $type,
            'income_category_id' => $categoryId,
            'item' => trim($data['item']),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $amount,
            'date' => Carbon::parse($data['date'] ?? today())->toDateString(),
            'notes' => $data['notes'] ?? null,
        ];
    }
}
