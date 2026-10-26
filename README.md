# Res HUB (Pivot Reservations)

The reservations and payments system for a coworking space: spaces, units and plans; hourly, full-day and monthly / yearly
subscription bookings; member packages; cash in / cash out with shift closing; and the daily Schedule page.
It replaces the old Pivot system (`dwidar_pivot_api`), whose data is imported by the `to_run` script.

## Main features

- **Schedule** — the business day at a glance (the day runs to 02:00): an hourly timeline, full-day bookings, subscriptions
  starting / renewing / not renewed, cancelled bookings and the open shift's totals.
- **Reservations** — hourly, full-day and period (subscription) bookings, with:
  - a live availability check against each unit's capacity;
  - repeats;
  - Renew, with calendar-month renewals on the subscription's billing day;
  - Mark left;
  - Today / Upcoming / All tabs and list filters, including a Renewal filter.
- **Packages** — a member's bundle of reservations with a discount, extras and a PDF statement.
- **Members** — server-side search, quick add from any form, duplicates found by mobile number and merged.
- **Payments** — Cash In (payments, withholding), Cash Out (expenses, refunds), receipts (PDF), withholding certificates.
- **Shift closing** — every login closes its own shift. Changes after closing are made by the account owner with a reason,
  and are logged. The owner also has a Daily Summary page.
- **Settings** — spaces and units (drag-and-drop order), plans, subscription types, reservation statuses, payment methods,
  income / expense categories, companies, jobs, employee positions and shifts.

## Users and areas

| Area | Login | Who |
|---|---|---|
| Admin | `/admin` (guard `admin`) | approves accounts |
| Account | `/login` (guard `web`) | the business owner: everything, incl. edits / deletes and settings |
| Employee | `/login` (guard `web`) | the account's staff: daily work (Schedule, reservations, payments, packages, members, My Shift) |

`users.userable` is a morph to `Account` or `Employee`. Data is scoped by `Auth::user()->userable->ownerAccountId()`.

## Stack

- Laravel 12, PHP ^8.2, MySQL (timezone Africa/Cairo)
- Modular: [`nwidart/laravel-modules`](https://github.com/nWidart/laravel-modules), 14 modules in `Modules/`
- Blade + the Tailwise "dagger" template (compiled `public/assets/css/app.css`), vanilla JS in `public/assets/js/custom.js`
- DataTables (server side, `yajra/laravel-datatables-oracle`), with jQuery used only for DataTables (`noConflict`)
- `prettus/l5-repository`, `intervention/image`, `maatwebsite/excel`, `niklasravnsborg/laravel-pdf` (mpdf)
- **No CDN:** every library and font is a local file under `public/assets/vendor/` and `public/assets/fonts/`.
  This covers jQuery, DataTables, SweetAlert2, flatpickr, Tom Select and Lucide.

## Getting started

```bash
composer install
cp .env.example .env          # set DB_DATABASE / DB_USERNAME / DB_PASSWORD
php artisan key:generate
php artisan migrate           # see "Database" below
php artisan serve             # http://127.0.0.1:8000
```

- There's no front-end build. Edit `public/assets/css/custom.css` and `public/assets/js/custom.js` directly; they're loaded
  with `?v=filemtime`.
- The Vite / Tailwind entries in `package.json` aren't used by the pages.
- After changing views or config, run `php artisan optimize:clear`.

### Useful commands

| Command | What it does |
|---|---|
| `php artisan payments:refresh {--account=}` | Recalculates paid / remaining of every reservation and package |
| `php artisan shifts:recalculate {--imported} {--account=}` | Recalculates shift totals (payments − refunds − expenses) |
| `php artisan reservations:link-renewals {--dry-run} {--account=}` | Links imported subscription periods to the period they renew |
| `php artisan members:merge-duplicates {--dry-run} {--account=}` | Merges members that share one mobile number |
| `php artisan test` | Tests (only the Laravel examples so far) |

## Project structure

```
Modules/{Name}Module/
  app/Http/Controllers/{Account|Admin|Employee|Guest|Auth}/   {Module}{UserType}ModuleController
  app/Http/Controllers/Concerns/                              shared Account + Employee actions (traits)
  app/Http/Requests, Models, Repositories, Services
  database/Migrations, resources/views, routes/web.php
app/Helpers/                 LocalizedHelper (name_ar / name_en), SafeMorphHelper, UploaderHelper
public/assets/css/custom.css all custom CSS (pdf.css for PDF views only)
public/assets/js/custom.js   popups, ajax forms, dependent selects, the Schedule page, list filters
public/assets/js/datatables.js  DataTables setup + the row API
Template_Source/             the purchased template's HTML (reference for markup / classes)
to_run                       the old → new data transfer script (re-runnable for go-live)
docs/                        progress + feature specs
```

| Module | Covers |
|---|---|
| UserModule, AccountModule, EmployeeModule, AdminModule | logins, user types, account settings, employees |
| LayoutModule | layouts, sidebars, shared partials (fields, filters, popups) |
| SpaceModule, UnitModule, PlanModule | spaces + subscription types, units, plans / prices |
| ReservationModule | reservations, statuses, the Schedule page, renewals |
| PackageModule | packages, statement PDF |
| PaymentModule | cash in / out, extras, refunds, withholding, shift closing, Daily Summary |
| MemberModule, CompanyModule, JobModule | members and their lookups |

## Conventions

- **Thin controllers:** validation goes in Form Requests and business logic in Services. Services called outside a form
  (imports, commands) check their own rules and throw `ValidationException`.
- **Features shared by the account and employees:** a `Concerns/{X}Actions` trait plus two thin controllers. Routes are
  named `{area}.{resource}.{action}` (e.g. `account.reservations.show`), always with named routes.
- **Lists:** server-side DataTables. View / add / edit open in a popup (`data-modal`), forms submit by ajax (`data-ajax`),
  and rows update in place.
- **No inline CSS:** no `style=""` and no `<style>`. Use named classes in `custom.css`, and template markup from
  `Template_Source/`. Template files are never edited; overrides go in `custom.js`.
- **Display formats:** dates `d-m-Y`, times `h:i A`. Input values stay `Y-m-d` / `H:i`.
- **Database:** snake_case columns, `account_id` on every account-owned table, `0` = none for non-nullable foreign ids,
  `name_ar` / `name_en` for translated text (UI is English for now).

## Database and the data transfer

The current database is a **trial import** of the old system. On go-live day the whole import is run again from `to_run`,
following its GO-LIVE CHECKLIST (backup, pre-check, reload, recalculations, renewal links, member merge, checks).

- **Every manual change goes into `to_run`.** Any change made to the database by hand (`ALTER`, `UPDATE`, backfill) must
  also be added there: idempotent, with the expected row counts.
- **`dwidar_pivot_api` is read-only.** It's the old live system's database and is never written to.
- **Schema changes:** in practice the module's `create_*_table` migration is edited and the column is changed by hand in
  the database, plus the matching guarded step in `to_run`.

## Documentation

- [`docs/progress.md`](docs/progress.md) — current status, decisions per module, known issues, session log
- [`docs/payments-plan.md`](docs/payments-plan.md) — payments, shift closing, expenses, invoices / ETA plan (read before
  any payments work)
- [`docs/reservations-list-plan.md`](docs/reservations-list-plan.md) — the reservations and packages lists
- [`CLAUDE.md`](CLAUDE.md) — working rules and the cross-module dependencies map
