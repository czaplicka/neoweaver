-- Migration: Bug #5 — add last_seen_at heartbeat column to cyber_campaign_signups
-- Run once in Supabase SQL editor.

-- 1. Add column (nullable — existing rows will have NULL until first heartbeat)
ALTER TABLE cyber_campaign_signups
    ADD COLUMN IF NOT EXISTS last_seen_at TIMESTAMPTZ DEFAULT NULL;

-- 2. Index so the lobby query (ORDER BY last_seen_at, WHERE last_seen_at > now()-90s)
--    does not do a full table scan when many campaigns exist.
CREATE INDEX IF NOT EXISTS idx_cyber_campaign_signups_last_seen
    ON cyber_campaign_signups (campaign_id, last_seen_at DESC NULLS LAST);

-- 3. Helper: mark a player as online (called by the WP AJAX heartbeat handler)
--    Usage: SELECT upsert_lobby_heartbeat(42, 7);
CREATE OR REPLACE FUNCTION upsert_lobby_heartbeat(
    p_campaign_id INT,
    p_wp_user_id  INT
)
RETURNS VOID
LANGUAGE sql
SECURITY DEFINER
AS $$
    UPDATE cyber_campaign_signups
    SET    last_seen_at = NOW()
    WHERE  campaign_id  = p_campaign_id
      AND  wp_user_id   = p_wp_user_id;
$$;
