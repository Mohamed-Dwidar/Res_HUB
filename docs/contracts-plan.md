# Contracts (ContractModule) — plan

> Approved by the user 2026-10-31 (analysis + decisions below). Read before any contracts work.
> Status: Phase 1 done + committed (2026-10-31, `a0f65dc`). Phase 2 built (2026-10-31), migration `2026_10_31_100027` waiting for the
> user's review, not committed. Phases 3–4 not started.

---

## 0. The old module (Pivot_DMS `Modules/ContractModule`, read only — history)

- Admin only, no account scope, not linked to members / units / reservations / payments.
- `contracts`: one flat row — free-text `unit_number`, dates, `annual_amount`, `contract_duration` (text), 6 lessor fields copied from
  translation strings (`messages.main_info.*`), 7 tenant fields typed by hand (name, company, representative, position, national
  id, mobile, email), `unit_address`, `unit_activity`, `contract_terms` (TinyMCE HTML, prefilled from
  `storage/app/private/virtual_contract_terms.html`), `notes`, `signed_copy_path`, `status` enum never set by the code.
- `contract_renewals`: contract_id, renewal_date, start / end, renewal_amount, renewal_duration (renewal_terms / notes /
  signed_copy_path unused). One contract + renewal rows; suggested amount = last × 1.10.
- Placeholders only in the renewal annex (`virtual_contract_renewal_template.html`, 9 `[x]` keys, `str_replace` in Blade).
- PDF: mpdf A4, Cairo, `letterhead.png` watermark image, `<style>` + flex (ignored by mpdf); 3 header tables (lessor / tenant /
  unit) + signature table + the terms HTML.
- Reused here (adapted): the page-1 tables, the lessor / tenant field list, the lessor data copied into the contract (= snapshot),
  TinyMCE 4.7.13. Not its texts: the owner writes every template (user 2026-10-31).
- Not reused: free-text unit / tenant, lessor data in translations, unsanitized HTML, no account, admin-only CRUD pages, inline
  CSS, the fixed 10% increase, renewals without the reservation chain.

## 1. Decisions (user 2026-10-31)

1. **Renewal model (c): one contract per TERM**, linked to the reservation chain (`renewal_of`). A contract has its own
   `starts_on` / `ends_on` and covers the chain's periods (same member + unit) starting inside it; monthly renewals don't touch
   it. When the chain goes past `ends_on` → "Renew contract" = a new `contracts` row, `kind = renewal`, `renewal_of` = the previous
   contract, printed with the template's renewal annex text. Another unit = a new contract. Each template has its own
   `default_term_months` (12 for monthly units; NULL = the reservation's own period, e.g. a yearly plan).
2. **Permissions** — employees: create / edit drafts **and their wording (changed 2026-10-31 by the user: edited in the Create Contract / Edit Draft page itself, a full page with the editors)**, issue,
   print, mark signed, upload the signed scan, delete their own drafts. Owner only: Settings › Contracts, templates, unit
   assignment, end, cancel (with a reason), replace a signed scan. Nobody edits an issued contract.
3. **Customer fields** — members: `address`, `nationality`, `id_type` (national_id | passport) + the number in
   `national_number` (DOUBLE → VARCHAR(30), passports have letters); companies: `commercial_register`, `tax_number`, `address`,
   `representative_name`, `representative_title`, `representative_national_id`. To ISSUE: an individual needs the ID number; a
   company needs the commercial register + the representative's name. The draft form lists what is missing and saves it back to
   the member / company.
4. **Old DMS contracts: imported later** (after the code is stable and tested), not now. The `contracts` table fits them:
   `contract_no` kept from DMS, `source` (app | dms) + `source_id`, free-text tenant / unit columns for rows that can't be matched
   (mapped later). `contract_settings.number_start` = a setting the owner sets at import time; a new number =
   max(number_start, highest number + 1).
5. **Body = a full rich-text editor**: TinyMCE 4.7.13 (the old project's version, LGPL-2.1, local copy
   `public/assets/vendor/tinymce/`, no CDN). Every saved HTML is sanitized by HTMLPurifier (`ezyang/htmlpurifier`) with an
   allowlist mpdf renders well: `p, br, strong/b, em/i, u, ol, ul, li, h2–h4, table, thead, tbody, tr, td, th, span`; `style`
   only `text-align` / `direction` (+ the `dir` attribute; `colspan` / `rowspan` on cells); no scripts, no event handlers, no
   links, no images. The editor has the same allowlist (`valid_elements`), so what you see = what is saved. Templates are
   written by the owner; a draft starts from its template's body and the owner OR an employee may edit that contract's body before issuing (2026-10-31; the template itself stays owner-only);
   at issue the (edited) body is the snapshot. Placeholders `{member_name}` … inserted with chips (like the WhatsApp form),
   unknown ones refused on save. **No ready-made wording in the code** (user 2026-10-31: the "Start from" texts — the old DMS
   terms + annex — were removed): the owner writes every template in the editor. Clause numbering = written in the text (no
   automatic numbering in rich text).
6. **Cairo font**: the static Cairo TTF files (Regular, Bold, Light) copied from the old project into
   `public/assets/fonts/Cairo/` (config/pdf.php `font_path`) → every PDF (receipt, statement, contracts) uses Cairo.
7. **`subscription_types.is_contract`**: unrelated to this module (it only switches display formats + package hours). Its uses
   become `booking_mode = period`; the column is dropped in the last phase (own migration).

## 1b. The contract's own data (user 2026-11-01)

Every printed value is the CONTRACT's own (like the old DMS form): pre-filled from the reservation / member / company / Settings ›
Contracts (a renewal: from the previous contract), edited freely per contract, stored on the contract only (columns `unit_number`,
`contract_date`, `duration_text`, `term_amount` = the contract value, `currency`, `tenant_name`, `tenant_representative`,
`tenant_company`, `tenant_title`, `tenant_national_id`, `tenant_mobile`, `tenant_email`, `unit_address`, `activity`,
`deposit_amount`, `notes`). The PDF and the placeholders use them. Never written to the member / company, except "Also save to member
/ company" (unticked: their EMPTY fields only). To issue: tenant name + national ID (individual) / company name + legal
representative (company). Fixed links: reservation, member, unit, template. The draft page = three sections (بيانات العقد /
معلومات المستأجر / معلومات الوحدة, Arabic labels) + the editors (no placeholder chips there). This replaces decision 3's "the draft
saves the party fields back to the member / company".

## 2. Data model

### Phase 1 (ContractModule + Member / Company)
- `contract_settings` (one row per account, created on the first save; defaults until then): `lessor_name_ar` / `_en`,
  `commercial_register`, `tax_number`, `representative_name`, `representative_title`, `representative_national_id`,
  `property_address_ar` / `_en`, `number_start` (default 1), timestamps.
- `contract_templates`: `account_id`, `name`, `title_ar` / `title_en`, `body_ar` (longText) / `body_en`, `renewal_body_ar` /
  `renewal_body_en` (the annex), `default_term_months` (NULL = the reservation's period), `is_enabled`, creatable, timestamps,
  soft deletes.
- `contract_template_unit`: `contract_template_id`, `unit_id`, `account_id`; UNIQUE (`unit_id`) = one template per unit (the
  template form refuses a unit that has another; the unit form has one select). Deleting a template deletes its rows.
- `members`: + `address`, `nationality`, `id_type`; `national_number` → VARCHAR(30).
- `companies`: + the 6 fields above.

### Phase 2 (`contracts`)
- identity: `account_id`, `contract_no` (NULL until issued; UNIQUE (account_id, contract_no)), `kind` (contract | renewal),
  `renewal_of` (contract id, 0), `source` (app | dms), `source_id`
- links: `contract_template_id`, `template_name`, `reservation_id` (the period it was issued from), `member_id`, `company_id`,
  `party_type` (individual | company), `unit_id`, `space_id`; free text for unmatched imports: `tenant_text`, `unit_text`
- term + money: `starts_on`, `ends_on`, `amount` (period price), `term_amount`, `deposit_amount`, `activity`
- status: `status` (draft | issued | signed | ended | cancelled), `issued_at` / `issued_by`, `signed_on`, `signed_file` (private
  `contracts/{account_id}/`), `signed_by`, `ended_on`, `cancelled_at`, `cancel_reason`
- body: `body_html` (draft: NULL = the template's; owner edits) → snapshot at issue: `title`, `body_ar`, `body_en`, `values`
  (json: every placeholder + the header-table values)
- `notes`, creatable, timestamps, soft deletes; indexes (account_id, status), (account_id, ends_on), reservation_id, member_id,
  unit_id, renewal_of
- Phase 3: `contract_events` (issued / signed / scan_replaced / ended / cancelled; reason, data, creatable).
- Number: MAX + 1 per account under `lockForUpdate` at ISSUE (deleted drafts leave no gap), never below `number_start`.
- Status: draft (live, "مسودة / DRAFT" watermark, deletable) → issued (number + snapshot, never edited: cancel and issue again) →
  signed (date, scan optional) → ended ("End contract" with a date; Mark left of a covered period offers it) / cancelled (reason,
  watermark). "Renewed" = a badge (a renewal points to it).
- Snapshot: an issued contract's PDF reads only the snapshot; if the covered reservation's dates / price change after issue the
  view warns.

## 3. Placeholders (`config('contractmodule.placeholders')`, `ContractService::variables()`)

Values are HTML-escaped when inserted; an unknown `{x}` stays as typed and the template form refuses it.
- Contract: `contract_no`, `contract_date`, `start_date`, `end_date`, `term`, `activity`, `deposit`, `previous_contract_no`,
  `previous_contract_date`
- Lessor: `lessor_name`, `lessor_phone`, `lessor_commercial_register`, `lessor_tax_number`, `lessor_representative`,
  `lessor_representative_title`, `lessor_representative_national_id`, `property_address`
- Tenant: `tenant_name` (the company for a company contract, else the member), `member_name`, `member_phone`, `member_email`,
  `member_id_type`, `member_id_number`, `member_nationality`, `member_address`, `member_job`, `company_name`,
  `company_commercial_register`, `company_tax_number`, `company_address`, `company_representative`,
  `company_representative_title`, `company_representative_national_id`
- Place: `space`, `unit`, `unit_description`, `capacity`, `plan`, `lease_period`
- Money: `price`, `term_amount`, `billing_day`, `renewal_date`
- Taxes (only with "Invoice requested", from the reservation's tax snapshot): `net_before_tax`, `vat_rate`, `vat`,
  `table_tax_rate`, `table_tax`, `total_with_tax`

Note: `companies` today = the member's workplace (11 rows; 2,927 members on "Other", 22 on "Freelancer"), so a contract's
`party_type` is chosen per contract (default individual).

## 4. PDF (Phase 2; mpdf, `pdf.css`, Cairo, RTL)
A4 portrait, Arabic by default (English = a second PDF when the template has English). Header every page: logo + account name |
"عقد رقم N · date". Page 1: title, the three tables (الطرف الأول (المؤجر) / الطرف الثاني (المستأجر) / بيانات الوحدة: address,
space / unit, activity, start, end, term, price, deposit, taxes when invoiced), then the body (clauses as written), then the
signature block (two columns: name, title, signature, date; kept on one page). Footer every page: "Contract No. N · Page X of Y"
+ an initials box. Watermarks: draft "مسودة / DRAFT", cancelled "ملغي / CANCELLED". Renewal annex: same header / footer,
"ملحق تجديد للعقد رقم N بتاريخ …", the annex text, the new dates / amount, signatures.

## 5. Where it shows
- Settings › Contracts (owner): lessor data + number start + the templates table (Name | Title | Units | Term | AR / EN | Renewal
  annex | Enabled | Edit · Duplicate · Delete); template add / edit = a full page (the editor needs room). Duplicate (2026-10-31) =
  the New Template page filled from a template (`create?from=<id>`: "<name> (copy)", titles, texts, term; no units; On), nothing
  saved until Add.
- Unit form "Contract Template" select + unit view line.
- Phase 2+: the reservation view "Contract" block (Create / Covered by #N / Contract ended — Renew contract); Contracts list
  (DataTables, both areas: No. | Tenant | Space › Unit | Term | Status (+ Renewed, Scan missing) | Created by; filters search |
  date | space → unit | status | Ending | Clear; account sidebar Management, employees Daily).
- Phase 4 (optional): a "Contract ends dd-mm" badge on the Schedule renewal rows.

## 6. Phases
1. **Foundation** (now): module skeleton (MemberModule style); migrations `contract_settings`, `contract_templates` +
   `contract_template_unit`, member + company fields; Settings › Contracts; template CRUD with the editor, placeholders,
   preview, units (no "Start from": the owner writes the texts); unit form / view section; member / company fields in their forms + views; Cairo fonts; TinyMCE files;
   HTMLPurifier sanitizer.
2. **Contracts** (built 2026-10-31): `contracts` migration; created BY HAND only — Create / Renew Contract in the reservation view's
   Contract block and the reservations list row menu (only when the unit has a switched on template; a covering contract shows
   "Covered by #N" + its buttons; a period after the last issued contract's end = Renew = a renewal annex); the draft PAGE (a full page; term
   from the template, start → end follows the term, party individual | company, the party fields saved back to the member /
   company, deposit, activity, notes + this contract's wording in the editors — owner and employees; template text = kept
   linked); Issue (account + employees; the number under the settings row lock; snapshot); Upload Signed (account + employees on an
   issued one; replacing the scan = the owner); delete a draft (owner any, employee own); PDF AR / EN (DRAFT / CANCELLED
   watermark); Contracts list (Management / Daily). Not in Phase 2: End / Cancel (Phase 3), contract_events.
3. **Lifecycle**: signed scan / mark signed, end, cancel (reason), `contract_events`, renewal annex, "Covered by" in the
   reservation view, "reservation changed after issue" warning. The renewal annex uses the NEW price: the renewal reservation's
   amounts, which already carry the renewal increase (2026-11-12: `reservations.renewal_increase_type / _value`, the previous
   price before discount + the increase, discount % kept, `Reservation::nextRenewalAmounts()`); add `{renewal_increase}` /
   previous price placeholders to the annex if the owner wants them printed.
4. **Optional / cleanup**: Schedule badge, amount in Arabic words, `is_contract` → `booking_mode = period` + drop the column,
   the DMS import (`source = dms`).
