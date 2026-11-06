# Reports plan (ReportModule)

> Approved by the user 2026-11-06. Replaces `docs/payments-plan.md` §4 (its "Daily cash" / "Audit" rows read `day_closings`,
> a table dropped 2026-10-13: closing = the shift, `shift_closings`, and the Daily Summary already is the daily cash report).
> Build order 1 → 6, the user tests each before the next; one commit per report.

## 0. Common rules

- **Module:** `Modules/ReportModule` — `app/Services/ReportService` (one query method per report, always `account_id` scoped,
  aggregated in SQL: never all rows into PHP), `app/Exports/*Export` (maatwebsite/excel 3.1, already in composer.json,
  `FromQuery` + chunks), `Concerns/ReportActions` + `Account|Employee/Report*ModuleController` (thin), routes
  `{area}.reports.{report}[.data | .excel | .pdf | .member]`, views `Report/{report}` + `Report/partials/*`.
- **Page:** toolbar = search | date (`layoutmodule::partials.date-range-filter`) | selects | Clear; a totals line / row
  (json `html` → `[data-html]`, same request as the rows); "Export Excel" = the same filters, with the totals row.
- **Permissions** (EmployeeModule config, group "Reports"): `reports.outstanding`, `reports.member_statement`,
  `reports.income`, `reports.expenses`, `reports.subscriptions`, `reports.occupancy`. In no preset, never in Front desk
  (`EmployeeRoutesTest::NOT_FRONT_DESK` has `reports.`); the owner passes all. A key = the whole account's data (not
  `CashScope`); Excel / PDF come with it; every export is written in the Access Log (`export`, the owner's too).
  The local role "Accounting Office" gets outstanding, member_statement, income, expenses (fix file
  `database/fixes/2026-11-06-accounting-office-report-permissions.sql`; the server's role by name).
- **Sidebar:** a "Reports" section (account: after Management; employees: after Daily, only the reports the role has).
- **Indexes:** migration `ReportModule 2026_11_06_100055_add_report_indexes`: `payments (account_id, paid_at)`,
  `reservations (account_id, remaining)`.
- **Money rules (existing, reused):** what a reservation costs = `Reservation::dueAmount()` / `DUE_SQL`; paid / remaining =
  `PaymentService::refresh()` (stored `paid_amount` / `remaining`); not counted statuses = due 0
  (`ReservationStatus::notCountedIds()`); a reservation inside a package = paid through the package (remaining 0 there).

## 1. Outstanding & credits (`reports.outstanding`)

- **Source:** `reservations` (`package_id` = 0, not deleted, `remaining` <> 0) UNION ALL `packages` (not deleted,
  `remaining` <> 0), grouped by `member_id` in SQL; `members` + `companies` for the names.
- **Rows (one per member):** Member (phone, company) | Owed (Σ remaining > 0) | Credit (Σ −remaining < 0) | Net |
  Periods (count) | Oldest due since (earliest start of an owed row). The member opens a popup with his rows:
  No. | Kind (reservation / package) | Space › Unit / package name | Period | Due | Paid | Remaining.
- **Filters:** search (member name / phone) | date = the row's start (reservation `start_at`, package `date_from`) |
  type → space → plan | Show: Owed & credit / Owed only / Credit only | "Include future" checkbox.
- **Totals:** Owed, Credit, Net, members, periods.
- **Rules (user 2026-11-06):** today's balance (the stored remaining), no "as of" date; future periods (start after the
  business date) are left out of Owed by default — "Include future" adds them; a credit always shows (money already
  received); a type / space / plan filter leaves packages out (a package has no single space / plan); reservations without
  a member (9 old rows) = one "No member" row.

## 2. Member statement (`reports.member_statement`)

- **Source:** charges = the member's reservations paid on their own (due = `dueAmount()`, dated by the START date),
  packages (`after_discount`, dated by `date_from`), extras (their date, adjustments = balance moves); payments = cash in +,
  withholding +, refunds − (dated `paid_at`). Reservations inside a package = information lines at 0.
- **Rows:** Opening balance | Date | Type | Description (No., space › unit, period) | Charge | Payment | Balance (running) |
  Closing balance.
- **Filters:** member (member-select search) | date range.
- **Output:** PDF (mpdf + Cairo, `pdf.css`, AR / EN like the package statement) + Excel.
- **Rules:** not counted statuses = 0 with "Cancelled" (user); opening = everything before the range.

## 3. Income (`reports.income`)

- **Source:** `payments` by `paid_at` (cash basis only): cash in + withholding − refunds (a refund on its OWN date);
  other income only from `income_categories.is_revenue` = 1 (no Advance repayment, no VAT (manual)).
- **Group by (one at a time):** Space › Unit | Subscription type | Plan | Payment method | Month. Package payments = their
  own "Packages" row (no split); other income = a row per category.
- **Columns:** Cash In | Refunds | Withholding | Tax (VAT + table tax from the stored split of taxed rows) |
  Net (before tax) | Total.
- **Filters:** date | type → space → plan | method.

## 4. Expenses (`reports.expenses`)

- **Source:** `expenses` by `spent_at`; two blocks: Shift Cash Out (`Expense::scopeInShifts()`) and Company Cash Out
  (`is_company`: Advances, Salaries). Refunds are NOT here (they are the Refunds column of Income).
- **Columns:** Category | Count | Amount (subtotal per block + grand total); detail list No. | Date | Category | Item |
  Method | By | Amount.
- **Filters:** date | category | method | Shift / Company / All.

## 5. Subscriptions (`reports.subscriptions`)

- **Source:** reservations of `booking_mode` = period types; the existing rules only: `Reservation::scopeRenewalStatus()`,
  `RENEWAL_DATE_SQL`, `OWES_SQL`, `RenewalView` (texts / badges).
- **Counts + list per tab:** New (starts in the range, `renewal_of` NULL) | Renewed (renewal date in the range, the
  renewal exists AND is paid) | Not renewed (renewal date in the range, no paid renewal, not left; inside it the count
  "Renewed · unpaid") | Ending soon (renewal date within N days: a field on the report, default the account's
  `renewal_days`) | Left.
- **Columns:** Member | Space › Unit | Plan | Period | Renewal date | Amount | State.
- **Filters:** date | space → unit | plan | N days.

## 6. Occupancy (`reports.occupancy`)

- **Source:** counted reservations overlapping the range, each clipped to the range only (`GREATEST` / `LEAST`, no
  per-day business hours), summed per unit in SQL, rolled up per space; today's active units only.
- **Available:** hourly = active units × `concurrent_usage` × business hours (`today_hours_from` → `to`) × days;
  full day = units × days (booked = days); period = units × days (booked = days covered by a period).
- **Columns:** Space › Unit | Mode | Booked | Available | % (bar); totals per space + overall.
- **Filters:** date (default this month) | type | space.
