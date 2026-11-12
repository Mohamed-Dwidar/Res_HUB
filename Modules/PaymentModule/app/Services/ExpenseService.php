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

    // why a company Cash Out can't be changed here (§6b)
    const COMPANY_LOCKED = 'This is a company Cash Out made by HR (advance / salary): it can\'t be changed here — undo it from where it was made.';

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

    // delete: the account login only; edit: also the recorder while it is in their open shift (canEdit())
    public function isOwner(Model $user): bool
    {
        return $user->userable instanceof Account;
    }

    /**
     * Who may edit an expense (2026-11-01, the payments' rule): the owner any (a closed shift: with a reason); an employee only
     * one they RECORDED while it is still in their OPEN shift (not the date); never a colleague's. editError() = why not.
     */
    public function canEdit(Expense $expense, Model $user): bool
    {
        return $this->editError($expense, $user) === null;
    }

    public function editError(Expense $expense, Model $user): ?string
    {
        // a company Cash Out (§6b) is made by the system: undone from where it was made (the advance / the payroll), never here
        if ($expense->is_company) {
            return self::COMPANY_LOCKED;
        }
        if ($this->isOwner($user)) {
            return null;
        }
        if ($expense->trashed()) {
            return 'This cash out was deleted.';
        }
        if ($expense->creatable_type !== $user->getMorphClass() || (int) $expense->creatable_id !== (int) $user->getKey()) {
            return 'You can change only the cash out you recorded.';
        }
        if ($expense->shift_closing_id) {
            return 'This cash out is in a closed shift: only the account owner can change it.';
        }
        return null;
    }

    /**
     * Record an expense (any login): $data = expense_category_id, item, amount, payment_method_id, spent_at, notes, attachment.
     * It joins $creator's open shift.
     */
    public function create($accountId, array $data, Model $creator): Expense
    {
        // settled against a reservation / package (2026-11-12, OffsetService): method = the system "Offset" + a matching Cash In
        $settle = null;
        if (!empty($data['settle_for'])) {
            if (!$this->offsets()->enabled($accountId)) {
                throw ValidationException::withMessages(['settle_for' => 'Settling a Cash Out against a reservation is turned off (Settings › General).']);
            }
            $settle = $this->offsets()->payable($accountId, (string) $data['settle_for']);
            $data['payment_method_id'] = $this->offsets()->method($accountId)->id;
        }
        $values = $this->values($accountId, $data, null, false, (bool) $settle);
        // the date (2026-10-27): the business date of the recording time; only the account owner gives another one (reports only)
        $values['spent_at'] = app(PaymentService::class)->recordDate($accountId, $data['spent_at'] ?? null, $creator)->toDateString();

        return DB::transaction(function () use ($accountId, $data, $creator, $values, $settle) {
            // Reference no.: optional for every method, may repeat (2026-11-03, user; was required + unique for "Needs Reference No.")
            $number = $this->nextNumber($accountId);
            $expense = new Expense($values + ['account_id' => $accountId, 'expense_no' => $number]);
            $expense->creatable()->associate($creator);
            $expense->save();
            $this->storeAttachment($expense, $data['attachment'] ?? null);
            if ($settle) {
                $this->offsets()->attach($expense, $settle, $creator);
            }

            return $expense;
        });
    }

    // the Cash Out ↔ reservation settling (resolved when used: it needs PaymentService / ShiftClosingService)
    private function offsets(): OffsetService
    {
        return app(OffsetService::class);
    }

    /**
     * The edit (category, item, amount, method, reference, notes, receipt; the date: the owner only, an employee's stays as
     * recorded): the owner, or the recorder while it is in their open shift (canEdit()). In a CLOSED shift (the owner):
     * $data['change_reason'] is required; the shift's totals are recalculated. Every edit is logged (before / after).
     */
    // the last update()'s note for the answer (an owner date change no shift covers), null = none
    private ?string $lastNote = null;

    public function lastNote(): ?string
    {
        return $this->lastNote;
    }

    public function update($accountId, $id, array $data, Model $user): Expense
    {
        $expense = $this->findOne($accountId, $id);
        if ($error = $this->editError($expense, $user)) {
            abort(403, $error);
        }
        if (!$this->isOwner($user)) {
            $data['spent_at'] = $expense->spent_at;
        }
        // a settled Cash Out keeps its "Offset" method (its Cash In follows below)
        if ($expense->isOffset()) {
            $data['payment_method_id'] = $expense->payment_method_id;
        }
        $values = $this->values($accountId, $data, $expense);
        // the owner changes the date (2026-11-08): to the recorder's shift covering it (ShiftClosingService::planDateMove())
        $newDate = \Illuminate\Support\Carbon::parse($values['spent_at'] ?? $expense->spent_at)->startOfDay();
        $dateChanged = $this->isOwner($user) && !$newDate->isSameDay($expense->spent_at);
        $move = $dateChanged ? $this->shiftService->planDateMove($expense, $newDate, (float) $expense->amount, (float) ($values['amount'] ?? $expense->amount), $data) : null;
        $this->lastNote = $move['note'] ?? null;
        $closing = $this->closedShiftOf($expense);
        $reason = $closing || ($move['closed'] ?? false)
            ? $this->assertReason($data['change_reason'] ?? '', 'change_reason', 'Write why this expense of a closed shift is changed.') : null;

        return DB::transaction(function () use ($expense, $data, $values, $closing, $reason, $user, $dateChanged, $move) {
            $before = $this->snapshot($expense);
            $expense->update($values);
            if ($dateChanged) {
                $expense->forceFill(['date_changed_at' => now(), 'date_changed_by' => $user->getKey()]
                    + (!empty($move['move']) ? ['shift_closing_id' => $move['to']] : []))->save();
            }
            if (!empty($data['delete_attachment']) || !empty($data['attachment'])) {
                $this->deleteAttachment($expense);
            }
            $this->storeAttachment($expense, $data['attachment'] ?? null);
            // the audit log: every edit (2026-10-27), a closed shift also gets its totals recalculated
            $changes = ['before' => $before, 'after' => $this->snapshot($expense->fresh())];
            if (!empty($move['move'])) {
                $this->shiftService->applyDateMove($move, $expense, $user, $reason, $changes);
            } else {
                $closing ? $this->shiftService->paymentChanged($closing, 'expense_edit', $expense, $user, $reason, $changes)
                    : $this->shiftService->logOpenChange('expense_edit', $expense, $user, trim((string) ($data['change_reason'] ?? '')) ?: null, $changes);
            }
            // its Cash In follows (amount, date, shift)
            if ($expense->isOffset()) {
                $this->offsets()->sync($expense->fresh(), $user, $reason ?? (trim((string) ($data['change_reason'] ?? '')) ?: null));
            }

            return $expense;
        });
    }

    // the account's delete: soft delete with the reason and who deleted it; in a closed shift: its totals recalculated + logged
    public function delete($accountId, $id, string $reason, Model $user): Expense
    {
        $this->assertOwner($user);
        $expense = $this->findOne($accountId, $id);
        if ($expense->is_company) {
            throw ValidationException::withMessages(['delete_reason' => self::COMPANY_LOCKED]);
        }
        $reason = $this->assertReason($reason, 'delete_reason', 'Write why this expense is deleted.');
        $closing = $this->closedShiftOf($expense);

        return DB::transaction(function () use ($expense, $reason, $user, $closing) {
            $expense->update(['delete_reason' => $reason, 'deleted_by' => $user->getKey()]);
            $expense->delete();
            $changes = ['before' => $this->snapshot($expense), 'after' => null];
            $closing ? $this->shiftService->paymentChanged($closing, 'expense_delete', $expense, $user, $reason, $changes)
                : $this->shiftService->logOpenChange('expense_delete', $expense, $user, $reason, $changes);
            // a settled Cash Out: its Cash In goes too
            if ($expense->isOffset()) {
                $this->offsets()->remove($expense, $reason, $user);
            }

            return $expense;
        });
    }

    /**
     * A company Cash Out (docs/hr-plan.md §6b): paid by the company (owner / company cash, bank, wallet), in NO shift — never
     * in a hand-over, My Shift, a closing or Daily Summary's collectors (Expense::scopeInShifts()). $key = the system category
     * ('advances' | 'salaries', made on first use). $data = item, amount, payment_method_id, spent_at (any date, default today),
     * reference, notes. Only the system calls it (H4 advances, H5 salaries); the forms can't edit / delete the row.
     */
    public function createCompany($accountId, string $key, array $data, Model $by): Expense
    {
        $category = app(ExpenseCategoryService::class)->generated($accountId, $key);
        $values = $this->values($accountId, ['expense_category_id' => $category->id] + $data, null, true);

        return DB::transaction(function () use ($accountId, $values, $by) {
            $expense = new Expense($values + ['account_id' => $accountId, 'expense_no' => $this->nextNumber($accountId)]);
            $expense->is_company = true;
            $expense->creatable()->associate($by);
            $expense->save();
            return $expense;
        });
    }

    /**
     * Void a company Cash Out (§6b) when its source is cancelled (an advance; H5 a salary payment): soft delete with the reason
     * and who (deleted_at = when). Only the system calls it, in the source's own transaction; the Cash Out screens never can.
     */
    public function voidCompany(Expense $expense, string $reason, Model $by): void
    {
        abort_unless($expense->is_company, 500, 'Only a company Cash Out can be voided.');
        $expense->update(['delete_reason' => $reason, 'deleted_by' => $by->getKey()]);
        $expense->delete();
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

    // the checked values of the form (category / method of the account, amount > 0); the current ones stay valid when inactive;
    // a system category (generated_key: Advances, Salaries) only for createCompany() ($company)
    // $offset: the system method "Offset" allowed (a new settled Cash Out); an edit keeps its own
    private function values($accountId, array $data, ?Expense $expense = null, bool $company = false, bool $offset = false): array
    {
        $categoryId = (int) ($data['expense_category_id'] ?? 0);
        $category = ExpenseCategory::where('account_id', $accountId)->find($categoryId);
        if (!$category || (!$company && !$category->is_active && $categoryId !== (int) $expense?->expense_category_id)) {
            throw ValidationException::withMessages(['expense_category_id' => 'Choose one of your expense categories.']);
        }
        if ($category->isGenerated() && !$company) {
            throw ValidationException::withMessages(['expense_category_id' => '"' . $category->name . '" is recorded by the system only (HR), choose another category.']);
        }
        $methodId = (int) ($data['payment_method_id'] ?? 0);
        $method = PaymentMethod::where('account_id', $accountId)->find($methodId);
        if (!$method || (!$method->is_active && $methodId !== (int) $expense?->payment_method_id)) {
            throw ValidationException::withMessages(['payment_method_id' => 'Choose how the money was paid.']);
        }
        if ($method->isGenerated() && !$offset && $methodId !== (int) $expense?->payment_method_id) {
            throw ValidationException::withMessages(['payment_method_id' => '"' . $method->name . '" is used by the system only (Settle against reservation), choose another method.']);
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
