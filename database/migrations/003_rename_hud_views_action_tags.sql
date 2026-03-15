-- =============================================================================
-- Migration 003: Rename HUD views + update table references
--
-- Changes:
--   1. Drop old views (world_status_summary_v2, location_status_summary)
--   2. Recreate as cyber_world_hud_stats and cyber_location_hud_stats
--      joining cyber_action_tags / cyber_action_tag_categories instead of
--      the renamed cyber_tags / cyber_tags_category tables.
--
-- Run in Supabase SQL editor or via psql.
-- Safe to run multiple times (DROP IF EXISTS).
-- =============================================================================

-- 1. Drop old views
DROP VIEW IF EXISTS public.world_status_summary_v2;
DROP VIEW IF EXISTS public.location_status_summary;

-- =============================================================================
-- 2. cyber_world_hud_stats  (was: world_status_summary_v2)
-- =============================================================================
CREATE VIEW public.cyber_world_hud_stats AS
WITH stats_calc AS (
    SELECT
        e.world_id,
        g.slug                                                        AS hud_slug,
        LEAST(100::numeric,
              GREATEST('-100'::numeric,
                       SUM(e.count::numeric * t.impact)))             AS total_impact,
        string_agg(DISTINCT t.name, ', ')                             AS recent_tags
    FROM  cyber_world_echo          e
    JOIN  cyber_action_tags         t  ON t.id = e.tag_id
    JOIN  cyber_action_tag_categories c ON c.id = t.category_id
    JOIN  cyber_hud_groups          g  ON g.id = c.hud_group_id
    GROUP BY e.world_id, g.slug
)
SELECT
    w.id AS world_id,

    COALESCE((SELECT s.total_impact FROM stats_calc s
              WHERE s.hud_slug = 'danger'     AND s.world_id = w.id), 0)          AS danger_val,
    COALESCE((SELECT s.recent_tags  FROM stats_calc s
              WHERE s.hud_slug = 'danger'     AND s.world_id = w.id), 'SECURE')   AS danger_tags,

    COALESCE((SELECT s.total_impact FROM stats_calc s
              WHERE s.hud_slug = 'morality'   AND s.world_id = w.id), 0)          AS order_val,
    COALESCE((SELECT s.recent_tags  FROM stats_calc s
              WHERE s.hud_slug = 'morality'   AND s.world_id = w.id), 'BALANCED') AS order_tags,

    COALESCE((SELECT s.total_impact FROM stats_calc s
              WHERE s.hud_slug = 'stealth'    AND s.world_id = w.id), 0)          AS stealth_val,
    COALESCE((SELECT s.recent_tags  FROM stats_calc s
              WHERE s.hud_slug = 'stealth'    AND s.world_id = w.id), 'CLEAN')    AS stealth_tags,

    COALESCE((SELECT s.total_impact FROM stats_calc s
              WHERE s.hud_slug = 'reputation' AND s.world_id = w.id), 0)          AS political_val,
    COALESCE((SELECT s.recent_tags  FROM stats_calc s
              WHERE s.hud_slug = 'reputation' AND s.world_id = w.id), 'NEUTRAL')  AS political_tags

FROM cyber_worlds w;

-- =============================================================================
-- 3. cyber_location_hud_stats  (was: location_status_summary)
-- =============================================================================
CREATE VIEW public.cyber_location_hud_stats AS
WITH stats_calc AS (
    SELECT
        e.world_id,
        e.location_id,
        g.slug                                                        AS hud_slug,
        LEAST(100::numeric,
              GREATEST('-100'::numeric,
                       SUM(e.count::numeric * t.impact)))             AS total_impact,
        string_agg(DISTINCT t.name, ', ')                             AS recent_tags
    FROM  cyber_world_echo          e
    JOIN  cyber_action_tags         t  ON t.id = e.tag_id
    JOIN  cyber_action_tag_categories c ON c.id = t.category_id
    JOIN  cyber_hud_groups          g  ON g.id = c.hud_group_id
    GROUP BY e.world_id, e.location_id, g.slug
)
SELECT
    w.id  AS world_id,
    m.id  AS location_id,
    m.location_name,
    m.coord_x,
    m.coord_y,

    COALESCE((SELECT s.total_impact FROM stats_calc s
              WHERE s.hud_slug = 'danger'     AND s.world_id = w.id AND s.location_id = m.id), 0)          AS danger_val,
    COALESCE((SELECT s.recent_tags  FROM stats_calc s
              WHERE s.hud_slug = 'danger'     AND s.world_id = w.id AND s.location_id = m.id), 'SECURE')   AS danger_tags,

    COALESCE((SELECT s.total_impact FROM stats_calc s
              WHERE s.hud_slug = 'morality'   AND s.world_id = w.id AND s.location_id = m.id), 0)          AS order_val,
    COALESCE((SELECT s.recent_tags  FROM stats_calc s
              WHERE s.hud_slug = 'morality'   AND s.world_id = w.id AND s.location_id = m.id), 'BALANCED') AS order_tags,

    COALESCE((SELECT s.total_impact FROM stats_calc s
              WHERE s.hud_slug = 'stealth'    AND s.world_id = w.id AND s.location_id = m.id), 0)          AS stealth_val,
    COALESCE((SELECT s.recent_tags  FROM stats_calc s
              WHERE s.hud_slug = 'stealth'    AND s.world_id = w.id AND s.location_id = m.id), 'CLEAN')    AS stealth_tags,

    COALESCE((SELECT s.total_impact FROM stats_calc s
              WHERE s.hud_slug = 'reputation' AND s.world_id = w.id AND s.location_id = m.id), 0)          AS political_val,
    COALESCE((SELECT s.recent_tags  FROM stats_calc s
              WHERE s.hud_slug = 'reputation' AND s.world_id = w.id AND s.location_id = m.id), 'NEUTRAL')  AS political_tags

FROM cyber_worlds w
JOIN (
    SELECT DISTINCT world_id, location_id
    FROM   cyber_world_echo
    WHERE  location_id IS NOT NULL
) lc ON lc.world_id = w.id
JOIN cyber_world_map m
    ON  m.world_id = lc.world_id
    AND m.id       = lc.location_id
WHERE m.is_active = TRUE;
