<?php

namespace Modules\PaymentModule\app\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AccountModule\app\Models\Account;
use Modules\PaymentModule\app\Models\Expense;
use Modules\PaymentModule\app\Models\ExpenseCategory;
use Modules\PaymentModule\app\Models\PaymentMethod;
use Modules\PaymentModule\app\Models\ShiftClosing;
use Modules\PaymentModule\app\Repositories\ExpenseRepository;

/**
 * Expenses of an account (docs/payments-plan.md "Expenses"): every method takes the owner account id.
 * - add: any login of the account → the expense joins the recorder's OPEN shift (cash leaves their drawer)
 * - edit / delete: the account only; an expense of a CLOSED shift needs a reason → ShiftClosingService::paymentChanged()
 *   (totals recalculated, logged before / after, "Edited after closing") — the same rules as payments
 * - expense_no: per account, MAX + 1 inside the transaction (locked), from config paymentmodule.number_start
 * - the receipt is a private file (storage/app/private/expenses/{account_id}/), downloaded through the app
 * The rules errors are thrown as ValidationException (422, shown under the fields by custom.js).
 */
class ExpenseService
{
    private ExpenseRepository $expenseRepository;
    private ShiftClosingService $shiftService;

    public function __construct(ExpenseRepository $expenseRepository, ShiftClosingService $shiftService)
    {
        $this->expenseRepository = $expenseRepository;
        $this->shiftService = $shiftService;
    }

    // list query (paged / ordered by DataTables)
    public function listQuery($accountId, array $filters = [])
    {
        return $this->expenseRepository->forAccount($accountId)->filter($filters);
    }

    // 404 when the expense belongs to another account
    public function findOne($accountId, $id): Expense
    {
        return $this->expenseRepository->forAccount($accountId)->with('creatable.userable')->findOrFail($id);
    }

    // edit / delete: the account login only
    public function isOwner(Model $user): bool
    {
        return $user->userable instanceof Account;
    }

    /**
     * Record an expense (any login): $data = expense_category_id, item, amount, payment_method_id, spent_at, notes, attachment.
     * It joins $creator's open shift.
     */
    public function create($accountId, array $data, Model $creator): Expense
    {
        $values = $this->values($accountId, $data);
        // the date (2026-10-27): the business date of the recording time; only the account owner gives another one (reports only)
        $values['spent_at'] = app(PaymentService::class)->recordDate($accountId, $data['spent_at'] ?? null, $creator)->toDateString();

        return DB::transaction(function () use ($accountId, $data, $creator, $values) {
            // the number first: its lock keeps two saves of the same reference apart
            $number = $this->nextNumber($accountId);
            $this->assertReference($accountId, $values['payment_method_id'], $values['reference']);
            $expense = new Expense($values + ['account_id' => $accountId, 'expense_no' => $number]);
            $expense->creatable()->associate($creator);
            $expense->save();
            $this->storeAttachment($expense, $data['attachment'] ?? null);

            return $expense;
        });
    }

    /**
     * The account's edit (category, item, amount, method, date, notes, receipt). In a CLOSED shift: $data['change_reason']
     * is required; the shift's totals are recalculated and the change logged.
     */
    public function update($accountId, $id, array $data, Model $user): Expense
    {
        $this->assertOwner($user);
        $expense = $this->findOne($accountId, $id);
        $values = $this->values($accountId, $data, $expense);
        // Reference no.: checked when the method or the reference changes (old rows without one stay as they are)
        if ($values['payment_method_id'] !== (int) $expense->payment_method_id
            || ($values['reference'] !== null && $values['reference'] !== $expense->reference)) {
            $this->assertReference($accountId, $values['payment_method_id'], $values['reference'], $expense->getKey());
        }
        $closing = $this->closedShiftOf($expense);
        $reason = $closing ? $this->assertReason($data['change_reason'] ?? '', 'change_reason', 'Write why this expense of a closed shift is changed.') : null;

        return DB::transaction(function () use ($expense, $data, $values, $closing, $reason, $user) {
            $before = $this->snapshot($expense);
            $expense->update($values);
            if (!empty($data['delete_attachment']) || !empty($data['attachment'])) {
                $this->deleteAttachment($expense);
            }
            $this->storeAttachment($expense, $data['attachment'] ?? null);
            // the audit log: every edit (2026-10-27), a closed shift also gets its totals recalculated
            $changes = ['before' => $before, 'after' => $this->snapshot($expense->fresh())];
            $closing ? $this->shiftService->paymentChanged($closing, 'expense_edit', $expense, $user, $reason, $changes)
                : $this->shiftService->logOpenChange('expense_edit', $expense, $user, trim((string) ($data['change_reason'] ?? '')) ?: null, $changes);

            return $expense;
        });
    }

    // the account's delete: soft delete with the reason and who deleted it; in a closed shift: its totals recalculated + logged
    public function delete($accountId, $id, string $reason, Model $user): Expense
    {
        $this->assertOwner($user);
        $expense = $this->findOne($accountId, $id);
        $reason = $this->assertReason($reason, 'delete_reason', 'Write why this expense is deleted.');
        $closing = $this->closedShiftOf($expense);

        return DB::transaction(function () use ($expense, $reason, $user, $closing) {
            $expense->update(['delete_reason' => $reason, 'deleted_by' => $user->getKey()]);
            $expense->delete();
            $changes = ['before' => $this->snapshot($expense), 'after' => null];
            $closing ? $this->shiftService->paymentChanged($closing, 'expense_delete', $expense, $user, $reason, $changes)
                : $this->shiftService->logOpenChange('expense_delete', $expense, $user, $reason, $changes);

            return $expense;
        });
    }

    // the CLOSED shift an expense is in (null = an open shift)
    public function closedShiftOf(Expense $expense): ?ShiftClosing
    {
        return $expense->shift_closing_id ? ShiftClosing::find($expense->shift_closing_id) : null;
    }

    // the saved receipt (storage path), null when none / missing
    public function attachmentPath(Expense $expense): ?string
    {
        $path = $expense->attachment ? 'expenses/' . $expense->account_id . '/' . $expense->attachment : null;
        return $path && Storage::disk('local')->exists($path) ? $path : null;
    }

    // the next expense number of the account (inside a transaction: locked); never below config paymentmodule.number_start
    public function nextNumber($accountId): int
    {
        $last = (int) Expense::withTrashed()->where('account_id', $accountId)->lockForUpdate()->max('expense_no');
        return max($last + 1, (int) config('paymentmodule.number_start', 1));
    }

    // the checked values of the form (category / method of the account, amount > 0); the current ones stay valid when inactive
    private function values($accountId, array $data, ?Expense $expense = null): array
    {
        $categoryId = (int) ($data['expense_category_id'] ?? 0);
        $category = ExpenseCategory::where('account_id', $accountId)->find($categoryId);
        if (!$category || (!$category->is_active && $categoryId !== (int) $expense?->expense_category_id)) {
            throw ValidationException::withMessages(['expense_category_id' => 'Choose one of your expense categories.']);
        }
        $methodId = (int) ($data['payment_method_id'] ?? 0);
        $method = PaymentMethod::where('account_id', $accountId)->find($methodId);
        if (!$method || (!$method->is_active && $methodId !== (int) $expense?->payment_method_id)) {
            throw ValidationException::withMessages(['payment_method_id' => 'Choose how the money was paid.']);
        }
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'The amount must be more than 0.']);
        }
        if (trim((string) ($data['item'] ?? '')) === '') {
            throw ValidationException::withMessages(['item' => 'Write what was paid for.']);
        }

        return [
            'expense_category_id' => $categoryId,
            'item' => trim($data['item']),
            'amount' => $amount,
            'payment_method_id' => $methodId,
            'reference' => trim((string) ($data['reference'] ?? '')) ?: null,
            'spent_at' => Carbon::parse($data['spent_at'] ?? today())->toDateString(),
            'notes' => $data['notes'] ?? null,
        ];
    }

    // Reference no. (2026-10-27): a method with "needs reference" → required, unique per account + method among the expenses
    // not deleted ($ignoreId = the one being edited); throws under "reference"
    private function assertReference($accountId, int $methodId, ?string $reference, $ignoreId = null): void
    {
        $method = PaymentMethod::where('account_id', $accountId)->find($methodId);
        if (!$method?->requires_reference) {
            return;
        }
        if ($reference === null || $reference === '') {
            throw ValidationException::withMessages(['reference' => 'Write the reference no. of this ' . $method->name . ' payment.']);
        }
        $taken = Expense::where('account_id', $accountId)->where('payment_method_id', $methodId)->where('reference', $reference)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->value('expense_no');
        if ($taken) {
            throw ValidationException::withMessages(['reference' => 'This ' . $method->name . ' reference is already used by cash out #' . $taken . '.']);
        }
    }

    private function assertOwner(Model $user): void
    {
        if (!$this->isOwner($user)) {
            abort(403, 'Only the account owner can change or delete expenses.');
        }
    }

    private function assertReason($reason, string $field, string $message): string
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw ValidationException::withMessages([$field => $message]);
        }
        return $reason;
    }

    // what the shift log keeps of an expense (before / after a change)
    private function snapshot(Expense $expense): array
    {
        return [
            'id' => $expense->getKey(),
            'expense_no' => $expense->expense_no,
            'item' => $expense->item,
            'category' => $expense->expenseCategory?->name,
            'amount' => (float) $expense->amount,
            'payment_method_id' => (int) $expense->payment_method_id,
            'method' => $expense->method_name,
            'reference' => $expense->reference,
            'spent_at' => $expense->spent_at?->format('Y-m-d'),
            'notes' => $expense->notes,
        ];
    }

    private function storeAttachment(Expense $expense, ?UploadedFile $file): void
    {
        if (!$file) {
            return;
        }
        $name = Str::random(32) . '.' . strtolower($file->getClientOriginalExtension());
        $file->storeAs('expenses/' . $expense->account_id, $name, 'local');
        $expense->update(['attachment' => $name]);
    }

    private function deleteAttachment(Expense $expense): void
    {
        if ($path = $this->attachmentPath($expense)) {
            Storage::disk('local')->delete($path);
        }
        $expense->update(['attachment' => null]);
    }
}
