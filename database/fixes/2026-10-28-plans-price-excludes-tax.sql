-- 2026-10-28 — every plan: "Price includes tax" = off (user): with "Invoice requested", the taxes are ADDED to the plan price
-- (docs/payments-plan.md §1 / §3). No invoice = the price as before. Saved reservations keep their own tax snapshot (a plan change
-- affects only reservations saved after it, or an edit that changes the plan).
-- Needs the migration 2026_10_28_100001_add_tax_flags_to_plans_table (plans.price_includes_tax).
-- Safe twice: only the listed plans of account 1 that still include the tax. Run after a backup:
--   mysqldump <db> plans > plans-before-price-excludes-tax.sql
-- Follow-up: none (no amount changes; payments:refresh would update 0 rows).

START TRANSACTION;

-- before: expected (2026-10-28, local copy) 59 plans, 58 including the tax (plan 8 already excludes it)
SELECT COUNT(*) AS plans, SUM(price_includes_tax) AS includes_tax FROM plans WHERE account_id = 1;

UPDATE plans SET price_includes_tax = 0, updated_at = NOW()
WHERE account_id = 1 AND price_includes_tax = 1
    AND id IN (1,2,3,4,5,6,7,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49,50,51,52,53,54,55,56,57,58,59);

-- after: expected includes_tax 0; a plan added on the server after 2026-10-28 is not in the list — shown here if it still includes it
SELECT COUNT(*) AS plans, SUM(price_includes_tax) AS includes_tax FROM plans WHERE account_id = 1;
SELECT id, name FROM plans WHERE account_id = 1 AND price_includes_tax = 1;

COMMIT;
