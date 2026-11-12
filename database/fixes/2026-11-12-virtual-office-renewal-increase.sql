-- 2026-11-12 — Virtual Office subscriptions: +10% renewal increase (user)
-- Plans 16 Virtual Office [Basic Package], 17 [Advance Package], 18 [PRO Package]: their default + every reservation made with them
-- (all period subscriptions) get renewal_increase_type = percent, renewal_increase_value = 10. Effect: the NEXT Renew of each costs
-- the previous price (before discount) + 10%, discount % kept; nothing owed today changes (amounts / paid / remaining untouched).
--
-- NEEDS the migrations PlanModule 2026_11_12_100059 + ReservationModule 2026_11_12_100060 first (the columns).
-- Idempotent: only rows still at 'none' change; a second run changes 0. Back up plans + reservations first.
-- Ids = the reservations of these plans on the local copy (dwidar_res_hub, 2026-11-12): 164 rows. Rows that no longer match
-- (deleted, moved to another plan, already set) are skipped and listed below; reservations made on the server after the copy
-- with these plans are listed at the end (they get the plan default once block 1 has run — only the ones made BEFORE it need a
-- hand fix: the owner's Edit › Renewal Increase).
-- Follow-up: none (no money column changes; no payments:refresh needed).

START TRANSACTION;

-- ---------- block 1: the plans' default ----------
SELECT 'plans before' AS step, id, name, renewal_increase_type, renewal_increase_value FROM plans WHERE id IN (16, 17, 18);

UPDATE plans SET renewal_increase_type = 'percent', renewal_increase_value = 10
WHERE id IN (16, 17, 18) AND renewal_increase_type = 'none';

SELECT 'plans after' AS step, id, name, renewal_increase_type, renewal_increase_value FROM plans WHERE id IN (16, 17, 18);

-- ---------- block 2: the reservations ----------
-- expected on the local copy: 164 to change
SELECT 'reservations to change' AS step, COUNT(*) AS n FROM reservations
WHERE id IN (
    1218, 2984, 3198, 3312, 3569, 3596, 3609, 4051, 4166, 4287, 4288, 4481, 4940, 4945, 5051, 5418, 5444, 5516, 
    6152, 6153, 6274, 6441, 6711, 6717, 7003, 7579, 7620, 7641, 7763, 7890, 7921, 8073, 8236, 8345, 8576, 9117, 
    9577, 10210, 10213, 10239, 10435, 10932, 11015, 11085, 11258, 11492, 12039, 12117, 13025, 13152, 13415, 
    13416, 13464, 13539, 13544, 13583, 13605, 13837, 13942, 13947, 14220, 14221, 14228, 14268, 14414, 14584, 
    14667, 14766, 14773, 14996, 15035, 15069, 15077, 15260, 15290, 15324, 15382, 15388, 15664, 15665, 15864, 
    15865, 15885, 16148, 16151, 16164, 16166, 16178, 16186, 16458, 16459, 16527, 16531, 16536, 16702, 16716, 
    16936, 17056, 17480, 17705, 17777, 17787, 17788, 17901, 17911, 17917, 17918, 18032, 18146, 18147, 18248, 
    18257, 18403, 18453, 18480, 18484, 18498, 18512, 18526, 18544, 18567, 18615, 18635, 18876, 19144, 19320, 
    19535, 19575, 19576, 20028, 20029, 20035, 20056, 20086, 20714, 20715, 20719, 20723, 20724, 20810, 21059, 
    21087, 21150, 21228, 21452, 21486, 21577, 21698, 21734, 21994, 22023, 22024, 22047, 22163, 22164, 22179, 
    22216, 22423, 22431, 22450, 22536, 22575, 22578, 22639
) AND plan_id IN (16, 17, 18) AND deleted_at IS NULL AND renewal_increase_type = 'none';

-- skipped: the listed ids that no longer match (report them)
SELECT 'skipped' AS step, id, plan_id, deleted_at, renewal_increase_type, renewal_increase_value FROM reservations
WHERE id IN (
    1218, 2984, 3198, 3312, 3569, 3596, 3609, 4051, 4166, 4287, 4288, 4481, 4940, 4945, 5051, 5418, 5444, 5516, 
    6152, 6153, 6274, 6441, 6711, 6717, 7003, 7579, 7620, 7641, 7763, 7890, 7921, 8073, 8236, 8345, 8576, 9117, 
    9577, 10210, 10213, 10239, 10435, 10932, 11015, 11085, 11258, 11492, 12039, 12117, 13025, 13152, 13415, 
    13416, 13464, 13539, 13544, 13583, 13605, 13837, 13942, 13947, 14220, 14221, 14228, 14268, 14414, 14584, 
    14667, 14766, 14773, 14996, 15035, 15069, 15077, 15260, 15290, 15324, 15382, 15388, 15664, 15665, 15864, 
    15865, 15885, 16148, 16151, 16164, 16166, 16178, 16186, 16458, 16459, 16527, 16531, 16536, 16702, 16716, 
    16936, 17056, 17480, 17705, 17777, 17787, 17788, 17901, 17911, 17917, 17918, 18032, 18146, 18147, 18248, 
    18257, 18403, 18453, 18480, 18484, 18498, 18512, 18526, 18544, 18567, 18615, 18635, 18876, 19144, 19320, 
    19535, 19575, 19576, 20028, 20029, 20035, 20056, 20086, 20714, 20715, 20719, 20723, 20724, 20810, 21059, 
    21087, 21150, 21228, 21452, 21486, 21577, 21698, 21734, 21994, 22023, 22024, 22047, 22163, 22164, 22179, 
    22216, 22423, 22431, 22450, 22536, 22575, 22578, 22639
) AND NOT (plan_id IN (16, 17, 18) AND deleted_at IS NULL AND renewal_increase_type IN ('none', 'percent')
    AND (renewal_increase_type = 'none' OR renewal_increase_value = 10));

UPDATE reservations SET renewal_increase_type = 'percent', renewal_increase_value = 10
WHERE id IN (
    1218, 2984, 3198, 3312, 3569, 3596, 3609, 4051, 4166, 4287, 4288, 4481, 4940, 4945, 5051, 5418, 5444, 5516, 
    6152, 6153, 6274, 6441, 6711, 6717, 7003, 7579, 7620, 7641, 7763, 7890, 7921, 8073, 8236, 8345, 8576, 9117, 
    9577, 10210, 10213, 10239, 10435, 10932, 11015, 11085, 11258, 11492, 12039, 12117, 13025, 13152, 13415, 
    13416, 13464, 13539, 13544, 13583, 13605, 13837, 13942, 13947, 14220, 14221, 14228, 14268, 14414, 14584, 
    14667, 14766, 14773, 14996, 15035, 15069, 15077, 15260, 15290, 15324, 15382, 15388, 15664, 15665, 15864, 
    15865, 15885, 16148, 16151, 16164, 16166, 16178, 16186, 16458, 16459, 16527, 16531, 16536, 16702, 16716, 
    16936, 17056, 17480, 17705, 17777, 17787, 17788, 17901, 17911, 17917, 17918, 18032, 18146, 18147, 18248, 
    18257, 18403, 18453, 18480, 18484, 18498, 18512, 18526, 18544, 18567, 18615, 18635, 18876, 19144, 19320, 
    19535, 19575, 19576, 20028, 20029, 20035, 20056, 20086, 20714, 20715, 20719, 20723, 20724, 20810, 21059, 
    21087, 21150, 21228, 21452, 21486, 21577, 21698, 21734, 21994, 22023, 22024, 22047, 22163, 22164, 22179, 
    22216, 22423, 22431, 22450, 22536, 22575, 22578, 22639
) AND plan_id IN (16, 17, 18) AND deleted_at IS NULL AND renewal_increase_type = 'none';

SELECT 'reservations after' AS step, plan_id, renewal_increase_type, renewal_increase_value, COUNT(*) AS n FROM reservations
WHERE plan_id IN (16, 17, 18) AND deleted_at IS NULL GROUP BY plan_id, renewal_increase_type, renewal_increase_value ORDER BY plan_id;

-- not in the list (made after the copy) and still without the increase: fix by hand (Edit › Renewal Increase) if they should have it
SELECT 'not in the list, still none' AS step, id, plan_id, member_id, start_at, end_at, created_at FROM reservations
WHERE plan_id IN (16, 17, 18) AND deleted_at IS NULL AND renewal_increase_type = 'none';

COMMIT;
