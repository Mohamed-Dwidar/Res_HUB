-- 2026-11-06 — Reports (docs/reports-plan.md §0): the role "Accounting Office" gets 4 report keys
--   reports.outstanding, reports.member_statement, reports.income, reports.expenses (user 2026-11-06)
-- Run AFTER the deploy that brings ReportModule (the keys mean nothing before). Backup first.
-- Idempotent: each key is added only when the role does not have it yet. Only roles named exactly "Accounting Office",
-- not deleted. Roles with another name are not touched (reported by the first SELECT: 0 rows = nothing to do, check the name).
-- Follow-up: none (permissions are read on each request).

START TRANSACTION;

-- before: the role(s) and whether they already have each key
SELECT id, account_id, name,
       JSON_CONTAINS(permissions, '"reports.outstanding"') AS outstanding,
       JSON_CONTAINS(permissions, '"reports.member_statement"') AS member_statement,
       JSON_CONTAINS(permissions, '"reports.income"') AS income,
       JSON_CONTAINS(permissions, '"reports.expenses"') AS expenses
FROM roles
WHERE name = 'Accounting Office' AND deleted_at IS NULL;

UPDATE roles SET permissions = JSON_ARRAY_APPEND(permissions, '$', 'reports.outstanding')
WHERE name = 'Accounting Office' AND deleted_at IS NULL AND NOT JSON_CONTAINS(permissions, '"reports.outstanding"');

UPDATE roles SET permissions = JSON_ARRAY_APPEND(permissions, '$', 'reports.member_statement')
WHERE name = 'Accounting Office' AND deleted_at IS NULL AND NOT JSON_CONTAINS(permissions, '"reports.member_statement"');

UPDATE roles SET permissions = JSON_ARRAY_APPEND(permissions, '$', 'reports.income')
WHERE name = 'Accounting Office' AND deleted_at IS NULL AND NOT JSON_CONTAINS(permissions, '"reports.income"');

UPDATE roles SET permissions = JSON_ARRAY_APPEND(permissions, '$', 'reports.expenses')
WHERE name = 'Accounting Office' AND deleted_at IS NULL AND NOT JSON_CONTAINS(permissions, '"reports.expenses"');

-- after: every column = 1
SELECT id, account_id, name,
       JSON_CONTAINS(permissions, '"reports.outstanding"') AS outstanding,
       JSON_CONTAINS(permissions, '"reports.member_statement"') AS member_statement,
       JSON_CONTAINS(permissions, '"reports.income"') AS income,
       JSON_CONTAINS(permissions, '"reports.expenses"') AS expenses
FROM roles
WHERE name = 'Accounting Office' AND deleted_at IS NULL;

COMMIT;
