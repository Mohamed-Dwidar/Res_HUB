# Payments, Invoices & ETA — Module Plan

> Status: Phase 1 done (old data transferred 2026-10-11); §2b shift closing (two levels) built 2026-10-11; Phase 2 not started.
> Phases: (1) payments + withholding certificates + day closing + old data import · (2) taxes on reservations / packages / extras + ETA to-do (redesigned 2026-10-28; Phase A built) · (3) reports.

---

## 0. Decisions (agreed)

| Topic | Decision |
|---|---|
| Payments | One `payments` table, morph `payable` → Reservation \| Package \| null (other income). `type` = payment \| refund \| withholding. |
| Reservation inside a package | Not payable on its own; it is paid through the package. |
| Member credit | Negative `remaining` = member credit, shown clearly (badge + member statement). Wallet comes later. |
| Refunds | Refund records only (a `type = refund` row, positive amount). |
| Rights | Employees **add** payments only. Edit / delete / refund = account owner only. Delete = soft delete + required reason. (2026-10-28: an employee may edit their OWN cash in / withholding while it is in their OPEN shift — not the date, not a refund, not after closing; logged.) |
| Closing | ONE level since 2026-10-13 (§2b): the **shift** (every login, the account included); after closing the account edits / deletes with a reason (logged, totals recalculated); refunds by anyone into their own shift. No day / month lock; the account's **Daily Summary** is read only. (2026-10-11 → 10-13: two levels with an account day closing — removed.) |
| Payment methods | Managed per account. |
| Expenses | Separate table later, no `direction` column. |
| Invoices | Simplified, ETA-ready (no API now). Created on demand; one builder, so auto-creation can be added later. Issued = read-only; corrections via credit / debit note. |
| E-receipts (B2C) | Same tables and flow as invoices (`document_type = receipt`), entered manually on the ETA portal, UUID pasted back. |
| Taxes | (2026-10-28) VAT 14% or exempt + table tax 1%, rates in Settings › General › Taxes; per plan / income category flags = the tax TYPE (VAT / Exempt, table tax, price includes tax). Taxed ONLY when the customer requests an invoice: "Invoice requested" per reservation / package / other-income cash in (default no; pre-ticked by the plan's / category's "Invoice by default"), from the tax start date; a snapshot is stored on save. One `TaxCalculator` (§1, §3). |
| Prices | Inclusive or exclusive PER plan / income category (`price_includes_tax`, default inclusive). Invoice requested + exclusive = the member owes net + taxes; no invoice = the discounted price as before. |

### Further decisions (approved 2026-10-10)
1. **Day closing (REPLACED 2026-10-11 by two levels, then 2026-10-13 by the ONE shift level in §2b; original text kept):** one closing per account per day (not per shift). Account and employees can close; only the owner can reopen, with a reason (logged). The totals are broken down **by employee AND by payment method** (cash handover per employee). Withholding payments are **excluded from the cash totals** and shown on a separate line.
2. **Reservation moved into a package after being paid:** its payments move to the package (the payable changes; the amount and date don't).
3. **Old payment method 5 "إشعار خصم"** (8 payments, 20–270 EGP): **confirmed as withholding**. Imported as `type = withholding`, no certificate attached yet.
4. **Withholding tax (T4):** on the net before tax (2026-10-28); rate = Settings `withholding_rate` (accountant). On ETA documents: not shown by default; the account setting `withholding_on_invoices` turns it on (still being confirmed with the accountant). The customer's withholding is recorded through payments of type withholding + certificates.
5. **`creatable` of old rows:** set to the account's user (`User` id 1), the same as the reservations SQL in `to_run`.
6. **Reservation view 500 (old `creatable_type` classes):** fix **both** ways — SQL to fix the existing data + a code guard for unknown or missing creators. Done before Phase 1.
7. **Receiver id:** one `receiver_id_no` column whose meaning follows `receiver_type` (tax id / national id / passport), not separate columns.
8. **Rounding to the exact price:** decided 2026-10-28 — VAT absorbs the 0.01 (`tax_rounding` = `vat`, a setting: net / extra discount are the other options, §1).

---

## 1. Tax calculation (decided with the accountant 2026-10-28; built: Phase A)

### Rules
- **Rates** in Settings › General › Taxes, never hard-coded: `accounts.vat_rate` (14), `accounts.table_tax_rate` (1). ETA codes in
  `config('paymentmodule.tax')`: VAT T1 / V009, table tax T2 / Tbl01, VAT exempt subtype = **null until the accountant gives it**.
- **When:** only on rows with "Invoice requested" (§3), created on / after the tax start date; otherwise no tax lines at all.
- **Per plan and per income category** (the extras' item type) = the tax TYPE, set by the owner (`HasTaxFlags`): VAT 14% / Exempt
  (`vat_exempt`), Table tax yes / no (`table_tax`), Price includes tax yes / no (`price_includes_tax`, default yes). A category not
  counted as income (`is_revenue` = 0, e.g. "VAT (manual)") is never taxed. Which services are VAT exempt / have table tax changes
  over time → the owner edits the flags; saved rows keep their snapshot (§3).
- **Formula** (ETA order, 2 decimals, half up, whole cents — no float rounding):
  `table tax = net × table rate` · `VAT = (net + table tax) × VAT rate` · `total = net + table tax + VAT`.
- **Discount before tax.** Exclusive price: `net = price − discount`. Inclusive price: the member pays `price − discount`, the net is
  worked back from it; `sales` / `discount` are reported before tax (`sales` = the net of the full price, `discount` = the gap).
- **Inclusive back-calculation:** `net ≈ total ÷ ((1 + table) × (1 + VAT))`, then the nearest net (± 2 cents) whose taxes add up to
  the total exactly. Some totals can't be reached by any net (each cent of net moves the total ~1.15 cents). Every price 0.01 …
  2,000.00: **26,298 (13%)** with table tax + VAT, **24,561** VAT only, **1,980** table tax only — real ones too (plans 5,000 and
  15,000). Then the rounding option (`accounts.tax_rounding`, Settings) decides who absorbs the 0.01:
  - `vat` — **chosen (2026-10-28, default)**: net and table tax exact, VAT = total − net − table tax (0.01 off its formula); on a
    VAT exempt line the table tax takes it (never a VAT on an exempt line).
  - `net`: the taxes from the nearest net, net = total − taxes.
  - `extra_discount`: the smallest net above, every tax exact, the excess (≤ 0.02) = an after-tax extra discount (ETA field).
- **Negative amounts** (returns) = the same split, negated.
- **Withholding (T4)** is on the **net before tax** (the stored snapshot net), never on the total; rate = `accounts.withholding_rate`
  (null until the accountant gives it). The withholding payment still lowers `remaining` like cash; T4 lines on ETA documents stay
  off by default.

### Worked example — 1,000.00, table tax 1% + VAT 14%

| | Exclusive (1,000 = net) | Inclusive (1,000 = what the member pays) |
|---|---|---|
| Net | 1,000.00 | 868.50 (1000 ÷ 1.1514 = 868.5079; 868.50 is the net that adds up exactly) |
| Table tax 1% | 10.00 | 8.69 (8.685 half up) |
| VAT 14% on net + table tax | 141.40 (on 1,010.00) | 122.81 (877.19 × 14% = 122.8066) |
| **Total** | **1,151.40** | **1,000.00** |

Discount 10% before tax: exclusive 900.00 + 9.00 + 127.26 = 1,036.26; inclusive the member pays 900.00 (sales 868.50 before tax).

### Code (PaymentModule, Phase A)
- `app/Services/Tax/TaxCalculator` (`calculate($price, $discount, TaxRule)` → `TaxResult`), `TaxRule` (rates + flags + rounding;
  `TaxRule::for($account, $planOrCategory)`), `TaxResult` (sales, discount, net, table tax, VAT, extra discount, total, adjusted;
  `toArray()` = the snapshot incl. the rates / flags used).
- `tests/Unit/TaxCalculatorTest.php`: the worked example, exempt / untaxed, discount before tax, negatives, every price 0.01 …
  2,000.00 for the 3 combinations exclusive and inclusive × the 3 rounding options (the unreachable counts above are asserted).

---

## 2. Phase 1 — Payments, withholding certificates, day closing, old data import

### New module `PaymentModule`
```
Modules/PaymentModule/
  app/Contracts/Payable.php                  payableAmount(), payableMemberId(), canTakePayments()
  app/Models/Concerns/HasPayments.php         morphMany payments, paid / remaining helpers, payment status
  app/Models/{Payment, PaymentMethod, IncomeCategory, WithholdingCertificate, DayClosing}.php
  app/Repositories/{Payment, PaymentMethod, IncomeCategory, WithholdingCertificate, DayClosing}Repository.php   (forAccount())
  app/Services/PaymentService.php             create / update / delete(reason) / refund, refresh(payable),
                                              assertDayOpen(date), nextNumber(), movePayments(from, to)
  app/Services/DayClosingService.php          summary(date) (per employee × method, withholding apart), close, reopen(reason)
  app/Services/WithholdingCertificateService.php  attach / detach withholding payments, difference check
  app/Http/Requests/{Payment, PaymentDelete, PaymentMethod, IncomeCategory, WithholdingCertificate, DayClosing, DayReopen}Request.php
  app/Http/Controllers/Concerns/PaymentActions.php            index (table), create, store, show, receipt (PDF)
  app/Http/Controllers/Account/PaymentAccountModuleController.php     + edit, update, destroy (reason), refund
  app/Http/Controllers/Employee/PaymentEmployeeModuleController.php   actions trait only (add / view)
  app/Http/Controllers/Account/{PaymentMethod, IncomeCategory, WithholdingCertificate}AccountModuleController.php
  app/Http/Controllers/Concerns/DayClosingActions.php + Account/Employee DayClosing controllers (reopen = account only)
  app/Console/RefreshPaymentsCommand.php      `php artisan payments:refresh` (all reservations / packages)
  resources/views/Payment/{index, form, show, delete-form, receipt-pdf, _list}.blade.php
  resources/views/{Method, Category}/{index, form}.blade.php
  resources/views/Withholding/{index, form, show}.blade.php
  resources/views/DayClosing/{index, show, reopen-form}.blade.php
  database/Migrations/*_create_{payment_methods, income_categories, payments, withholding_certificates, day_closings}_table.php
  routes/web.php                              account.payments.*, employee.payments.*, account.payment-methods.*, …
```

### Changes in other modules
- **ReservationModule:** the `Reservation` model implements `Payable` (payable only when `package_id = 0`). The view shows the payments block (`_list`) + "Add payment". Row menu: "Add payment". List: paid / remaining columns + a payment status filter. `ReservationService`: when `package_id` changes → `PaymentService::movePayments()`.
- **PackageModule:** the `Package` model implements `Payable`. `PackageService::recalculate()` ends with `PaymentService::refresh($package)`, because the price changes the remaining. The view shows the payments block.
- **Extras (added 2026-10-11, user decision):** table `extras` (morph `extrable`: Package now, Reservation later) — `type` extra | adjustment, `income_category_id` (extra only), `item`, `quantity`, `unit_price`, `amount` (= quantity × unit_price, signed), `date`, `notes`, creatable, soft deletes. **Package due = net (after discount) + Σ extras.**
  - extra = a real sale (copies, buffet, extra hours …): revenue, has an income category, invoiceable in phase 2 (its ETA item / taxes come from the income category).
  - adjustment = a balance move (carry-overs between packages, close-outs, discounts, reimbursements): not revenue, never invoiced, can be negative.
  - Files: `PaymentModule` `Models/Extra`, `Models/Concerns/HasExtras` (on Package), `Services/ExtraService`, `Requests/ExtraRequest`, `Account/ExtraAccountModuleController`, routes `account.extras.*`, views `Extra/*` (block `Extra.partials.package-extras` in the package view). The account adds / edits / deletes; employees see them.
  - Old data: the 180 old package extras (`dwidar_pivot_api.extras`) are imported by item: 61 adjustments, 119 extras (categories 1 Prints, 2, 3, 5 Other, 7 Buffet, 8 Break Hours, 9 Extra Hours); the rule is in `to_run`.
- **MemberModule:** the member view shows a balance (owed / paid / credit). Full statement in phase 3.
- **LayoutModule** (shared — confirm before editing): account sidebar: Payments, Withholding certificates, Day closing, Settings → Payment methods, Income categories. Employee sidebar: Payments, Day closing.

### Rules in `PaymentService`
- Amount always positive; `type` gives the sign (refund / withholding count as "not cash in").
- `paid_amount = Σ payment + Σ withholding − Σ refund`; `remaining = due − paid_amount` (negative = credit).
- Due: reservation `net_amount` (only when `package_id = 0`; reservations inside a package show "in package"); package `after_discount`.
- ~~Every add / edit / delete / refund checks `assertDayOpen(paid_at)`~~ — removed 2026-10-13 (no day lock; a closed SHIFT needs a reason instead, §2b).
- Employees: create only, dated today or an open day. Owner: everything, with a delete reason.
- `payment_no`: per account, `MAX + 1` inside a transaction (lock).

### DB — new tables (normal create migrations, `php artisan migrate`)

**payment_methods**: `id`, `account_id` (index), `name_ar`, `name_en`, `is_active` (bool, 1), `sort_order` (int, 0), timestamps, softDeletes

**income_categories**: `id`, `account_id` (index), `name_ar`, `name_en`, `is_active` (bool, 1), `sort_order` (int, 0), timestamps, softDeletes
*(phase 2 adds `eta_item_id`)*

**payments**

| column | type |
|---|---|
| `id` | bigint pk |
| `account_id` | bigint, index |
| `payment_no` | unsigned int — unique (`account_id`, `payment_no`) |
| `payable_type` | varchar null |
| `payable_id` | bigint default 0 — index (`payable_type`, `payable_id`) |
| `income_category_id` | bigint default 0 |
| `member_id` | bigint default 0, index |
| `type` | enum('payment','refund','withholding') default 'payment' |
| `amount` | decimal(12,2) |
| `payment_method_id` | bigint default 0 (0 for withholding) |
| `withholding_certificate_id` | bigint default 0 |
| `paid_at` | date, index |
| `reference` | varchar null (transfer / card ref) |
| `notes` | text null |
| `delete_reason` | varchar null |
| `deleted_by` | bigint default 0 (user id) |
| `creatable_type`, `creatable_id` | morphs |
| timestamps, softDeletes | |

**withholding_certificates**: `id`, `account_id` (index), `certificate_no`, `issued_at` (date), `company_id` (default 0), `member_id` (default 0), `payer_name`, `payer_tax_id` (null), `period_from` (date null), `period_to` (date null), `amount` (decimal 12,2), `attachment` (varchar null, private disk), `notes` (text null), creatable morphs, timestamps, softDeletes

**day_closings**: `id`, `account_id`, `date` — unique (`account_id`, `date`); `status` enum('closed','reopened'); `cash_total` decimal(12,2) (payments − refunds, withholding excluded); `withholding_total` decimal(12,2); `totals` (json: `{employees: {user_id: {name, methods: {method_id: {payments, refunds, net}}, net}}, methods: {method_id: net}}`, withholding listed separately); `closed_by` (bigint user id), `closed_at` (datetime), `reopened_by` (bigint default 0), `reopened_at` (datetime null), `reopen_reason` (varchar null), timestamps

### DB — columns on existing tables (add by hand + edit the `create_*` migration)
```sql
ALTER TABLE reservations
  ADD paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER net_amount,
  ADD remaining   DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER paid_amount;

ALTER TABLE packages
  ADD paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER after_discount,
  MODIFY remaining DECIMAL(12,2) NOT NULL DEFAULT 0;

-- recommended: money columns double → decimal (avoids 99.99999)
ALTER TABLE reservations
  MODIFY amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  MODIFY discount_value DECIMAL(12,2) NOT NULL DEFAULT 0,
  MODIFY net_amount DECIMAL(12,2) NOT NULL DEFAULT 0;
ALTER TABLE packages
  MODIFY total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  MODIFY amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  MODIFY after_discount DECIMAL(12,2) NOT NULL DEFAULT 0;
```

### Old data import (from `dwidar_pivot_res`, account 1)
The old data:
- 5,042 payments: 4,864 on reservations + 178 on packages, all linked to existing rows; 32 negative; 8 with method 5
- 227 incomes in 5 types
- 6 payment methods

Old ids are kept for payments, methods and categories.

```sql
-- 1. payment methods (old table has no account_id; method 5 becomes type withholding)
INSERT INTO dwidar_res_portal.payment_methods (id, account_id, name_ar, name_en, sort_order, created_at, updated_at)
SELECT id, 1, name, name, id, NOW(), NOW() FROM dwidar_pivot_res.payment_methods WHERE id <> 5;
-- then rename by hand: cash → نقدي / Cash, visa → Visa, instapay → InstaPay, vodafone_cash → Vodafone Cash

-- 2. income categories
INSERT INTO dwidar_res_portal.income_categories (id, account_id, name_ar, name_en, sort_order, created_at, updated_at, deleted_at)
SELECT id, account_id, name_ar, name_en, id, created_at, updated_at, deleted_at FROM dwidar_pivot_res.income_types;

-- 3. payments (payment_no = old id)
INSERT INTO dwidar_res_portal.payments
  (id, account_id, payment_no, payable_type, payable_id, member_id, type, amount, payment_method_id,
   paid_at, notes, creatable_type, creatable_id, created_at, updated_at)
SELECT p.id, p.account_id, p.id,
  CASE WHEN p.paymentable_type LIKE '%Package%' THEN 'Modules\\PackageModule\\app\\Models\\Package'
       ELSE 'Modules\\ReservationModule\\app\\Models\\Reservation' END,
  p.paymentable_id,
  COALESCE(r.member_id, k.member_id, 0),
  CASE WHEN p.payment_method = 5 THEN 'withholding' WHEN p.amount < 0 THEN 'refund' ELSE 'payment' END,
  ABS(p.amount),
  CASE WHEN p.payment_method = 5 THEN 0 ELSE p.payment_method END,
  p.date, p.notes,
  'Modules\\UserModule\\app\\Models\\User', 1,
  p.created_at, p.updated_at
FROM dwidar_pivot_res.payments p
LEFT JOIN dwidar_res_portal.reservations r ON p.paymentable_type LIKE '%Reservation%' AND r.id = p.paymentable_id
LEFT JOIN dwidar_res_portal.packages     k ON p.paymentable_type LIKE '%Package%'     AND k.id = p.paymentable_id;

-- 4. the one payment on a reservation that is inside a package → move to the package
UPDATE dwidar_res_portal.payments pay
JOIN dwidar_res_portal.reservations r ON pay.payable_type LIKE '%Reservation' AND r.id = pay.payable_id
SET pay.payable_type = 'Modules\\PackageModule\\app\\Models\\Package', pay.payable_id = r.package_id
WHERE r.package_id > 0;

-- 5. other income → payments without payable (payment_no continues after the old max payment id 5189)
INSERT INTO dwidar_res_portal.payments
  (account_id, payment_no, payable_type, payable_id, income_category_id, member_id, type, amount,
   payment_method_id, paid_at, notes, creatable_type, creatable_id, created_at, updated_at, deleted_at)
SELECT i.account_id, 5189 + i.id, NULL, 0, i.type_id, 0,
  'payment', i.amount, i.payment_method_id,   -- old incomes: no negatives, methods 1–4 only
  i.date, NULLIF(CONCAT_WS(' — ', NULLIF(i.item, ''), NULLIF(i.notes, '')), ''),
  'Modules\\UserModule\\app\\Models\\User', 1, i.created_at, i.updated_at, i.deleted_at
FROM dwidar_pivot_res.incomes i;

-- 6. check: totals must match the old system
SELECT (SELECT SUM(amount) FROM dwidar_pivot_res.payments) old_payments,
       (SELECT SUM(IF(type='refund', -amount, amount)) FROM dwidar_res_portal.payments WHERE payable_type IS NOT NULL) new_payments,
       (SELECT SUM(amount) FROM dwidar_pivot_res.incomes WHERE deleted_at IS NULL) old_incomes,
       (SELECT SUM(IF(type='refund', -amount, amount)) FROM dwidar_res_portal.payments WHERE payable_type IS NULL AND deleted_at IS NULL) new_incomes;
```
Then:
1. `php artisan tinker --execute '…Package::each(fn ($p) => $p->recalculate());'` (already in `to_run`)
2. `php artisan payments:refresh`
3. Optionally close every old day up to the import date: `php artisan payments:close-days --until=2025-02-14`

Steps 1–6 go into `to_run`.

### Phase 1 build order
1. Migrations + models + `Payable` / `HasPayments` + the ALTERs above
2. `PaymentMethod` + `IncomeCategory` CRUD (account settings, popup CRUD like statuses)
3. `PaymentService` (create, refresh, numbering, rights) + `payments:refresh`
4. Payments list + add form (reservation / package / other income) + receipt PDF
5. Payments block + "Add payment" in reservation / package views, paid / remaining columns + filter
6. Owner edit / delete (reason) / refund
7. Withholding payments + certificates (attach payments, difference check, attachment)
8. Day closing (summary, close, reopen) + `assertDayOpen` everywhere
9. Package / reservation hooks (`recalculate` → `refresh`, `movePayments`)
10. Sidebar items (shared layout — confirm) + import SQL + checks

### Cash In / Cash Out — the Payments page (user, 2026-10-14; built)
UI names only (labels, titles, buttons, popups, PDFs, docs); code, tables, routes and URLs keep payment / expense.
- **Names:** money in (payments, withholding) = **Cash In** / وارد; money out (expenses, refunds) = **Cash Out** / منصرف. Type labels
  (`Payment::TYPES`): Cash In | Refund | Withholding; expenses = Cash Out. Titles "Cash In #N" / "Refund #N" / "Withholding #N" /
  "Cash Out #N"; add buttons "+ Cash In" / "+ Cash Out"; receipt PDF "Cash In Receipt" / "إيصال استلام نقدية" (a refund: "Refund Receipt" /
  "إيصال رد نقدية"; withholding unchanged); reservation / package views: block "Cash In", button "+ Cash In"; row menus "Add Cash In";
  package statement PDF section "Cash In". Shift close popup / My Shift / shift popup / Daily Summary: columns Cash In | Refunds |
  Cash Out | Net, sum rows Cash In, Refunds (−), Cash Out (−), Net to hand over (the "Total collected" row is gone), withholding apart;
  My Shift / shift popup lists "Cash In and Refunds" + "Cash Out". Settings lists: Payment Methods / Income Categories count column
  "Cash In", Expense Categories "Cash Out"; the settings page names (Payment Methods, Income / Expense Categories) are unchanged.
- **One sidebar item:** Daily = Dashboard, Reservations, Packages, Payments, My Shift, Members (account + employee); no Expenses item;
  Payments is active on the expense pages too.
- **Page tabs** (links `?type=`, `CashListService::TYPES`, `.page-tabs`): **All** (default) | **Cash In** | **Cash Out**; the data stays
  in `payments` / `expenses`. Buttons "+ Cash In" / "+ Cash Out" on every tab. Each tab keeps the list rules: date tabs Today (default) |
  This Month | All + ONE date field (account); employees: their own rows, ONE day, no date tabs (server enforced).
  - **Cash In** = the payments list above WITHOUT refunds (`Payment::scopeFilter` `direction=in`, sent in the table's data URL — not a
    form field: Clear resets hidden fields and counts them); Method keeps Withholding, no Refunds option; summary "Cash In: …".
  - **Cash Out** = expenses + refunds (user: refunds here, not on Cash In); filters search | date | Category (expense categories +
    Refunds) | Method | Clear; summary "Cash Out: <out> (Refunds · Paid out) — per method".
  - **All** = both, newest first (date, then added time); in green +, out red − (refunds + expenses; an imported negative expense =
    green "Return"). Columns Date (time) | Type (Cash In / Refund / Withholding / Cash Out + no.) | Member / Category | For / Item |
    Method | Amount | By (account only) | row menu (the payment's / expense's own). Filters search | date | Method | Clear. Summary
    "In · Out · Net — per method net · Withholding" (withholding apart, not in In — user).
  - All / Cash Out: `PaymentActions::cashData()` (route `{area}.payments.cash-data?direction=all|out`) + `CashListService`: ONE UNION ALL
    of the payments and expenses queries (each with its own `filter()` / `tab()` / `collectedBy()`), count / order / page on it, then the
    page's models in two queries; rows `row-payment-{id}` / `row-expense-{id}`, so an edit / delete answers `reload` (both update
    answers now send `reload: true`; on the Cash In tab the row still updates in place). ~0.06–0.85 s on the real data (15,857 rows; All tab "All" dates the slowest).
- **Old URLs:** `/account|employee/expenses` → `payments?type=out` (`ExpenseActions::index()`); the expense routes / popups stay;
  `Expense/index` view unused (kept). `{area}.expenses.data` still feeds My Shift's Cash Out list.

### Payments list (user, 2026-10-13 — simplified; built; = the Cash In tab since 2026-10-14)
- **Tabs:** Today (default, `paid_at` = today) | This Month | All (`Payment::TABS`, `scopeTab`).
- **Filters, one row:** search (no., reference, member name or phone) | **date** | **For** | **Method** | Clear.
  - Date = ONE date field (shared, 2026-10-13): `layoutmodule::partials.date-range-filter` right after search — flatpickr 4.6.13 range mode (MIT,
  Arabic locale `l10n/ar`; local copy `public/assets/vendor/flatpickr/` + its LICENSE, no CDN), one shared init in custom.js; one day clicked = that day, two = the range; shows `13-10-2026` /
  `01-10-2026 → 13-10-2026` + × (clear = All). Synced with the tabs both ways (`partials.filter-tabs`, `Model::tabDates()`): Today → today, This Month → first → last day, All → empty;
    picking today / this month's full range / nothing highlights that tab (the tab's own rule is sent, no dates); other days = the All
    tab + `date_from` / `date_to`, no tab highlighted.
  - **Employees: NO tabs, one day only** (user, 2026-10-13): only the date field, starting on today (`default` → `data-date-default`),
    one click picks and closes (`single` → flatpickr single mode); × and Clear go back to TODAY, never empty (× hidden while on today),
    so an employee can never list all their payments at once. Server (`PaymentActions::listFilters()` / `tab()`): employees = tab `all`
    + date_from = date_to = the picked day; no date / not a date → today; a range → its first day; `tab` ignored.
    Ranges / tabs = the account only; the reservations / packages lists keep the range for everyone.
  - Clear button count (datatables.js): the date field = ONE filter (date_from only; the `tab` it set to All not counted with it);
    not counted while on its default day.
  - For (`for`) = Reservations (`reservation`) | Packages (`package`) | group "Other income": All other income (`income`) + each category (`category-{id}`)
    — replaces the Sources + Categories filters.
  - Method (`method`) = each payment method (id), then **Refunds** (`refund`) and **Withholding** (`withholding`) — the Type filter is gone.
  - `Payment::scopeFilter` reads `for` / `method` (the older keys `source`, `type`, `payment_method_id`, `income_category_id` still work).
- **Summary line** above the table, for the current tab + filters (employees: their own payments):
  `Total: <net> — <method> <amount> · … · Refunds −<amount> · Withholding <amount>`; total = payments − refunds, withholding NOT in it
  (`PaymentService::listSummary()`, partial `Payment/partials/list-summary`, sent with the rows as json `html` → `[data-html]`, datatables.js).
- **Columns (order updated 2026-10-13):** Member (name → the payment view popup, payment no. small; no phone, no No. column) |
  For (link: reservation → unit color + `Space › Unit` (`row-place`) + its start under it, package → name, other income → category) |
  Date (date, the time it was added small) | Method |
  Amount (bold; refund = minus in red; withholding + small "Withholding" badge; no Type column) | Created By (collector, account only) | row menu (as before).
  Default order: Date newest first. Partials `row-member`, `row-amount`, `row-source`.

---

## 2b. Shift closing — the ONE closing level (simplified 2026-10-13; first built 2026-10-11 as two levels)

> 2026-10-13 (user): one closing level only, the SHIFT. Removed: the account day closing (close / reopen / history / the
> "closed day" add-edit rules), shift reopen / close again, the owner's "locked by the day close" special case, the Shift
> Reminders settings page. No day lock, no month lock. Why: simpler; the place works 24 h, a shift crosses midnight.

### Model
- **Payments join a closing by link, not by time window** (`paid_at` is a DATE): a login's **open shift** = their payments
  (`creatable` = their user) with `shift_closing_id = 0`, not deleted. Closing writes the closing id on those rows; they stay
  in it for good. A missed / back-dated payment joins the collector's open shift; a shift over midnight needs nothing special.
- **Every login collects into its own shift: the employees AND the account itself** (My Shift for both). Reminders, "still
  working" and the logout popup are for employees only.
- "Open since" = `created_at` of the OLDEST unclosed payment; an open shift without payments never triggers a popup.
- `paid_at` stays the accounting date (Daily Summary, reports).

### Closing a shift (My Shift: `MyShiftActions`, employee + account)
- My Shift: ONLY their open-shift payments — totals per method (payments / refunds / net), then Total payments,
  **Refunds as their own minus line**, Total collected, withholding apart; the payments = the SAME list as the Payments page
  (`Payment/partials/list-table`, data `{area}.payments.data?shift=open`: their own payments with `shift_closing_id` 0, any date;
  same columns + row menu, no Created By, no tabs / date / summary line); "Close My Shift" (no "Add Payment" / "Daily Summary"
  buttons there, user 2026-10-13: payments are added from Payments). My Closings: Closed | Payments From → To (added) |
  Payments = their dates (paid_at first → last) + count | Collected | Withholding (hidden while withholding tax is off) | Status.
- The shift popup (`Shift/show`): totals + its payments in the Payments list cells (Member | For | Date | Method | Amount | row menu;
  newest `ShiftClosingService::POPUP_LIMIT` = 200, "The newest 200 of N"), NO history (user 2026-10-13: the changes stay in
  `shift_events`, the "Edited after closing" badge stays).
- "Close My Shift" → popup (totals) → "Confirm Closing" / "Cancel"; the form sends the highest payment id shown; new payments
  meanwhile → refused "review again". The account can close an employee's shift on their behalf (logged `close_on_behalf`).

### After closing
- **Reopen** (account only, 2026-10-26, user — replaces "no reopen"): the shift popup's "Reopen" → a reason → the closing's payments
  and expenses go back to that login's open shift (`shift_closing_id` 0, closed again from My Shift / on behalf), the closing row is
  deleted, `shift_events` keeps a `reopen` event (its id, totals, who, reason; its other events stay). Only the login's LAST closing,
  never an imported one (`ShiftClosingService::reopen()` / `reopenError()`).
- **Edit / delete** (account only, as before) of a payment in a CLOSED shift: reason required (`change_reason` / `delete_reason`);
  `ShiftClosingService::paymentChanged()` recalculates the shift's totals, logs `payment_edit` / `payment_delete` in
  `shift_events` (payment_id, by, reason, `changes` = {before, after} snapshots) and sets `edited_at` / `edit_count` → the
  badge "Edited after closing" (the History list was removed from the popup 2026-10-13; the log stays in `shift_events`). A payment of an OPEN shift: no reason needed.
- **Refund** (the account AND employees): a new `refund` row (`refund_of` = the payment, `reason` required) created by the
  refunder → it joins THEIR open shift (the cash leaves their drawer). Not more than the payment's amount less its earlier
  refunds (`refund_of`; the 52 imported refunds have no link and are left as they are). The original payment in a closed
  shift: that shift gets the `payment_refund` log entry + the badge only, its totals don't change.
- Employees can't edit / delete.

### Reminders (employees only, never a hard block)
- Logout with an open shift that has payments → popup "Close My Shift and Log Out" / "Log Out, I'm Still Working"
  (logged `logout_closed` / `logout_still_working`).
- Oldest unclosed payment older than `config('paymentmodule.shift_reminder_hours')` (12) → popup on the next page / action
  "Close My Shift Now" / "I'm Still Working (Covering a Shift)" (reason required, logged `still_working`), back after
  `shift_snooze_hours` (4). Middleware `ShiftReminder` (full pages: layout popup; ajax answers: `X-Shift-Reminder` header).

### Daily Summary (account only, read only — replaces the Day Closing page)
- Pick a date (`?date=`, default today): Long Open Shifts (employees, with their "still working" answers + Close on Behalf);
  the shifts holding payments of that date (open → Close on Behalf / the account's own: Close My Shift; closed → View, with the
  "Edited after closing" badge); the day's totals per method and per collector (refunds their own line, withholding apart);
  the day's refunds (who, amount, method, original payment, member, reason). From the payments now (`DailySummaryService`).

### Settings › General (account only, 2026-10-13) — `accounts.withholding_enabled`, `accounts.vat_enabled` (default 1)
- Page `account.settings.general.{edit, update}` (AccountModule `GeneralSettingAccountModuleController`), sidebar Settings › General.
  Read through `Account::settingsOf($accountId)` (once per request) / `PaymentService::withholdingOn()`.
- **Withholding tax OFF:** no "Withholding" payment type in the add form (and `PaymentService::create()` refuses it), no
  "Withholding" option in the payments Method filter, the Withholding Certificates page (404, middleware `WithholdingEnabled`),
  its sidebar item and the Payments header button hidden; withholding lines / columns (shift close popup, My Shift and shift
  totals, Daily Summary totals + shifts column, payments summary line) hidden ONLY when there is none. Existing withholding
  payments / certificates are never changed or deleted and stay visible (rows, badges, totals). ON again = everything back.
- **VAT:** the switch + Taxes (rates, start date, rounding, report month; 2026-10-28) — the rules are in §1 / §3.

### Expenses (2026-10-14, built; user answers 2026-10-14)
- **Rule:** an expense always joins the OPEN shift of whoever records it (cash leaves their drawer), like a refund. Fields: category
  (`expense_categories`, Settings › Expense Categories, same CRUD as income categories), item, amount (> 0 in the app), payment method,
  date (`spent_at`), notes, optional receipt (image / PDF, private disk `expenses/{account_id}/`, `{area}.expenses.attachment`).
  `expense_no` per account like `payment_no` (app from 100001, imported = old id).
- **Who:** everyone adds; employees see their own only, the payments list rule (no tabs, ONE day, today by default — forced on the
  server); the account sees all (tabs Today | This Month | All + date range) and edits / deletes. An expense of a CLOSED shift: reason
  required → `ShiftClosingService::paymentChanged()` (`expense_edit` / `expense_delete`, `shift_events.expense_id`, before / after,
  totals recalculated, "Edited after closing"). Files: `Models/{Expense, ExpenseCategory}`, `Services/{ExpenseService,
  ExpenseCategoryService}`, `Concerns/ExpenseActions` + `{Account, Employee}/Expense*ModuleController`, `Requests/ExpenseRequest`,
  `views/Expense/*`, `views/ExpenseCategory/*`; routes `{area}.expenses.*`, `account.expenses.{edit, update, delete, destroy}`,
  `account.expense-categories.*`; sidebar Expenses (Daily, both areas — since 2026-10-14 the Payments page tab Cash Out, see
  "Cash In / Cash Out"), Settings › Expense Categories.
- **Totals:** per method payments − refunds − expenses = **net to hand over** (`ShiftClosingService::summary()`: methods[…]['expenses'],
  `expenses` {count, total}, `expenses_total`, `net_total`, `no_method_expenses`); `shift_closings.cash_total` stays "collected"
  (payments − refunds), `expenses_total` new, net = `ShiftClosing::net_total`. Close popup / My Shift / shift popup / Daily Summary:
  Total payments, Refunds (−), Total collected, Expenses (−), Net to hand over, withholding apart (since 2026-10-14: Cash In,
  Refunds, Cash Out, Net to hand over); My Shift lists its expenses
  (`Expense/partials/list-table`, `?shift=open`); closing also sends the newest expense id shown (`up_to_expense_id`).
- **Imported (`dwidar_pivot_api`):** `types` → `expense_categories` (ids kept; 16 "Building Expenses", 40 "Chamber of Commerce"
  translated — the only Arabic `name_en`); `expenses` → `expenses` (ids kept, type_id → category, method 5 "إشعار خصم" → 1 Cash (user
  2026-10-14: no "No method" for expenses; this DB: `to_run` STEP 3.15d); the 2 negative rows = returns, as they are; creator =
  creatable_type / creatable_id: Admin / unknown → login 1, User X → login X; `created_by` ignored; dates as they are). Each login's
  imported expenses join its "Imported" shift, closed_at = the later of its last payment / expense; totals by
  `php artisan shifts:recalculate --imported`. `to_run`: 3.0e, 3.1 ALTERs, 3.2, 3.13b / 3.13c, 3.14, 3.15, STEP 3.15c (one time),
  STEP 5, checks 6.11 + 6.13. Old data: 2,060 expenses, 4,582,668.30, 31 types.

### Employee isolation
- Employees never see colleagues' collections AS A WHOLE: shifts, payments list (own payments only), totals, the Daily Summary
  (account only), reports (phase 3).
- NOT covered: the payments of ONE reservation / package — employees see all rows there (incl. "Collected by") and can open /
  refund one of them.

### DB (live 2026-10-13, create migrations match)
- `payments.shift_closing_id` (0 = open shift) + index `(account_id, creatable_id, shift_closing_id)`; `payments.refund_of`
  (index) + `payments.reason`.
- `shift_closings`: `account_id`, `user_id` (collector), `first_payment_at`, `last_payment_at`, `count`, `cash_total`,
  `withholding_total`, `totals` json (`ShiftClosingService::summary()`: methods, refunds, withholding, cash_total, count),
  `notes`, `closed_by`, `closed_at`, `edited_at`, `edit_count`, timestamps.
- `shift_events`: `account_id`, `user_id`, `shift_closing_id` (0 = the open shift), `payment_id`, `by_user_id`, `action`
  close | close_on_behalf | logout_closed | logout_still_working | still_working | payment_edit | payment_delete |
  payment_refund, `reason`, `totals` json, `changes` json, `created_at`.
- Dropped: `day_closings`, `day_closing_logs` (empty: `to_run` 3.16 never ran), `shift_closings.status / reopened_*`,
  `accounts.shift_reminder_hours / shift_snooze_hours` (→ config).
- `to_run`: 3.15 = one closed "Imported" shift per old login, the account included; 3.15b (one time, this DB) = the account's
  1,848 imported payments; 3.16 / check 6.12 removed; 6.11 = every imported payment in a closing.

---

## 3. Phase 2 — Taxes when an invoice is requested, ETA to-do (redesigned 2026-10-28; invoice-requested model 2026-10-28)

Replaces the 2026-10-10 design (taxes only on invoice lines, one `prices_include_tax` per account). Some prices are exclusive, so
what the member OWES can include the taxes → the taxes live on the reservation / package / other-income cash in, as a snapshot
taken when it is saved. **Taxes are calculated only when the customer requests an invoice** (accountant 2026-10-28: fine for us).

### When taxes apply
- A row is taxed when ALL hold: VAT switch ON (Settings › General), `accounts.tax_start_date` set, the row's `created_at` ≥ that
  date (`Account::taxesApplyTo()`), and **"Invoice requested" = yes** on it. Before the start date nothing is taxed whatever the
  toggle (the toggle is not shown on those rows).
- **Invoice requested = no** (the default): no tax lines, not in the ETA to-do; the member owes the discounted price as today
  (`net_amount` / `after_discount` + extras), whether the plan is inclusive or exclusive.
- **Invoice requested = yes:** the snapshot (§1) → the member owes its total (exclusive: net + taxes; inclusive: the price).
- The tax TYPE comes from the plan / income category flags (Phase A): VAT 14% / exempt, table tax, price includes tax.

### "Invoice requested" — where it is set
- **Reservation:** checkbox on the form (account AND employees, add and edit). New reservation: pre-ticked by the plan's
  "Invoice by default" (`plans.invoice_by_default`, default off; re-applied when the plan changes on a new form, never on an edit).
  No member / company flag.
- **Package:** ONE toggle for the whole package (Add / Edit; account AND employees). Its reservations follow it (their own checkbox is hidden and locked
  while in a package, like the member); moving a reservation into a package takes the package's value; out of it, keeps it.
- **Extras:** they only exist on packages (2026-10-28: all 183) → they follow their package; no toggle of their own. Their tax
  TYPE = their income category's flags; adjustments (balance moves) are never taxed.
- **Other-income cash in:** its own toggle (account AND employees), pre-ticked by the category's "Invoice by default"
  (`income_categories.invoice_by_default`). The cash amount stays the money collected: with an invoice, an inclusive category splits
  the amount; an exclusive category asks the price before tax and the amount = its total (read only).
- **Renew / repeat:** copy "Invoice requested" from the period being renewed / the original; tax TYPE and rates from the plan's
  CURRENT flags.

### Snapshot on save (Phase B / C)
- On every save of a row created on / after the start date, the tax TYPE is stored (flags + rates + rounding used) even when no
  invoice is requested — so ticking "Invoice requested" later uses the type of the day it was created, not today's plan
  ("changing a plan's tax affects only new ones"). The amounts (net, table tax, VAT, extra discount, total) are filled only while
  the invoice is requested (else NULL).
- **Reservation** (`ReservationService` price step, after `amountBetween()` + `Reservation::calculate()`): `TaxRule::for(account,
  plan)` → `TaxCalculator`. `net_amount` keeps its meaning (the price after discount, as typed); the snapshot's net is before tax.
  Edit: the stored type; the plan's current flags only when the edit changes the plan.
- **Package** (`Package::recalculate()`, invoice requested): the reservations' prices summed **per tax group** (the stored type of
  each: VAT rate / exempt × table tax × inclusive; reservations created before the start date = an untaxed group), the package
  discount % on each group, the taxes per group, + the extras per their category type; total = Σ. Stored: groups (json) + totals.
  Not requested: today's formula.
- **What is owed:** `payableAmount()` = the total when invoice requested and taxed, else today's amounts.
  `PaymentService::refresh()`, `remaining`, Schedule "Due" / Pay, `assertNotMoreThanOwed()`: no change (they read
  `payableAmount()` / `remaining`). Screens that print `net_amount` as "the amount" switch to "what is owed" (Schedule renewal row,
  hourly list, cancelled popup, reservations list Amount, `amount-lines`, package view / list / statement PDF).
- **Toggling** = a save: recalculated at once, `refresh()`; after payments the remaining may go up (owes the tax) or below 0
  (credit, e.g. no → after paying an exclusive total) — shown as today.
- **Warning (user 2026-10-28):** ticking "Invoice requested" on a row that already has payments, with an exclusive plan /
  category → the form shows "The customer will owe X more (taxes)." before saving (X = the new total − the current due).
- **Forms:** "Invoice requested" + a live breakdown Net / Table tax / VAT / Total (custom.js, plan flags from `placeTree()` + the
  account's rates; the same integer rule as the calculator); the server recalculates on save.
- **Views / PDFs:** reservation / package view and the package statement show "Invoice requested" and, when yes, the breakdown;
  the Cash In receipt unchanged.
- **Withholding:** base = the stored net (§1); hint in the Cash In form (base × `withholding_rate`) when the payable is taxed.
- **Payments / shifts / Daily Summary:** unchanged.

### Back-dated start date (user 2026-10-28)
- The owner may set the start date in the past; saving confirms with the count ("X reservations created since <date> will be
  able to have an invoice. Their amounts don't change unless 'Invoice requested' is ticked.", `account.settings.general.tax-count`).
  `accounts.tax_start_set_at` = when it was set.
- **Back-dated rows** (created from the start date, before `tax_start_set_at`; `Account::isBackdated()`): the checkbox only for
  the OWNER, never pre-ticked (the stored value, 0); employees don't see it and their edits keep the stored value (the service
  ignores theirs). Amounts change only when the owner ticks it (logged). Rows created after = the normal rule.

### Double-tax guard (user 2026-10-28)
- While "Invoice requested" is ticked, the form lists the member's VAT paid by hand — other-income cash ins in a category not
  counted as income ("VAT (manual)") dated in the period ± 7 days (`{area}.reservations.manual-vat`): "VAT was already paid
  manually — check before adding taxes: #no · date · amount".
- **Data (2026-10-28): the 16 "VAT (manual)" cash ins of October have NO member** (the customer is in the notes only) → the
  guard can't match them. Proposed: Member required on cash ins of a category not counted as income; the old ones linked by the
  owner (the move below picks the reservation directly).
- **Proposed, not built — apply a VAT (manual) cash in to a reservation** (owner only): from the Cash In view ("Apply to
  reservation…", a reservation search) or from the guard line after saving: the payment's payable = the reservation,
  `income_category_id` 0, member = the reservation's; amount / date / method / shift unchanged (shift totals per method don't
  move); a required reason; logged as `shift_events` `payment_move` (before / after); `PaymentService::refresh()` → the remaining
  drops by the VAT already paid. Allowed only when the reservation is taxed (invoice requested), paid on its own (not in a
  package), the payment has no refunds and its amount ≤ the remaining; undo = move it back (owner, logged). The tax report then
  counts the VAT once (the snapshot), and "VAT (manual)" drops by that amount.

### Controls
- **Log:** every change of "Invoice requested" → `invoice_request_logs` (row, before → after, the paid amount at that moment, who,
  when); the Payment Exceptions report gets an **"Invoice toggled"** tab = the changes made after the row's first payment (paid
  amount > 0 at that moment). Not `shift_events`: it is not a shift / payment change.
- After its ETA document is recorded (Phase D), only the owner can change "Invoice requested" or the price fields (a credit note
  is needed — warning).

### VAT recorded by hand until the start date
- The ONE category = income category #6 "VAT (manual)" (the imported "VAT", renamed + active, `is_revenue` 0 → never income, never
  taxed; fix `database/fixes/2026-10-28-vat-manual-income-category.sql`). Found 2026-10-28: Cash In #100015 980.00 "VAT 14%" in
  category "extra" (counted as income) — to move by the owner's Edit (logged).
- After the start date: "VAT (manual)" cash ins are refused for reservations / packages created on or after it (their VAT comes
  from "Invoice requested" → never counted twice).

### ETA to-do + documents (Phase D)
- `eta_documents`: account, `documentable` morph (reservation / package / other-income payment), type invoice / receipt /
  credit_note / debit_note, `eta_uuid`, `issued_at`, the amounts copied (net, table tax, VAT, total), creatable, timestamps.
- To-do = rows with invoice requested and taxed, without a document, by created date; "Mark entered" = UUID + date + type.
- Entered by hand on the ETA portal; the ETA API later — the full invoice tables (`invoices`, `invoice_items`,
  `invoice_item_taxes`, `eta_items`, issuer snapshot, numbering, void / credit / debit notes; 2026-10-10 design, in git history)
  only when the API is built, fed from the snapshots.

### Tax report (Phase E)
Per month: net, table tax, VAT of the rows with invoice requested; taxable vs exempt, with vs without table tax. Month by
`accounts.tax_report_basis`: `eta_date` (the ETA document date, default, the official report) or `created` — the accountant confirms.

### DB
- **Phase A (run locally 2026-10-28):** `accounts` + `vat_rate` 14, `table_tax_rate` 1, `tax_start_date` null, `tax_rounding`
  'vat', `tax_report_basis` 'eta_date', `withholding_rate` null; `plans` + `income_categories`: `vat_exempt` 0, `table_tax` 0,
  `price_includes_tax` 1.
- **Phase B / C (proposed 2026-10-28, waiting for review):**
  - `plans` / `income_categories` + `invoice_by_default` bool 0.
  - `reservations`, `payments` (other income only): `invoice_requested` bool 0 + the tax type (`tax_vat_rate`, `tax_table_rate`
    decimal(5,2) null, `tax_vat_exempt`, `tax_inclusive` bool null, `tax_rounding` varchar(20) null) + the amounts (`tax_net`,
    `table_tax`, `vat`, `tax_extra_discount` decimal(12,2) null; reservations + `tax_total`; payments: the amount = the total).
    NULL = no snapshot (created before the start date). Index (`account_id`, `invoice_requested`, `created_at`) for the to-do.
  - `packages`: `invoice_requested` bool 0, `tax_groups` json null (the type per group), `tax_net`, `table_tax`, `vat`,
    `tax_extra_discount`, `tax_total` decimal(12,2) null, the same index.
  - `extras`: the tax type + amounts + `tax_total` (no toggle: they follow the package).
  - `invoice_request_logs`.
- **Phase D:** `eta_documents`.

### Build order
- **A — done 2026-10-28:** settings, plan / category flags (+ `placeTree()`), calculator + tests.
- **B — reservations: built 2026-10-28 (uncommitted, tested HTTP kernel + headless Chrome, not by the user):** migrations; "Invoice by default" on plans; the reservation snapshot (create / edit / renew / repeat, the
  toggle); `payableAmount()`; form toggle + live breakdown; view; list / Schedule amounts; `invoice_request_logs` + the Payment
  Exceptions "Invoice toggled" tab.
- **C — packages, extras, other income:** package toggle (reservations follow), groups + discount, extras by category type,
  "Invoice by default" on categories, the other-income toggle in Cash In, package view / statement PDF, the withholding hint, the
  "VAT (manual)" refusal after the start date.
- **D:** ETA to-do + `eta_documents` + the owner-only lock. **E:** tax report + Excel.
- Waiting for the accountant: the exempt ETA subtype, the withholding rate, the report month basis (confirm), the start date.

---

## 4. Phase 3 — Reports

### New module `ReportModule`
```
Modules/ReportModule/
  app/Services/ReportService.php              one query method per report (forAccount, date range)
  app/Exports/*Export.php                     maatwebsite/excel
  app/Http/Controllers/Account/ReportAccountModuleController.php   (employees: daily cash only)
  resources/views/Report/{index, daily-cash, income, outstanding, member-statement, withholding, tax, eta, audit, pdf/*}.blade.php
```

| Report | Source | Contents |
|---|---|---|
| Daily cash | payments, day_closings | per day: by method, by employee, refunds, withholding; closed / open |
| Income | payments | by period × source (reservations / packages / categories) × method |
| Outstanding & credits | reservations, packages | remaining > 0 (owed), < 0 (credit), by member |
| Member statement | reservations, packages, payments | due / paid / balance timeline |
| Withholding | payments, certificates | withheld amounts, certificates received / missing, differences |
| Tax | tax snapshots (invoice requested), eta_documents | per month (ETA date or created date): net, table tax, VAT; taxable vs exempt (§3) |
| ETA status | eta_documents | the ETA to-do: taxed rows without a document (§3) |
| Audit | payments (trashed), day_closings | deleted payments with reason + who, reopened days |

### Build order
Daily cash → Outstanding & credits → Member statement → Income → Withholding → Tax → ETA status → Audit; then Excel / PDF exports. Sidebar "Reports" (confirm).

---

## 5. Questions — status (2026-10-10)
1. ~~Reservation view 500~~ → **both** (SQL data fix + code guard), before Phase 1.
2. ~~Rounding to the exact price~~ → **VAT absorbs the 0.01** (2026-10-28), setting `tax_rounding`.
3. ~~Old method 5 "إشعار خصم"~~ → **confirmed withholding**, imported as planned.
4. ~~Defaults in §0~~ → **approved**, with two changes: day closing totals by employee × payment method (withholding excluded from cash), and T4 on invoices = setting `withholding_on_invoices` (default off).
5. **T4 on invoices** → **waiting for the accountant** (only changes the setting's default).
