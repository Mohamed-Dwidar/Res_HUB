# Roles & Permissions — Plan

> Approved by the user 2026-11-03 (replaces the separate Accountant user type of the same day: AccountantModule removed, never
> deployed). Order: **R0 → R1 → R2 → Tax Phase C → P2 (ETA to-do) → P3 (reports)**.
> Read before any role / permission / accountant / export / ETA / report work. Related: `docs/payments-plan.md` §3 (taxes),
> §4 (reports).

## 0. Goal

The owner controls what each employee can do, without code changes: roles per account (Settings › Roles), a permissions
checklist, one role per employee. An accountant (external or internal), a supervisor, a receptionist … = an employee with a role.

## 1. Decisions (user 2026-11-03)

1. **Custom roles + Laravel's Gate** (no package: spatie/laravel-permission is not installed; its tables / per-account teams /
   users-attached roles / boolean-only permissions do not fit). Permission catalog in code (config, grouped by page); `roles`
   table (account, name, ticked permissions json, idle minutes, default flag); `employees.role_id`.
2. **`Gate::before`:** the owner (Account) = every ability; an employee = what his role has (loaded once per request).
   Routes `->middleware('can:<key>')`, views `@can`, services `$user->can()`.
3. **"Front desk"** = EXACTLY today's employee permissions, created for every account and given to every existing employee by
   the migration. Safety net: `role_id` 0 / missing role = Front desk. A default role can't be deleted; a role used by employees
   can't be deleted.
4. **Owner-only, never in a role:** settings, employees, roles, the Access Log, the owner's own My Shift.
5. **Presets** to create a role from: Front desk, Accountant (external), Accountant (internal); the owner edits it afterwards.
6. **Kept from the Accountant P0 (moved into EmployeeModule):** `access_logs` for every employee (login, failed login, logout,
   idle timeout; R1+: exports + sensitive actions), the idle timeout (per role, `roles.idle_hours`, empty = no limit), the
   automated route test (R0: every employee route declares its permission; a role without it gets 403).
7. `Userable::area()` stays (views read the area from the logged in user, not the route).

## 2. Today (before R0) — where employee access is decided

| Where | What |
|---|---|
| Routes | `account.*` (`user.active:Account`, 250) vs `employee.*` (`user.active:Employee`, 83); employees simply have no route for payment / expense delete, extras edit / delete, member delete, Daily Summary, Payment Exceptions, Withholding, settings |
| Concerns traits | `area() === 'employee'`: Payment / Expense (own rows, one day), MyShift, Reservation, Contract |
| Services | 24 `isOwner()` / `instanceof Account|Employee` checks in Payment, Expense, ShiftClosing, Reservation, Contract, Messaging services + `ExtraRequest` |
| Middleware | `ShiftReminder` (every employee), `WithholdingEnabled` |
| Views | 72 views guess the area by `routeIs('employee.*')`; 37 `$area === 'account'` checks in 14 views |
| Sidebar | `layoutmodule::employee.sidebar`, a fixed list |
| Member phones / emails | 40 places in 25 views + the phone search |

## 3. Permissions (catalog; ✓ = Front desk, Ext = Accountant (external) preset)

| Group | Permission | Front desk | Ext |
|---|---|---|---|
| Schedule | `schedule.view` | ✓ | |
| Reservations | `reservations.view`, `.create`, `.edit`, `.delete`, `.status` (+ restore), `.renew`, `.mark_left` | ✓ | |
| | `.cancel_paid` (cancel with payments, reason), `.owner_fields` (billing day, Renewal of #, back-dated invoice) | | |
| Packages | `packages.view`, `.create`, `.edit`, `.delete`, `.statement`; `extras.create` | ✓ | |
| | `extras.manage` (edit / delete / adjustments) | | |
| Cash In | `payments.view`, `.create`, `.receipt`, `.refund`, `.edit_own_open` | ✓ | view, receipt |
| | `payments.view_all` ("See all cash in — all employees, any date"; ticks `payments.view`), `.export` | | ✓ |
| | `payments.date_range` (own cash in, any dates; for normal employees) | | |
| | `payments.edit_any`, `.delete` (reason in a closed shift), `.choose_date` | | |
| Cash Out | `expenses.view`, `.create`, `.edit_own_open` | ✓ | view |
| | `expenses.view_all` ("See all cash out — all employees, any date"; ticks `expenses.view`), `.export` | | ✓ |
| | `expenses.date_range` (own cash out, any dates) | | |
| | `expenses.edit_any`, `.delete` | | |
| Shift | `shift.has` (collects money, My Shift, reminders, logout prompt; Cash In / Out create need it) | ✓ | |
| | `daily_summary.view`, `shifts.close_on_behalf`, `shifts.reopen` | | |
| Members | `members.view`, `.create`, `.edit`, `.contacts` (phones / emails, phone search, WhatsApp) | ✓ | |
| | `members.delete` | | |
| WhatsApp | `messages.send` (needs `members.contacts`) | ✓ | |
| Contracts | `contracts.view`, `.draft`, `.issue`, `.upload_signed`, `.delete_own_draft`, `.pdf` | ✓ | |
| | `contracts.replace_scan`, `.delete_any` | | |
| Withholding | `withholding.view`, `.export` | | ✓ |
| | `withholding.manage` | | |
| Controls | `payment_exceptions.view` | | |
| Taxes (P2) | `eta.view`, `eta.mark_entered` | | ✓ |
| Reports (P3) | `reports.tax`, `reports.debts` (+ export) | | ✓ |
| Session | `idle_hours` (role setting, 1–24, empty = no limit) | empty | 1 |

**Accountant (internal)** preset = Ext + `daily_summary.view`, `payment_exceptions.view`, `withholding.manage`,
`payments.edit_any` / `.delete`, `expenses.edit_any` / `.delete`, `members.view`, `members.contacts`.

## 4. How pages follow the permissions

- Routes: every `employee.*` route gets `can:<key>`; owner-only features get `employee.*` routes (the same Concerns traits) only
  in the phase that opens them.
- Sidebar: items with a `can` key, `partials/menu` skips the ones the user lacks (an empty group hidden); the owner unchanged.
- Home: `Employee::homeRoute()` = Schedule if allowed, else the first allowed page in sidebar order.
- Buttons / menus: `row-menu` links / actions with a `can` key; header buttons `@can`; the 37 area checks → `@can`.
- Links to other pages (reservation, package, member, payment): one partial = a link with the permission, plain text without.
- Phones / emails: one member-contact partial instead of the 40 places; no `members.contacts` = no phone search, no WhatsApp,
  no tenant mobile in contract PDFs.
- Services: the 24 owner checks → `can()` (owner passes everything = unchanged), each in the phase that opens it.

## 5. DB

- **Moved from P0 (written, NOT run on `dwidar_res_hub`):** `EmployeeModule 2026_11_03_100028_create_access_logs_table`
  (`account_id`, `user_id`, `email` = the login's email at that moment, `event` (login | login_failed | logout | timeout | export |
  action), `page`, `format`, `filters` json, `rows`, `subject_type` / `subject_id` (a sensitive action's record), `ip`,
  `user_agent`, `created_at`; indexes (`account_id`, `created_at`), (`user_id`, `created_at`)).
- **R0 (to write, for review):** `roles` (`account_id`, `name`, `permissions` json, `idle_hours` tinyint null (1–24), `is_default`,
  `created_by`, timestamps, soft deletes; index `account_id`); `employees.role_id` (default 0, index) + per account an idempotent
  "Front desk" row (the permission list written INTO the migration, not read from config) and `role_id` set for every employee
  with 0.

## 6. Phases

- **R0 status (2026-11-03): BUILT, uncommitted, migrations `2026_11_03_100028 / 100029 / 100030` NOT run on `dwidar_res_hub`
  (tested on a disposable copy).** As built: catalog = the 33 Front desk keys only (later phases add theirs — R1 must add
  `members.contacts` to every role that has `members.view`); `permission_requires` (saving adds them, e.g. Cash In create →
  `shift.has`); combined Gate abilities `reservations.form`, `members.pick`, `payments.form` for shared routes; the Renew check
  inside `ReservationActions::create / store`; presets Front desk + Nothing ticked (+ Duplicate a role); the shared route
  closures got `->can()` (account routes too: the owner passes everything); row menus drop links the role can't open
  (`RoleService::allowsUrl()`) and actions with a `can` key; @can on the list "Add" buttons, the Payments header / tabs (no
  `expenses.view` = Cash In tab only, `cash-data` 403), the reservation view header (status = plain badge), "+ Cash In", the
  member quick add, the WhatsApp icon; still shown and 403 on click (R1, §4 "plain links"): payment / member links inside
  popups, buttons inside the package / payment / member / contract views.
- **R0 — zero behaviour change:** roles + `role_id` + Front desk; Settings › Roles (list, add from a preset, checklist, idle
  minutes; role in the employee form); the Gate; `can:` on all 83 employee routes; sidebar + home by permission; the access log
  + idle timeout per role; the route test (every employee route declares a permission, an empty role → 403 everywhere except
  profile / password, Front desk reaches exactly today's routes); check: every employee page as Front desk = before.
- **R1 — Payments:** `payments.view_all` / `expenses.view_all` (replace "own rows, one day"), Excel / PDF export (+ the owner)
  logged, `shift.has`, `members.contacts` everywhere, plain links.
- **R1 status (2026-11-03): "see all" + "any dates" BUILT (user decisions), uncommitted; export, contacts, plain links NOT yet.**
  - Four keys, never in Front desk: `payments.view_all` ("See all cash in — all employees, any date") and `expenses.view_all`
    ("See all cash out — all employees, any date") stay SEPARATE (each ticks only its own view key); `payments.date_range` /
    `expenses.date_range` = own rows over any dates (for normal employees; view_all already includes any range).
  - ONE rule: `PaymentModule Services/CashScope` (`paymentsCollector()` / `expensesCollector()`: null = everyone;
    `paymentsRange()` / `expensesRange()`; `rangeFor($tab)`: Cash In = payments, All / Cash Out = payments AND expenses — they mix
    refunds (payments) and cash out; `seesOthers($tab)`). Used by `PaymentActions` (Cash In list + summary, All / Cash Out via
    `CashListService::query()` with a collector PER part, the payment popup) and `ExpenseActions` (list, summary, popup). Without
    any-dates the date field is one day (server-forced) with no Today / This month / All tabs; the "By" column and the "Created
    By" filter only when the tab shows other logins' rows. Seeing never allows editing (`canEdit()` unchanged; refunds still
    follow `payments.refund`). My Shift stays the login's own open shift.
  - **Export rule (when built in R1):** an export takes its rows from the SAME list query with the same `CashScope` collectors
    and date rule — it can never contain a row the list can't show.
  - Tests: `EmployeeRoutesTest::test_cash_view_all_and_date_range_keys` (separate keys, not in Front desk, each ticks its view)
    + `test_cash_scope_by_role` (Front desk / view_all / date_range / both / owner). Real data (disposable copy, October):
    Front desk one day own rows; `payments.view_all` 400 cash in = SQL, Cash Out still one day own; date_range own 97 / 4;
    both 400 / 62 = SQL; a colleague's other income / cash out popup 404 → 200 only with the matching view_all.
- **R2 — accountant pages:** Withholding (view / export / manage), Daily Summary and Payment Exceptions for employees, the
  owner-only payment actions as permissions (edit any / delete), the Accountant presets.
- **Tax Phase C** → **P2 ETA to-do** (`eta.*`) → **P3 reports** (`reports.*`), each with its permission keys.

## 7. Risks

1. Every employee page is touched: a missed check hides or shows something. Front desk = all of today + a before / after walk of
   every employee page and button as Front desk + the route test, every phase.
2. The 24 owner checks in services must keep the owner's behaviour; converted only in the phase that opens them.
3. A new permission is OFF for existing roles: features extending Front desk pages add their key to Front desk in their migration.
4. Phones also in PDFs (contract tenant mobile, statements) → follow `members.contacts`.
5. One role read per request (cached) — no measurable cost.

## 8. Backlog

- `access_logs` retention: auto-delete lines older than a configurable period (default 12 months) — a scheduled command.
