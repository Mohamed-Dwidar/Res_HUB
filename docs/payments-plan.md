# Payments, Invoices & ETA — Module Plan

> Status: Phase 1 done (old data transferred 2026-10-11); §2b shift closing (two levels) built 2026-10-11; Phase 2 not started.
> Phases: (1) payments + withholding certificates + day closing + old data import · (2) tax rates + invoices / receipts + ETA entry view · (3) reports.

---

## 0. Decisions (agreed)

| Topic | Decision |
|---|---|
| Payments | One `payments` table, morph `payable` → Reservation \| Package \| null (other income). `type` = payment \| refund \| withholding. |
| Reservation inside a package | Not payable on its own; it is paid through the package. |
| Member credit | Negative `remaining` = member credit, shown clearly (badge + member statement). Wallet comes later. |
| Refunds | Refund records only (a `type = refund` row, positive amount). |
| Rights | Employees **add** payments only. Edit / delete / refund = account owner only. Delete = soft delete + required reason. |
| Closing | ONE level since 2026-10-13 (§2b): the **shift** (every login, the account included); after closing the account edits / deletes with a reason (logged, totals recalculated); refunds by anyone into their own shift. No day / month lock; the account's **Daily Summary** is read only. (2026-10-11 → 10-13: two levels with an account day closing — removed.) |
| Payment methods | Managed per account. |
| Expenses | Separate table later, no `direction` column. |
| Invoices | Simplified, ETA-ready (no API now). Created on demand; one builder, so auto-creation can be added later. Issued = read-only; corrections via credit / debit note. |
| E-receipts (B2C) | Same tables and flow as invoices (`document_type = receipt`), entered manually on the ETA portal, UUID pasted back. |
| Taxes | `tax_rates` table (T1 VAT, T2 / T3 table tax, others), several per plan / income category, several per invoice line (`invoice_item_taxes`). One `TaxCalculator` used everywhere. |
| Prices | VAT-inclusive by default (`prices_include_tax = 1` per account); the calculator also supports exclusive. |

### Further decisions (approved 2026-10-10)
1. **Day closing (REPLACED 2026-10-11 by two levels, then 2026-10-13 by the ONE shift level in §2b; original text kept):** one closing per account per day (not per shift). Account and employees can close; only the owner can reopen, with a reason (logged). The totals are broken down **by employee AND by payment method** (cash handover per employee). Withholding payments are **excluded from the cash totals** and shown on a separate line.
2. **Reservation moved into a package after being paid:** its payments move to the package (the payable changes; the amount and date don't).
3. **Old payment method 5 "إشعار خصم"** (8 payments, 20–270 EGP): **confirmed as withholding**. Imported as `type = withholding`, no certificate attached yet.
4. **Withholding tax (T4) on invoices:** not shown by default; the account setting `withholding_on_invoices` turns it on (still being confirmed with the accountant). The customer's withholding is recorded through payments of type withholding + certificates.
5. **`creatable` of old rows:** set to the account's user (`User` id 1), the same as the reservations SQL in `to_run`.
6. **Reservation view 500 (old `creatable_type` classes):** fix **both** ways — SQL to fix the existing data + a code guard for unknown or missing creators. Done before Phase 1.
7. **Receiver id:** one `receiver_id_no` column whose meaning follows `receiver_type` (tax id / national id / passport), not separate columns.
8. **Rounding to the exact price (extra discount):** waiting for the accountant's approval. It doesn't block Phase 1. The strategy is isolated in one class so it's easy to switch (§1).

---

## 1. Tax calculation (ETA rule) + worked example

### The ETA formula (from the official SDK "Document validation rules")
Per invoice line:
```
SalesTotal       = Quantity × UnitValue
NetTotal         = SalesTotal − Discount.Amount                 (taxable discount, before tax)
TotalTaxableFees = Σ amounts of T5…T12                           (stamp, entertainment, service charge, …)
T3 (table, fixed)= fixed amount, rate = 0
T2 (table, %)    = (NetTotal + TotalTaxableFees + ValueDifference + T3) × rate
T1 (VAT)         = (NetTotal + TotalTaxableFees + ValueDifference + T2 + T3) × rate
T4 (withholding) = (NetTotal − ItemsDiscount) × rate             (subtracted)
T13…T20          = non-taxable fees (added, not in the VAT base)
Total            = NetTotal + TotalTaxableFees + T2 + T3 + T1 + non-taxable fees − ItemsDiscount − T4
```
Invoice level: `TotalSales = Σ SalesTotal`, `NetAmount = Σ NetTotal`, `TotalItemsDiscount = Σ ItemsDiscount`, `TotalAmount = Σ line Total − ExtraDiscount`.
All amounts allow up to 5 decimals.

→ **The accountant is right: VAT is calculated on (net + table tax)**, plus any taxable fees and fixed table tax.

### Order inside `TaxCalculator`
1. Taxable fees T5–T12 (on net)
2. T3 fixed
3. T2 on (net + fees + T3)
4. T1 on (net + fees + T3 + T2)
5. Non-taxable fees T13–T20 (on net)
6. T4 on (net − items discount), subtracted

Every step is rounded to 5 decimals.

### Reverse calculation (VAT-inclusive prices)
The total is linear in net: `Total = net × a + b`, where
- `a = (1 + Σ taxable-fee %) × (1 + T2%) × (1 + T1%) + Σ non-taxable % − T4%`
- `b = (fixed fees + T3) × (1 + T2%) × (1 + T1%) + fixed non-taxable fees`

So `net = (price − b) / a`, rounded to 5 decimals, then the taxes are calculated forward with the rule above.

**Rounding:** with 5-decimal steps, about 13% of prices can't land exactly on the price; the total is then off by at most **0.00001**. I tested every price from 0.01 to 2,000.00 for 1% table tax + 14% VAT. The total is always correct at 2 decimals.

To make the total exactly equal the price, the calculator picks the smallest net whose total is ≥ the price. It puts the excess (≤ 0.00002) in the invoice `extra_discount_amount` (a non-taxable, after-tax discount, allowed by the ETA total formula). → **Waiting for the accountant's approval.**

The strategy is isolated behind `Tax/Rounding/RoundingStrategy`:
- `ExtraDiscountRounding`: exact total, tiny extra discount
- `NearestRounding`: accept the ≤ 0.00001 difference

The account setting `rounding_strategy` (default `nearest` until the accountant approves) picks one. The calculator never rounds anywhere else.

### Worked example — 1000 EGP, table tax 1% (T2 / Tbl01) + VAT 14% (T1 / V009)

**A) Price exclusive (1000 = net)**

| Line field | Calculation | Amount |
|---|---|---|
| Net total | 1 × 1000 | 1,000.00000 |
| T2 table tax 1% | 1000 × 1% | 10.00000 |
| T1 VAT 14% | (1000 + 10) × 14% | 141.40000 |
| **Total** | 1000 + 10 + 141.40 | **1,151.40000** |

(VAT on net alone would be 140.00; the difference is 1.40.)

**B) Price inclusive (1000 = what the member pays)**

| Line field | Calculation | Amount |
|---|---|---|
| Factor | 1.01 × 1.14 | 1.1514 |
| Net total | 1000 ÷ 1.1514 | 868.50790 |
| T2 table tax 1% | 868.50790 × 1% | 8.68508 |
| T1 VAT 14% | (868.50790 + 8.68508) × 14% = 877.19298 × 14% | 122.80702 |
| **Total** | 868.50790 + 8.68508 + 122.80702 | **1,000.00000** |

Effective tax inside an inclusive price: 131.49 of 1000 = **13.15%** (not 14% or 15%).

### Impact of VAT-inclusive (chosen default)
- Plan / reservation / package amounts, `Plan::amountBetween`, package totals, `remaining` and payments: **no change**. They all stay "what the member pays".
- A tax rate change reduces your margin; members' prices don't change.
- Revenue reports split net / table tax / VAT from **invoice lines**. Reservation amounts are gross.
- Business customers wanting exclusive quotes: the account setting `prices_include_tax = 0` makes plan amounts net. Only the calculator reads the setting.

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
- **VAT:** stored + switch only for now; the Phase 2 rule is in §3 Rules.

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

## 3. Phase 2 — Tax rates, invoices / receipts, ETA entry view

### New module `InvoiceModule`
```
Modules/InvoiceModule/
  app/Models/{AccountTaxSetting, TaxRate, EtaItem, Invoice, InvoiceItem, InvoiceItemTax}.php
  app/Models/Concerns/HasTaxes.php            morphToMany tax_rates via `taxables` (Plan, IncomeCategory, …)
  app/Services/Tax/TaxCalculator.php          line(qty, unitPrice|grossPrice, discount, taxes, inclusive) → LineResult (§1)
  app/Services/Tax/LineResult.php             value object (sales_total, net_total, taxes[], total, …)
  app/Services/Tax/Rounding/{RoundingStrategy, NearestRounding, ExtraDiscountRounding}.php   the only place rounding to the price happens
  app/Services/InvoiceBuilder.php             fromReservation / fromPackage (one line per plan) / fromPayment (other income) / blank
  app/Services/InvoiceService.php             saveDraft, issue (number + issuer snapshot + lock), void (draft / unsubmitted), creditNote / debitNote(from)
  app/Services/Eta/EtaDocumentMapper.php      Invoice → ETA JSON array (invoice, credit, debit, receipt); used by the entry view now, the API later
  app/Services/Eta/EtaGateway.php             interface: submit, status, cancel
  app/Services/Eta/ManualEtaGateway.php       now: register(uuid, long_id) → eta_status = registered
  (later) app/Services/Eta/ApiEtaGateway.php  ETA API + signing — no schema change
  app/Http/Requests/{TaxSetting, TaxRate, EtaItem, Invoice, EtaRegister, InvoiceVoid}Request.php
  app/Http/Controllers/Account/{TaxSetting, TaxRate, EtaItem}AccountModuleController.php
  app/Http/Controllers/Concerns/InvoiceActions.php + Account/Employee Invoice controllers (void / credit / debit note = account only)
  app/Http/Controllers/Account/EtaEntryAccountModuleController.php   entry view + register form
  resources/views/{Settings/form, TaxRate/{index,form}, EtaItem/{index,form}}.blade.php
  resources/views/Invoice/{index, form, _lines, show, pdf}.blade.php
  resources/views/Eta/{entry, register-form}.blade.php
  tests/Unit/TaxCalculatorTest.php            worked example (§1) + sweep of prices 0.01–2000 (inclusive total = price)
```

**ETA entry view:** the invoice laid out in the ETA portal's field order (issuer, receiver, each line with item code / unit / qty / unit value / discount / each tax type + subtype + rate + amount, totals), with a copy button per value. The view ends with a "Paste ETA UUID (+ long id)" form → `eta_status = registered`. Lists filter by `eta_status` (pending / registered).

### Changes in other modules
- **PlanModule:** plan form gets ETA item (select) + taxes (multi select). `Plan` uses `HasTaxes`.
- **PaymentModule:** income category form gets ETA item + taxes.
- **CompanyModule:** company gets tax id + address (for business receivers).
- **ReservationModule / PackageModule / PaymentModule:** "Create invoice / receipt" button in the view, plus an invoices block.
- **LayoutModule:** sidebar items Invoices, ETA pending (counter), Settings → Tax settings, Tax rates, ETA items.

### DB — new tables

**account_tax_settings**: `id`, `account_id` (unique), `tax_id` (RIN), `tax_name`, `activity_code`, `branch_id` (varchar, default '0'), `country` (char 2, 'EG'), `governorate`, `city`, `street`, `building_no`, `postal_code` (null), `floor` (null), `room` (null), `prices_include_tax` (bool, 1), `withholding_on_invoices` (bool, 0 — T4 lines on invoices), `rounding_strategy` (varchar, 'nearest' | 'extra_discount'), `invoice_prefix` (null), `receipt_prefix` (null), timestamps

**tax_rates**: `id`, `account_id` (index), `name_ar`, `name_en`, `tax_type` (varchar 4: T1…T20), `tax_subtype` (varchar 10: V009, Tbl01, Tbl02, …), `calc` enum('rate','fixed'), `rate` decimal(8,5) default 0, `fixed_amount` decimal(18,5) default 0, `is_active` (bool, 1), timestamps, softDeletes
*(no separate `eta_code`: `tax_type` + `tax_subtype` are the ETA codes)*

**taxables**: `tax_rate_id`, `taxable_type`, `taxable_id` — primary (`tax_rate_id`, `taxable_type`, `taxable_id`)
*(one polymorphic pivot instead of `plan_tax_rates` + `income_category_tax_rates` + …)*

**eta_items** (the account's ETA-registered codes): `id`, `account_id` (index), `name_ar`, `name_en`, `item_type` enum('EGS','GS1'), `item_code` (e.g. `EG-{RIN}-001`), `internal_code`, `unit_type` (ETA unit code, e.g. `EA`), `is_active` (bool, 1), timestamps, softDeletes

**invoices**

| column | type |
|---|---|
| `id`, `account_id` (index) | |
| `document_type` | enum('invoice','credit_note','debit_note','receipt','return_receipt') |
| `invoice_no` | varchar — unique (`account_id`, `document_type`, `invoice_no`) |
| `original_invoice_id` | bigint default 0 |
| `status` | enum('draft','issued','void') default 'draft' |
| `issued_at` | datetime null (Cairo; converted to UTC for ETA) |
| issuer snapshot | `issuer_tax_id`, `issuer_name`, `issuer_branch_id`, `activity_code`, `issuer_address` (json) |
| `member_id`, `company_id` | bigint default 0 |
| `receiver_type` | enum('business','person','foreigner') |
| `receiver_name`, `receiver_id_no` | varchar null |
| receiver address | `receiver_country` (char 2, 'EG'), `receiver_governorate`, `receiver_city`, `receiver_street`, `receiver_building_no`, `receiver_postal_code`, `receiver_floor`, `receiver_room` (all null) |
| `currency` | char 3 'EGP' |
| `exchange_rate` | decimal(12,5) default 1 |
| `total_sales` | decimal(18,5) — Σ sales_total |
| `total_discount` | decimal(18,5) — Σ taxable line discounts |
| `net_amount` | decimal(18,5) — Σ net_total |
| `total_items_discount` | decimal(18,5) — Σ non-taxable discounts |
| `extra_discount` | decimal(18,5) — invoice level, after tax |
| `tax_total` | decimal(18,5) |
| `total` | decimal(18,5) |
| `eta_status` | enum('pending','registered','rejected','cancelled') null |
| ETA ids | `eta_uuid`, `eta_long_id`, `eta_submission_uuid` (varchar null) |
| `eta_submitted_at` | datetime null |
| `eta_response` | json null |
| `external_ref` | varchar null |
| `void_reason`, `notes` | null |
| creatable morphs, timestamps, softDeletes | |

**invoice_items**: `id`, `invoice_id` (index), `itemable_type` (null), `itemable_id` (default 0), `eta_item_id` (default 0), `description`, `item_type`, `item_code`, `internal_code`, `unit_type`, `quantity` decimal(18,5), `unit_price` decimal(18,5), `sales_total`, `discount_rate` decimal(8,5), `discount_amount`, `net_total`, `value_difference`, `total_taxable_fees`, `items_discount`, `tax_total`, `total` (amounts decimal(18,5)), `sort_order`, timestamps

**invoice_item_taxes**: `id`, `invoice_item_id` (index), `tax_rate_id` (default 0), `tax_type`, `tax_subtype`, `rate` decimal(8,5), `amount` decimal(18,5)

### DB — existing tables (by hand + `create_*` migration)
```sql
ALTER TABLE plans              ADD eta_item_id BIGINT NOT NULL DEFAULT 0 AFTER is_time_based;
ALTER TABLE income_categories  ADD eta_item_id BIGINT NOT NULL DEFAULT 0 AFTER name_en;
ALTER TABLE companies
  ADD tax_id VARCHAR(30) NULL, ADD country CHAR(2) NOT NULL DEFAULT 'EG',
  ADD governorate VARCHAR(100) NULL, ADD city VARCHAR(100) NULL,
  ADD street VARCHAR(255) NULL, ADD building_no VARCHAR(50) NULL;
```

### Rules
- Issued documents can't be changed. A credit / debit note copies the receiver and references `original_invoice_id` (ETA needs the original's UUID).
- Void is only allowed for drafts, or issued documents with `eta_status` not registered. After registration → credit note.
- The issuer snapshot is taken on issue, so changes to the tax settings don't alter old invoices.
- A package invoice = one line per plan (sum of the package's counted reservations), each with that plan's ETA item + taxes. The package discount = line `discount_amount`, split by share.
- Auto-creation later = listen to reservation / package / payment events → `InvoiceBuilder` → `InvoiceService::issue()`. No schema change.
- **VAT switch (Settings › General, `accounts.vat_enabled`, default ON; decided 2026-10-13, apply when Phase 2 is built):** when OFF the
  account is not VAT registered → ALL prices are tax free: no tax rates are used or offered (`tax_rates` / `taxables` ignored, the
  tax settings screens and the plan / income category tax pickers hidden), `TaxCalculator` returns net = price with no tax lines,
  invoices / receipts carry no tax lines and no tax totals (ETA document without T1 / T2 / T3 taxes). Turning it ON again affects
  only documents issued after that (issued documents never change).

### Phase 2 build order
1. Tables + `AccountTaxSetting` form
2. `TaxRate` + `EtaItem` CRUD, `taxables`, plan / income category form fields
3. **`TaxCalculator` + unit tests** (worked example and the 200k-price sweep) — before any UI
4. `InvoiceBuilder` + `InvoiceService` (draft, issue, numbering, snapshot)
5. Invoice form (lines editor with live totals → server recalculates) + show + PDF
6. Create from reservation / package / payment
7. `EtaDocumentMapper` + ETA entry view + register UUID + pending list / counter
8. Void, credit note, debit note, receipt / return receipt
9. Company tax fields, sidebar items (confirm)

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
| Tax | invoices, invoice_item_taxes | per month: net, each tax type (T1, T2, …), credit notes deducted |
| ETA status | invoices | pending / registered / rejected, by document type |
| Audit | payments (trashed), day_closings | deleted payments with reason + who, reopened days |

### Build order
Daily cash → Outstanding & credits → Member statement → Income → Withholding → Tax → ETA status → Audit; then Excel / PDF exports. Sidebar "Reports" (confirm).

---

## 5. Questions — status (2026-10-10)
1. ~~Reservation view 500~~ → **both** (SQL data fix + code guard), before Phase 1.
2. **Rounding to the exact price** (extra discount) → **waiting for the accountant**. Doesn't block anything; switch = the `rounding_strategy` setting.
3. ~~Old method 5 "إشعار خصم"~~ → **confirmed withholding**, imported as planned.
4. ~~Defaults in §0~~ → **approved**, with two changes: day closing totals by employee × payment method (withholding excluded from cash), and T4 on invoices = setting `withholding_on_invoices` (default off).
5. **T4 on invoices** → **waiting for the accountant** (only changes the setting's default).
