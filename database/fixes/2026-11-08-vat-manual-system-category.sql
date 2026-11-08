-- Income categories rule (user 2026-11-08): a system category (generated_key) is never income, every other one always is
-- (no "Counts as income" checkbox any more). Makes #6 "VAT (manual)" (account 1, not income) the SYSTEM category 'vat_manual':
-- not offered in the Add Cash In / Add Extra forms, its Cash In locked (no edit / delete / refund; user's choice: VAT is no
-- longer recorded by hand). The old payments are not changed; is_revenue stays 0. Every other row already follows the rule
-- (checked 2026-11-08 on the local copy: 8 normal categories all income).
-- Needs database/fixes/2026-10-28-vat-manual-income-category.sql first (#6 renamed "VAT (manual)").
-- Safe twice: only #6 while it is account 1's "VAT (manual)" without a key (or already keyed); refused if the key is taken.
-- Run after a backup:  mysqldump <db> income_categories > income_categories-before-vat-manual-system.sql
-- Follow-up: none (no amounts change).

START TRANSACTION;

-- before: expected 1 row: 6 | 1 | VAT (manual) | is_revenue 0 | generated_key NULL (or 'vat_manual' on a second run)
SELECT id, account_id, name_en, is_revenue, generated_key, is_active, deleted_at FROM income_categories WHERE id = 6;
-- expected 0: another category of account 1 already holds the key (then stop and check by hand)
SELECT COUNT(*) AS other_vat_keys FROM income_categories WHERE account_id = 1 AND generated_key = 'vat_manual' AND id <> 6;
-- the rule: expected 0 rows (a normal category not income, or a system one counted as income) — any row = report it, don't fix here
SELECT id, name_en, is_revenue, generated_key FROM income_categories
WHERE deleted_at IS NULL AND id <> 6 AND ((generated_key IS NULL AND is_revenue = 0) OR (generated_key IS NOT NULL AND is_revenue = 1));
-- for the record: its Cash Ins (they become locked)
SELECT COUNT(*) AS cash_ins, SUM(amount) AS total, MAX(paid_at) AS last FROM payments WHERE income_category_id = 6 AND deleted_at IS NULL;

UPDATE income_categories
SET generated_key = 'vat_manual', is_revenue = 0, is_active = 1, updated_at = NOW()
WHERE id = 6 AND account_id = 1 AND deleted_at IS NULL AND name_en = 'VAT (manual)' AND is_revenue = 0 AND generated_key IS NULL
    AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM income_categories WHERE account_id = 1 AND generated_key = 'vat_manual') AS taken);
-- expected 1 (first and second run); 0 = #6 changed since the backup or the key is taken: check it
SELECT COUNT(*) AS vat_manual_system_ready FROM income_categories WHERE id = 6 AND account_id = 1 AND generated_key = 'vat_manual' AND is_revenue = 0;

COMMIT;
