-- GO-LIVE STEP (docs/hr-plan.md §6 "Go-live checklist"): run on the server RIGHT AFTER the H2 – H5 migrations, as part of that
-- deploy (user 2026-11-06): manual salary Cash Outs stop on the deploy day; November 2026 is the first month paid from Payroll.
-- Marks the existing Cash Out category #28 "Salaries" (account 1; 244 manual Cash Outs up to 31-10-2026) as the SYSTEM category
-- of payroll (expense_categories.generated_key = 'salaries'): from then on it is no longer offered in the Cash Out form and
-- salaries are paid only from HR › Payroll (Pay = a company Cash Out "Salaries", outside shifts). The old rows are not changed.
-- Safe twice: only #28 while it is account 1's "Salaries" without a key (or already keyed); refused if another category has
-- the key already (the 0 / 1 checks below).
-- Run after a backup:  mysqldump <db> expense_categories > expense_categories-before-salaries-system.sql
-- Follow-up: none (no amounts change).

START TRANSACTION;

-- before: expected 1 row: 28 | 1 | Salaries | generated_key NULL (or 'salaries' on a second run)
SELECT id, account_id, name_en, name_ar, generated_key, is_active, deleted_at FROM expense_categories WHERE id = 28;
-- expected 0: another category of account 1 already holds the key (then stop and check by hand)
SELECT COUNT(*) AS other_salaries_keys FROM expense_categories WHERE account_id = 1 AND generated_key = 'salaries' AND id <> 28;
-- for the record: its Cash Outs (244 on the local copy 2026-11-06; more on the server)
SELECT COUNT(*) AS cash_outs, SUM(amount) AS total, MAX(spent_at) AS last FROM expenses WHERE expense_category_id = 28 AND deleted_at IS NULL;

UPDATE expense_categories
SET generated_key = 'salaries', is_active = 1, updated_at = NOW()
WHERE id = 28 AND account_id = 1 AND deleted_at IS NULL AND name_en = 'Salaries' AND generated_key IS NULL
    AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM expense_categories WHERE account_id = 1 AND generated_key = 'salaries') AS taken);
-- expected 1 (first and second run); 0 = #28 changed since the backup or the key is taken: check it
SELECT COUNT(*) AS salaries_system_ready FROM expense_categories WHERE id = 28 AND account_id = 1 AND generated_key = 'salaries';

COMMIT;
