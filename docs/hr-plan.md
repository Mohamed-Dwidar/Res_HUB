# HR & Payroll (HrModule + PayrollModule) — plan

> APPROVED by the user 2026-11-04 (with the answers + rules of §A / §B). Read before any HR / payroll work.
> Order: H1 → H1b → H2 → H3 → H4 → H5 → (H6 attendance, only if needed) → H7 recruitment.
> Status: H1 (staff files & documents) built 2026-11-04; 2026-11-05 rewritten on the `employees` table (§A 10), migrations
> waiting for the user's review (§10).
> Every number of the law (rates, brackets, leave days, overtime %) is a SETTING with an effective date, reviewed by the accounting
> office (§9). Nothing legal is written in the code.

---

## A. Decisions (user 2026-11-04)

1. Two modules: **HrModule** + **PayrollModule**.
2. Employment contracts: separate small tables reusing the ContractModule code (salary privacy) — §3.2 (b).
3. Logging: employees' HR views, downloads and exports; the **owner's downloads and exports** too (not the owner's views).
4. Lock = `payroll.approve`, in NO preset → the owner only for now.
5. Reopen a locked payroll: allowed — the owner only, the LAST locked run only, no salary payment made from it yet, reason
   required, logged (`access_logs` action + the run's history).
6. Self-service (own leave requests, own payslips): LATER, after payroll is stable (`leaves.request_own`, `payroll.own_payslips`
   stay in the catalog plan only).
7. Staff files: everyone paid a salary, INCLUDING the owner (Dwidar, owner and responsible manager "مدير مسؤول"; his file has no
   Employee login link — he uses the Account login). No partners.
8. **Salary payments are ACCOUNT-LEVEL, never in a shift** (§6.4): paid by the owner or a role with `payroll.pay` (no preset; the
   payer usually has no shift); one Cash Out per payment, generated from a LOCKED payroll line ([Pay] per row + "Pay all"),
   category "Salaries", linked to staff + month + line; partial payments (the line shows paid / remaining); "Paid from" = the
   existing payment methods (owner / company cash, bank transfer, wallet), reference optional; `shift_closing_id` stays 0 forever +
   a flag so no shift logic picks them up; never in an employee's hand-over; Daily Summary, the Payments list and reports show
   them apart ("Company"). Advances the same: category "Advances", linked to the advance, `payroll.pay`, recovered by installments.
   After go-live "Salaries" and "Advances" can't be used for manual Cash Out (generated only).
9. Backlog (not now): an account setting to pay salaries from front-desk cash (a pending payout confirmed by the front-desk
   employee into their shift) — for other accounts.
10. **ONE row per person: the HR file IS the `employees` row (user 2026-11-05; replaces the separate `staff` table of
   2026-11-04).** `employees` gets the HR fields (migration `2026_11_04_100034_add_hr_fields_to_employees_table`); a person may
   have NO login — a login = a `users` row with userable = the employee, made / switched off from the staff form's System
   Access. HrModule's `Staff` model = the same row (`$table = 'employees'`, `getMorphClass()` = Employee) with the HR fields,
   casts and relations, so EmployeeModule still loads no HR code. History / documents = `employee_events`,
   `employee_documents`, `employee_document_files` (`100035`). **Rule: `employees` includes people without a login — any code
   listing / counting logins must use `Employee::withLogin()`** (Roles "Users" count, the Access Log filter; shift / payment
   code goes through `users` already). Why: two rows per person (staff + employees) had to be kept in step (`syncProfile()`
   copied name / phone / job / shift / dates to the login on every save), the 1:1 link could break, every existing login needed
   a "staff file" made by a command (`hr:staff-from-users`, with exclude / external options and a duplicate check), and the
   old Users urls needed a lookup; with one row the staff id = the employee id, nothing is copied, no command, no link to lose.
   Columns kept as they are and reused by HR: `name` (+ new `name_ar`), `phone` / `another_phone`, `gender`, `birth_date`,
   `address`, `educational_qualification`, `position_id`, `shift_id`, `join_date` (= hire date), `leave_date` (= the login is
   off from that day), `role_id`. The form's Name is now one required "Name" (+ optional Arabic name) instead of "English or
   Arabic". Backfill: numbers 1 … n, status Resigned since `leave_date` else Active, contact email = login email, #3 ABEX =
   External, #2 "User" (old test login) soft deleted. Future HR / payroll tables point to the person with `employee_id`
   (= employees.id; the `staff_id` names in §3.2 – §6 below read as `employee_id`).

## B. UX rules (user 2026-11-04 — apply to every phase)

- As few pages as possible: ONE **Staff** list + ONE **staff page** with tabs (Profile, Salary, Documents, Leaves, Advances &
  adjustments, Payslips — a tab appears with its phase). No separate page per concept.
- ONE **Payroll** page per month: a single table, one row per person (basic, additions, deductions, net, paid, remaining), inline
  cells during review (absence days, overtime hours, one-off bonus / deduction), totals at the bottom, buttons only for the next
  step (Calculate → Review → Lock → Pay). No wizards, no multi-step forms.
- Defaults everywhere (salary from the file, insurance from the rule set, the current month pre-selected): the usual month =
  open → check → lock → pay.
- Plain labels (Arabic-friendly), short popup forms, existing components only, works on a phone.
- Hide what isn't used (no insurance → no insurance fields; an empty tab = one line + an Add button).
- Warnings instead of blocking where possible.

**What this changed in the plan (simplified):**
- No pages for documents, expiring documents, insurance, leaves, advances, adjustments, payslips → tabs of the staff page +
  filters / a notice on the Staff list ("Documents: expired / expiring / missing"; later "Leave requests pending").
- Settings (2026-11-05, user): the HR setup lists live in the sidebar's HR section (Employee Departments, Employee Positions, Employee Shifts, Document Types; hr.manage; later leave types, holidays, pay items, violation types there too) — no "Settings › HR" page
  instead of a settings page each; payroll rule sets = one "Payroll Rules" page (H2).
- Unconfirmed rule set: **a warning, not a block** — Lock is allowed, the Payroll page and the run say "Rules not confirmed by
  the accounting office" (was: Lock refused).
- Missing required documents: a warning (staff page + the Staff list filter), never a block.
- Violation types optional: none added = a penalty is an amount + reason; the tiers appear only once a type exists.
- Advance installments: skip / change from the Payroll row (a cell), no separate screen; insurance monthly report = an export
  button on the Payroll page; employment contract = a block in the Profile tab (no contracts list page).
- H1: no photo field (a "Personal photos" document type instead); `rest_days` moves to H3 (only leaves use it).
- **Staff = the ONE place for people (user 2026-11-04, replaces the same day's "Users" page + System Access tab):** no Users list
  page (its old urls redirect to Staff); the `employees` (+ `users`) row stays as the auth record, linked 1:1 to the staff file,
  with no UI of its own. The staff add / edit form has a **System Access** section: "Can log in" + Login Email + Role + password
  (new access: Password + Confirm required; existing: a "Change password" checkbox, the password never shown); unticking it on
  an existing login = deactivated (row + Access Log history kept). The profile shows Login Email, Role, Last login, Active /
  Inactive read only. Any status but Active switches the login off at once (session ends at its next request); Active again does
  NOT switch it on. Permission **`users.manage`** (owner by default, no preset, never on one's own file) for access / email /
  password / role; without it the section is read only. **External** staff (`employees.is_external`, "not on payroll", e.g.
  the accounting office): out of payroll, headcount, HR reports and document warnings, only name, mobile, email required,
  system access as usual. (2026-11-05: every existing login already is a file — the same row; no command, §A 10.)
- **APP_KEY (user 2026-11-04):** staff bank / wallet numbers are encrypted with APP_KEY — NEVER change APP_KEY on the server and keep a backup of it (with the DB backups), or that data is lost.

## 0. Today (what we already have)

- `employees` = a LOGIN (EmployeeModule, `users.userable`): name, phone, gender, birth date, address, image (public uploads),
  `salary` (double, set for 3 of 15), `position_id` (`employee_positions`: Reception, HR, Accountant, Office Boy, CEO), `shift_id`
  (`employee_shifts`: Morning 08:30–16:30, Night 16:00–23:59, Full Day), `join_date`, `leave_date` (blocks the login),
  `employee_attachments` (0 rows), `role_id`. Email + password are required: a person without a login can't be stored.
- Salaries are paid today as **Cash Out, category #28 "Salaries"**, entered by the owner, one row per person, item = the name
  ("Am Emad", "Am Mohamed", "Ahmed Salah" …), ~30,000 / month, ~7 people, almost all Cash; advances are typed into the same
  category ("سلفة - اينور" 2,000 on 24-10; Aynour's October salary row = 1,735, the advance probably already taken off). Several
  paid people (Am Emad, Am Mohamed) have no login → the HR file must not need one.
- Reusable: roles + `Gate` (`config('employeemodule.permissions')`), `access_logs` (events `export` / `action` already reserved),
  private disk downloads (contracts, withholding), mpdf + Cairo PDFs, TinyMCE + `ContractService::sanitize()`, Cash Out +
  shifts (`ExpenseService`), a calculator + unit tests pattern (`TaxCalculator` / `TaxCalculatorTest`).

## 1. The reference project (Noon `Hr_NoonTraining`, read only — ideas only)

All 8 modules are thin CRUD: no `account_id`, no approval, no history, no lock; month stored as a `Y-m` string.

| Module | What it does | Tables | Feeds payroll | For us |
|---|---|---|---|---|
| Attendance | a MONTHLY sheet per employee: working days, present, absent, rate %, status text | `attendances` (employee, month, monthly_working_days, days_present, days_absent, attendance_rate, status) | **No** — payroll ignores it (absence = leave days) | Idea kept: a monthly "days / hours" entry per person = our manual first step, but inside the payroll review (§6), not a module |
| Bonuse | free bonus per employee per month | `bonuses` (employee, month, amount, reason) | Σ amount added | Merged → `payroll_adjustments` |
| Deduction | free or "violation" deduction per month | `deductions` (type custom/violation, employee, month, violation_id, violation_repeat_number, amount, reason) | Σ amount taken off | Merged → `payroll_adjustments` |
| Violation | catalog of violations + an amount per repeat (1st, 2nd, 3rd …); the deduction form counts the employee's repeats in the month and picks the tier | `violations`, `violation_repeats` (violation, repeat_number, deduction_amount) | through Deduction | **Useful** (the repeat tiers). Merged → `violation_types` with tiers as JSON, repeats counted in a window (setting), amount in days of wage or fixed |
| Commission | catalog fixed / % per student sold (training business) | `commissions` (name, type, value) | Σ per student of the month | Business-specific. Out as a module; a "Commission" pay item for manual entries. Automatic (e.g. on packages sold) = backlog |
| Leave | leave rows: type text, start / end, days (calendar, weekends included), month = start month | `leaves` | days × daily rate taken off (every leave is unpaid!) | Idea only. Ours needs types (paid / unpaid / partly paid), balances, approval, and a leave across two months split by month (theirs charges it all to the start month) |
| Payroll | one row per employee / month, RE-CALCULATED on every bonus / deduction / leave change and on opening the page; totals only; net floored at 0; daily rate = basic ÷ employee's working days; payslip + details PDF | `payrolls` (employee, month, days, basic, deductions, bonuses, students_commission, total_salary) | — | Ideas: "ensure a line per active employee", payslip + details PDF. Avoid: no lock (a past month changes when anything changes), no item lines, no insurance / tax, no payment link |
| Task | to-do per employee (title, dates, status new / in_progress / completed / late) | `tasks` | No | **Out** (not HR, not money). Operational checklists (opening / closing, cleaning rounds) could be a separate ops feature later |

Result: 8 modules → **2** (§2). Bonuses + deductions + violations + overtime + commission = one "adjustments" area; attendance
= manual fields in the payroll review until proven necessary.

## 2. Modules (smallest sensible set) and relations

Two modules, split by sensitivity (people vs money) — each with its own permission group:

| Module | Contains | Services |
|---|---|---|
| **HrModule** | staff files, departments, documents + expiry reminders, employment contracts (H1b), insurance registration data, leaves + public holidays, (later) attendance, recruitment | `StaffService` (file, documents, status, login link, insurance registration), `StaffContractService`, `LeaveService` (+ later `AttendanceService`, `RecruitmentService`) |
| **PayrollModule** | pay items catalog, salary history (compensation), payroll rule sets (effective dates), adjustments + violation types, advances, payroll runs / lines / payslips, salary payments → Cash Out, insurance & tax reports | `PayrollSettingService` (pay items, rule sets), `CompensationService`, `AdjustmentService`, `AdvanceService`, `PayrollService` (runs, calculate, lock, pay) + `Services/Calc/{InsuranceCalculator, SalaryTaxCalculator, PayrollCalculator}` (pure, unit tested like `TaxCalculator`) |

Not inside EmployeeModule: EmployeeModule = logins, roles, access log; the front desk must never load salary code by accident.
Conventions as everywhere: `account_id` on every table, `Concerns/*Actions` + thin Account / Employee controllers (employees by
permission), Prettus repositories `forAccount()`, Form Requests, popups, DataTables `table()`, `{area}.hr.*` / `{area}.payroll.*`
routes, soft deletes, `0` = none, decimals (12,2), snapshots in JSON.

Relations:
- **EmployeeModule (2026-11-05, §A 10):** the HR file = the `employees` row (HrModule `Staff` model on the same table); the
  login = its `users` row (optional). Any status but Active switches the login off (`leave_date`); `employees.salary` stays
  unused until H2 — ask before removing anything.
- **Roles:** new keys §4; none in Front desk. `RoleService::allows()` unchanged.
- **access_logs:** new events `view`, `download` (+ existing `export`, `action`); subject = staff / document / payroll line.
- **ContractModule:** code reused, data NOT shared (§3.2).
- **PaymentModule:** salary payments + advances = ACCOUNT-LEVEL Cash Out rows (`expenses.is_company` = 1, never in a shift),
  made by `ExpenseService::createCompany()`, linked by `payroll_payments` / `staff_advances.expense_id`; `ExpenseService` refuses
  editing / deleting them (undo from the Payroll page / the advance, logged); categories "Salaries" / "Advances" generated only
  (§6 step 4, §6b).
- **AccountModule:** business day not used by HR (HR days = calendar dates); account name / address on payslips.

## 3. Data model

### 3.1 HR file (HrModule, H1 — as built)

- `departments` — account_id, name_ar, name_en, is_active, soft deletes. Job title = the existing `employee_positions` (reused).
  **Department → Job Title (user 2026-11-05, migration `2026_11_05_100038`):** a job title may have a department
  (`employee_positions.department_id`, 0 = none; Settings › Employee Positions, the field only when departments exist); an
  employee's department = his job title's (no `employees.department_id`: dropped, it had no data). Staff form: with departments,
  Department first (filters only, not saved) → Job Title = that department's titles + titles with no department (a department
  change empties the title; custom.js `filterChain`, `data-match="*"` / `data-filter-reset`); without departments, every title.
  Job Title required for employees (not External). Lists / filters / reports use the title's department; a department used by
  job titles can't be deleted.
- `employees` (the HR file, §A 10; migration `100034` adds): `employee_no` (MAX + 1 per account, under a lock),
  `is_external`, `name_ar`, `email` (contact; the login email is in `users`), `probation_ends_on`, `status`
  active | on_leave | resigned | terminated + `status_date` + `status_reason`; identity (`hr.sensitive`): nationality, id_type,
  national_id (TEXT, encrypted), marital_status, military_status (males) + the existing birth_date, educational_qualification,
  address; pay (`hr.sensitive`): pay_method cash | bank | wallet, bank_name, bank_account + wallet_number (encrypted, TEXT);
  emergency (`hr.sensitive`): emergency_name / relation / phone; notes, creatable. Work = the existing position_id, shift_id,
  join_date (hire date). The login: `users` row (optional), `role_id`, `leave_date` (off from that day).
- `employee_events` — employee_id, type (created, updated, status, access_on / access_off / access_changed, document_added /
  updated / deleted), `changes` JSON (before / after; secret fields as "changed" without values), reason, creatable,
  created_at. The Profile tab shows them (History).
- `document_types` — account_id, name_ar / en, `kind` national_id | other | NULL, has_expiry, remind_days (30), is_required,
  is_active, sort_order, soft deletes; the migration (`100033`) adds 9 defaults per account (National ID, Birth certificate,
  Military status certificate, Criminal record, Qualification certificate, Personal photos, Insurance print, Medical certificate,
  Other), editable / deletable.
- `employee_documents` — account_id, employee_id, document_type_id, `name` (an "Other" document's own name), number (never for
  the National ID: the number lives in the profile, encrypted, last 4 digits without hr.manage), issue_date, expiry_date, notes,
  creatable, soft deletes; `employee_document_files` — one or more files per document (front + back) on the private disk
  `hr/{account}/{employee}/{uuid}.{ext}` (PDF / JPG / PNG ≤ 10 MB), original_name, size; served only by
  `StaffActions::documentFile()` (each open / download in the Access Log, the owner's too).
- Expiry: expired / expiring (within the type's `remind_days`) / missing required → badges on the Documents tab, a notice + a
  "Documents" filter on the Staff list, the sidebar counter (expired + expiring). Computed on view, no cron.
- Login: the staff form's System Access (users.manage) makes the `users` row for the file / changes email, role, password
  ("Change password") / switches it off; status ≠ Active switches it off. A file with a login can't be deleted (change the
  status / switch the access off); a file made by mistake (no login, no documents) can, by the owner. The old Users urls
  (`/account/employees/{id}`) redirect to the staff page of the same id.
- `employee_attachments` (0 rows, no files: the old Employees page's attachments): its code removed 2026-11-04; the table stays
  until the user confirms a drop migration (not written yet).
- Add Staff can upload documents (rows: type, name, number, expiry, files). Staff popup = modal-xl, `.form-grid` 1 / 2 / 3 columns.
- **Deploy checklist (HR files):** `storage/app/private/` writable by the web user (e.g. `chown -R www-data:www-data storage`,
  dirs 775 / files 664), the document root = `public/` only (never `storage/`), no symlink into `storage/app/private`
  (`storage:link` links only `storage/app/public`); PHP `upload_max_filesize` ≥ 10M and `post_max_size` ≥ 64M (several files);
  **back up `storage/app/private/`** (hr, contracts, withholding, expenses) on the same schedule as the DB and off the server —
  the DB dump does not contain the files, restore both together; back up `.env` (APP_KEY) — without it the ID / bank / wallet
  numbers can't be read.
- **Server deploy (H1, 2026-11-05):** (1) back up the DB (at least `employees`, `users`, `roles`) + `.env`; (2) `git pull`;
  (3) `php artisan migrate` → `2026_11_04_100031` (departments), `100033` (document_types + 9 defaults), `100034` (HR fields on
  employees + backfill), `100035` (employee_events / documents / files); (4) `php artisan optimize:clear`; (5) check: HR › Staff
  lists every current login (Resigned for the left ones, #3 ABEX External, #2 "User" gone), Settings › Roles "Users" unchanged.
  No command to run (the old `hr:staff-from-users` step is gone).

### 3.2 Employment contracts (H1b) — compare

| | (a) Extend ContractModule (`contracts.kind = employment`, staff_id, new placeholders) | (b) Light HR contracts reusing ContractModule's code |
|---|---|---|
| Data fit | `contracts` is built for tenants: reservation_id, unit_*, tenant_*, term_amount, renewal chain of reservations | own fields: staff, position, salary snapshot, probation, fixed-term / open-ended |
| Permissions | **risk:** `contracts.view` is in Front desk → reception would list employment contracts with salaries | `hr.contracts` only |
| Numbering / lists | shared number series + mixed list (rental + staff) | own series, own list in the HR file |
| Work | many `if kind` branches in a live module | ~3 tables, reuse `ContractService::sanitize()`, the TinyMCE setup (`contractEditors()`), the placeholder chips partial, the PDF CSS / Cairo, the issue = snapshot rule |

**Recommended: (b).** Tables: `staff_contract_templates` (account_id, name, title_ar / en, body_ar / en, contract_type
fixed_term | open_ended, default_months, probation_months, is_enabled), `staff_contracts` (staff_id, template_id, contract_no,
type, starts_on, ends_on (NULL = open-ended), probation_months, salary snapshot (basic + allowances), position / department
names, body_ar / en, values JSON, status draft | issued | signed | ended, issued_at / by, signed_on, signed_file (private),
ended_on, end_reason). Placeholders from the staff file + the compensation in force + the account (employer) data. Fixed-term
contracts ending within N days appear in the same "Expiring" list.

### 3.3 Salary & insurance (PayrollModule, H2)

- `pay_items` — account_id, code, name_ar / en, `kind` earning | deduction, `category` basic | allowance | bonus | overtime |
  commission | absence | unpaid_leave | penalty | advance | insurance | tax | statutory | other, flags `is_insurable`,
  `is_taxable` (earning) / `before_tax` (deduction reduces the taxable amount), `is_system` (basic, insurance, tax, advance,
  unpaid leave, absence: can't be deleted), is_active, sort. Accounting decides the flags; the calculator only reads them.
- `staff_compensations` — staff_id, `effective_from` (date), basic_salary, `items` JSON [{pay_item_id, amount}] (fixed
  allowances: transport, meals, housing, phone, night …), insurable_wage, reason, creatable. Never edited once a locked payroll
  used it (a raise = a new row). History list in the file (needs `payroll.view`).
- `staff_insurance` — staff_id (unique), is_insured, insurance_number, registered_on, form1 / form6 = documents (types), left_on,
  notes. The insurable wage comes from `staff_compensations` (dated), clamped to the rule set's min / max.

### 3.4 Leaves (HrModule, H3)

- `leave_types` — account_id, name_ar / en, `pay_percent` (100 paid, 0 unpaid, e.g. 75 partly paid sick), `uses_balance`,
  `yearly_days`, `entitlement_rule` JSON (e.g. after N months of service, more days after N years / from age N — values from the
  office, §9), `carry_over_max`, `needs_document` (sick → medical certificate), `counts` calendar | working (excluding the
  person's rest days + public holidays), is_active. Presets (annual, casual, sick, unpaid, maternity, Hajj, exam …) filled with
  the values the office confirms.
- `public_holidays` — account_id, date, name_ar / en (moving Islamic dates → entered each year; also used by overtime + attendance).
- `leave_balances` — staff_id, leave_type_id, year, entitled, carried, adjusted, adjust_reason. Used = Σ approved requests (computed).
- `leave_requests` — staff_id, leave_type_id, from_date, to_date, days (computed, half days allowed), reason, document_id, status
  pending | approved | rejected | cancelled, requested_by (creatable: HR or the person's own login), decided_by / at,
  decision_note. An approved leave inside a LOCKED payroll month can't change → a correction adjustment in the next open month.
- Payroll link: approved leave days in the period with `pay_percent` < 100 → automatic deduction item (days × daily rate ×
  (100 − pay_percent) %), split per month when a leave crosses months.

### 3.5 Adjustments & advances (PayrollModule, H4)

- `violation_types` — account_id, name_ar / en, `tiers` JSON [{repeat: 1, action: warning}, {repeat: 2, days: 0.5}, {repeat: 3,
  days: 1}, … or fixed amount], `repeat_window_months` (setting), is_active.
- `payroll_adjustments` — account_id, staff_id, `period` (first day of the month, a date), pay_item_id, quantity (days / hours) +
  rate (from the rule set: daily / hourly × multiplier) → amount, or a typed amount; reason (required), violation_type_id +
  repeat_no, source manual | leave | attendance | correction, source_id, creatable, `payroll_line_id` (0 until locked; then
  frozen). Bonus, incentive, overtime, night work, commission, penalty (جزاء), absence, lateness, damage / cash shortage, one-off
  allowance, corrections.
- `staff_advances` — account_id, staff_id, advance_no, amount, given_on, installment_amount (or count), first_period, reason,
  status open | settled | cancelled, `expense_id` (the Cash Out that paid it), approved_by, creatable.
- `staff_advance_moves` — advance_id, type deduct | repay | skip | change, amount, period, payroll_line_id / payment_id, reason,
  creatable. Remaining = amount − Σ deduct − Σ repay. Payroll proposes the installment (never more than the remaining; reduced
  when the net would go below the rule set's minimum net); the reviewer may skip a month from the Payroll row (reason). A cash
  repayment = Cash In, other income category "Advance repayment" (not counted as income, like "VAT (manual)").
- Giving an advance (`payroll.pay`) = an account-level Cash Out, category **"Advances"** (generated only), `expense_id` on the advance.
- **Opening balances at go-live (H4, proposal):** today advances are typed into "Salaries" (e.g. "سلفة - اينور" 2,000 on 24-10).
  Old rows are NOT changed. For each person still owing, the owner adds the advance with "Opening balance" ticked
  (`staff_advances.is_opening` = 1): amount = what is still owed on the go-live date, given_on = the original date, installment,
  first period = the first payroll month, an optional note / old Cash Out no. (e.g. "#100123"); NO Cash Out is generated (the
  money left with the old row). A short list to fill once: name, owed, installment (the office / owner gives it).

### 3.6 Payroll (PayrollModule, H5)

- `payroll_rule_sets` — account_id, `effective_from`, name, `rules` JSON (§5), `confirmed_note` + confirmed_by / at ("reviewed by
  the accounting office on …"); an unconfirmed set = a WARNING on the Payroll page and in the run (§B), not a block; a set used
  by a locked run is read only.
- `payroll_runs` — account_id, run_no, period (month), period_from / to, type regular | off_cycle (bonus month, final
  settlement), status draft | review | locked, rule_set_id + `rules` snapshot, totals JSON, created / sent_to_review / locked by +
  at, reopen_count, notes.
- `payroll_lines` — run_id, staff_id, snapshot (staff_no, name ar / en, position, department, national_id, insurance_number,
  pay_method, bank / wallet masked), days_in_period, days_in_service, absence_days, unpaid_leave_days, overtime_hours, basic,
  gross, insurable_wage, insurance_employee, insurance_employer, taxable, tax, statutory_employee / employer, total_deductions,
  net, paid_amount, payment_status, notes.
- `payroll_line_items` — line_id, pay_item_id + code / name_ar / name_en / kind / flags (snapshot), quantity, rate, amount,
  source (compensation, adjustment, leave, advance, calc), source_id.
- `payroll_payments` — line_id, staff_id, period, expense_id, amount, payment_method_id, paid_on, creatable, cancelled_at / by /
  reason (an undo cancels its Cash Out).
- `payroll_events` — run_id, type (created, review, locked, reopened, paid, payment_undone), reason, data, creatable, created_at.

## 4. Permissions (roles catalog; none in Front desk)

| Group | Keys |
|---|---|
| HR files | `hr.view` (staff list + file without salary / bank / national ID), `hr.sensitive` (national ID, bank / wallet, emergency contact, photo), `hr.manage` (add / edit, status, link login; requires view) |
| Documents | `documents.view` (list + download, logged), `documents.manage` (upload / replace / delete) |
| Employment contracts | `hr.contracts` (view / draft / issue / upload signed); templates = owner |
| Leaves | `leaves.view`, `leaves.manage` (enter for anyone, balances), `leaves.approve`; later: `leaves.request_own` |
| Insurance | `insurance.view` (registration + monthly report), `insurance.manage` |
| Adjustments | `adjustments.view`, `adjustments.manage` (bonuses / penalties / overtime …) |
| Advances | `advances.view`, `advances.manage` (enter / edit installments); giving the money = `payroll.pay` |
| Payroll | `payroll.view` (salaries, compensation, runs, payslips), `payroll.run` (create / recalculate / send to review), `payroll.approve` (lock; NO preset = the owner only for now), `payroll.pay` (pay salaries + give advances = account-level Cash Out; NO preset; does NOT need `shift.has`), `payroll.export` (Excel / PDF, logged); later: `payroll.own_payslips` |
| System Access | `users.manage` (turn a person's login on / off, login email / password / role, from the staff form; no preset; never on one's own file) |
| Settings | owner only (rule sets, pay items, leave / document / violation types, holidays, departments, contract templates) — a key later if asked |

Presets proposed: **HR officer** (hr.*, documents.*, hr.contracts, leaves.*, adjustments.manage, insurance.view),
**Payroll (accounting office)** (payroll.view, payroll.export, insurance.view, advances.view, adjustments.view; `payroll.run` if
they prepare it). `payroll.run` and `payroll.approve` in different hands by default.

Logging (`access_logs`, user 2026-11-04): an employee opening a staff page / tab (`view`, subject Staff); EVERY document /
signed contract / payslip download (`download`) and every export (`export`, filters + rows) — the owner's too; lock / reopen /
pay / undo pay (`action`, the owner's too). The owner's views are not logged.

## 5. Settings and effective dates

Per account, one `payroll_rule_sets` row per change date. `rules` JSON:
- Time: `month_divisor` (30 or calendar days or working days), `hours_per_day`, `shift_24h_counts_as` (days), proration of
  joiners / leavers (calendar days ÷ divisor).
- Overtime: multipliers day / night / rest day / public holiday, night hours window.
- Social insurance: employee %, employer %, insurable wage min / max, which pay items are insurable (flags on `pay_items`), rounding.
- Salary tax: method (annualize × 12 → tax → ÷ 12), personal exemption, **bands**: [{annual_income_up_to, brackets:
  [{up_to, rate}]}] (Egypt's table drops the lower brackets for high incomes → bands, so a new table needs no code), rounding.
- Other statutory items: a list [{code, name, base gross | insurable | taxable, employee_rate, employer_rate, cap}] (e.g. Martyrs'
  Fund, universal health insurance where it applies — the office says which).
- Penalties: max deduction days per month; minimum net (advances shrink first); net rounding.

How dates work:
- A run for month M uses the rule set with the latest `effective_from` ≤ the first day of M (or the last — the office chooses one
  rule, §9) and COPIES its `rules` into the run. Compensation = the row in force; a change inside the month is prorated by days.
- New year: "Duplicate as of 01-01-2027" → edit → the office confirms → used from January. December and earlier never move.
- A law published late but effective earlier: a new set dated back; locked months stay as they are; the difference is entered
  as a "correction" adjustment in the next open month (later: a helper that computes it).
- Locked = snapshot: line + items + rules + staff names copied; payslips / reports read ONLY the snapshot (like issued contracts).
  Reopen (user 2026-11-04): the owner only, the LAST locked run, no salary payment from it yet, reason required — logged.

## 6. Payroll flow

ONE Payroll page per month (§B): the current month pre-selected, one table, one row per person, totals at the bottom, the
button of the next step only.

1. **Draft** (`payroll.run`): "Calculate · October 2026" → a line per staff active in the month (status active / on_leave, or
   ended inside it). Inputs pulled: compensation, approved leaves, adjustments of the month, advance installments. Editable per
   line in the review grid: absence days, overtime hours (= the manual attendance), skip an installment, notes. "Recalculate" any
   time (sync; ~10 people = instant, so no "calculating" state).
2. **Calculation** (`PayrollCalculator`, pure): basic + allowances (prorated) + earning adjustments = gross → minus before-tax
   deductions (absence, unpaid leave) → insurance (insurable wage clamped × employee %) → taxable = taxable items − insurance −
   exemption → tax → statutory → after-tax deductions (penalties capped, advance installment, others) → net (not below the minimum
   net: the installment shrinks, the rest stays on the advance). Unit tests with the office's own examples before H5 ships.
3. **Review** → inputs frozen; send back to draft or **Lock** (`payroll.approve`): snapshot written, adjustments / leaves / advance
   moves of the month get the line id, payslips available.
4. **Pay** (`payroll.pay`, locked runs only): [Pay] on each row (amount = the remaining, editable for a partial payment; "Paid
   from" = a payment method: owner / company cash, bank transfer, wallet; date today; reference optional) and "Pay all" (every
   row with a remaining, one popup: method + date). Each payment = one **account-level** Cash Out (category "Salaries", item
   "Salary 10-2026 · <name>", `is_company` = 1, `shift_closing_id` 0 forever) + a `payroll_payments` row; the row shows paid /
   remaining. Undo a payment (reason, the owner or `payroll.pay`) cancels its Cash Out (soft delete + reason) — logged. A run
   with a payment can't be reopened.
5. **Outputs:** payslip PDF (mpdf, Cairo, AR / EN, from the snapshot, "received by" signature line for cash), run summary PDF,
   Excel export (bank / wallet list, full grid), **insurance monthly report** (staff, insurance no., insurable wage, employee /
   employer shares, totals — Excel + PDF in the office's layout), tax report (taxable, tax per person + total). All exports logged.

## 6b. Account-level Cash Out vs shifts / expenses / Daily Summary (checked in the code 2026-11-04)

Today a Cash Out belongs to its recorder's OPEN shift by one rule: `shift_closing_id` = 0 + `creatable` = the login
(`ShiftClosingService::openExpenses()`). A company Cash Out with `shift_closing_id` 0 would be taken as OPEN by:

| Where | Today | Needed (H4, with the advances) |
|---|---|---|
| `ShiftClosingService::openExpenses()` / `openSince()` / `openPeriods()` (My Shift, reminders, Daily Summary open shifts, Long Open Shifts) | counts it in the payer's open shift — it would never close, and lowers their net | one scope `Expense::scopeInShifts()` = `is_company = 0`, used by all of them |
| `ShiftClosingService::close()` | would sweep it into the payer's closing | the same scope |
| `ExpenseActions` My Shift list (`?shift=open`), `shifts:recalculate` | lists / recalculates it | the same scope |
| `ExpenseService::editError()` | the recorder may edit it while "open" | generated rows: refused for everyone (undo from payroll / the advance) |
| `DailySummaryService::summary()` (Day Totals, By Collector by `spent_at`) | in the payer's collector row + the day's net | a separate "Company" line, out of every "Net to hand over" |
| Payments page (`CashListService`, `CashScope`) | shown like any Cash Out | shown, with a "Company" badge + a Company filter; the By column = the payer |
| Cash Out form categories | any active category | "Salaries" / "Advances" hidden + refused (`expense_categories.generated_only`) from go-live |

No other conflict: `shift.has` is not needed for `payroll.pay`; payment methods are reused as they are; the 244 old "Salaries"
rows stay shift rows (history). Migrations (H4): `expenses.is_company` (0), `expense_categories.generated_only` (0) + the two
categories ("Salaries" = the existing #28, "Advances" new).

## 7. Attendance (recommendation: not now)

- Now: manual monthly figures in the payroll review (absence days, overtime hours) + leaves + adjustments. ~10 people, the owner
  knows who was absent; this gives the payroll everything it needs.
- Later (H6, only if the manual way proves wrong / slow after 2–3 months): `staff_rosters` (planned shifts per person / day) +
  `attendance_records` (staff, check_in, check_out as DATETIMES, work_date = the check-in date, source manual | device | QR,
  approved_by). 24h place: a shift crossing midnight is ONE record; a 24h shift = 24 worked hours, counted as
  `shift_24h_counts_as` days; absence = a planned shift with no record; overtime = worked − planned beyond a threshold, night /
  rest-day / holiday hours split by the rule set. Results feed the same review fields (no new payroll logic).
- Not used for attendance: system logins / My Shift (cash shift, not presence; cleaners / security have no login).

## 8. Tasks & recruitment

- **Tasks: out** (§1). Not HR / payroll; reconsider as an operations checklist feature.
- **Recruitment (H7, outline only):** `job_openings` (position, department, description, status), `applicants` (name, phone,
  CV file private, source, opening), `interviews` (applicant, date, interviewer, notes), `evaluations` (criteria scores, result),
  "Convert to staff" (creates the `staff` file + documents). Keys `recruitment.view`, `recruitment.manage`.

## 9. What the accounting office must confirm before payroll goes live

1. Social insurance for 2026 / 2027: employee % and employer %, insurable wage min / max, which items are insurable, insured on
   the actual or a declared wage, rounding, registration / exit deadlines (Form 1 / Form 6).
2. Salary tax: personal exemption, the brackets and the high-income bands, annualization method, which items are taxable /
   exempt, rounding.
3. Other statutory deductions: Martyrs' Fund (rate, base), universal health insurance (applies to us or not yet), any other.
4. Day / hour rate: month divisor (30 / calendar / 26), hours per day, how a 24h shift counts, proration of joiners / leavers,
   rule-set date rule (first or last day of the month).
5. Overtime multipliers (day, night, rest day, public holiday) and the night hours.
6. Leaves under the labour law in force (the new Labour Law 14 / 2025 — the office confirms): annual days by service / age,
   casual, sick leave pay %, carry over, unpaid leave deduction formula.
7. Penalties: maximum per month, procedure (investigation, written notice), deducted before or after tax.
8. Advances: expense or receivable, maximum installment (% of salary), what happens when someone leaves with a balance.
9. Net rounding, salary payment day, final settlement rules (unused leave payout, notice period) for H5's off-cycle runs.
10. The report layouts they need (insurance monthly, tax, bank list) — sample files.

## 10. Phases

| Phase | Content | Migrations | Done when |
|---|---|---|---|
| **H1** Staff files & documents | HrModule; departments, HR fields on `employees` (§A 10), employee_events, document_types (+ 9 defaults), employee_documents + files; Staff list + staff page (Profile, Documents tabs); System Access in the staff form; private files; expiry notice / filter / badge; keys hr.* / documents.* / users.manage; access log `view` / `download`; sidebar group **HR** › Staff + the 4 setup lists; every existing login is already a file (same row, backfilled); the owner adds the people without a login (Am Emad, Am Mohamed, himself) | `2026_11_04_100031` (run) + `100033`, `100034`, `100035` | the user lists, adds, gives access, uploads, sees the log |
| **H1b** Employment contracts | staff_contract_templates, staff_contracts; reuse sanitize / editors / PDF; `hr.contracts` | 1 | a contract issued + signed scan |
| **H2** Salary & insurance setup | PayrollModule; pay_items, staff_compensations, staff_insurance, payroll_rule_sets + calculators + unit tests; per-person **salary preview** (gross → insurance → tax → net) for the office to check against their own figures | 1–2 | the office confirms the previews (§9 1–4) |
| **H3** Leaves | leave_types, public_holidays, leave_balances, leave_requests; approval; own requests for linked logins (`leaves.request_own`, employee sidebar "My Leaves") | 1 | a year's balances + requests |
| **H4** Advances & adjustments | violation_types, payroll_adjustments, staff_advances (+ moves, opening balances); account-level Cash Out (`expenses.is_company`, §6b) "Advances"; Cash In "Advance repayment" | 2 | the opening balances entered |
| **H5** Payroll | runs, lines, items, payments, events; the one Payroll page, lock, reopen, pay → account-level Cash Out "Salaries" (generated only from go-live); payslips, insurance / tax exports | 1 | **parallel run** Nov + Dec 2026 next to today's manual Cash Out (same totals or explained), live from January 2027 with the 2027 rule set |
| H6 Attendance | only if needed (§7) | 1 | — |
| H7 Recruitment | §8 | 1 | — |

## 11. Risks / notes

- Wrong legal numbers = wrong salaries → nothing locks without a confirmed rule set; calculator unit tests from the office's
  examples; the parallel run.
- Sensitive data in a live system: private disk only, encrypted bank / wallet columns, `hr.sensitive` separate from `hr.view`,
  every view / download / export logged, no HR data in the front desk role.
- Salaries already in Cash Out (244 rows, 794,578.00) stay as they are (history); no import into payroll runs.
- The owner has a staff file (user 2026-11-04) without a login (an employees row with no users row); his salary goes through
  payroll like everyone's.

## 12. Questions for the user

Answered 2026-11-04 (§A). Open: none for H1.

