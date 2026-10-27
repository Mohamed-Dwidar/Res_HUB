-- 2026-10-28 — "VAT (manual)": the ONE income category for VAT collected by hand until the tax module starts
-- (docs/payments-plan.md §3). Reuses the imported category #6 "VAT" (inactive, not counted as income, 21 old cash ins
-- 33,821.84 from 09-09 → 26-10-2026) instead of adding a second VAT category: renamed + active again; is_revenue stays 0
-- (VAT collected is never income, and a category not counted as income is never taxed).
-- Safe twice: the UPDATE only touches #6 while it is still the VAT category of account 1 (name "VAT" or already renamed).
-- Run after a backup:  mysqldump <db> income_categories > income_categories-before-vat-manual.sql
-- Follow-up: none (no amounts change). Staff then record VAT only in "VAT (manual)" (Cash In › Other Income).

START TRANSACTION;

-- before: expected 1 row: 6 | VAT | VAT | is_active 0 | is_revenue 0  (or already VAT (manual) | 1 | 0 on a second run)
SELECT id, account_id, name_ar, name_en, is_active, is_revenue, deleted_at FROM income_categories WHERE id = 6;
-- expected 0: any other category already named like VAT (then stop and check by hand)
SELECT COUNT(*) AS other_vat_categories FROM income_categories WHERE id <> 6 AND account_id = 1 AND deleted_at IS NULL
    AND (name_en LIKE '%VAT%' OR name_ar LIKE '%VAT%' OR name_ar LIKE '%ضريب%' OR name_ar LIKE '%مضاف%');

UPDATE income_categories
SET name_en = 'VAT (manual)', name_ar = 'ضريبة القيمة المضافة (يدوي)', is_active = 1, is_revenue = 0, updated_at = NOW()
WHERE id = 6 AND account_id = 1 AND deleted_at IS NULL AND name_en IN ('VAT', 'VAT (manual)')
    AND NOT (name_en = 'VAT (manual)' AND is_active = 1 AND is_revenue = 0);
-- expected 1 (first and second run); 0 = #6 changed since the backup (other name / deleted / another account): check it
SELECT COUNT(*) AS vat_manual_ready FROM income_categories
WHERE id = 6 AND account_id = 1 AND deleted_at IS NULL AND name_en = 'VAT (manual)' AND is_active = 1 AND is_revenue = 0;

-- after: expected 6 | VAT (manual) | is_active 1 | is_revenue 0; its payments unchanged (expected 21 / 33821.84 on 2026-10-28)
SELECT id, name_ar, name_en, is_active, is_revenue FROM income_categories WHERE id = 6;
SELECT COUNT(*) AS payments, SUM(amount) AS total FROM payments WHERE income_category_id = 6 AND deleted_at IS NULL;

COMMIT;
