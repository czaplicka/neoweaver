-- Migration: Bug #5 — add last_seen_at heartbeat column to cyber_campaign_signups
-- STATUS: APPLIED — column added manually in Supabase with DEFAULT NOW()
--
-- DEFAULT NOW() means:
--   * existing rows immediately get a current timestamp instead of NULL
--   * new signups start with last_seen_at = join time
--     → they show a green dot instantly, BEFORE the first heartbeat fires (≤20 s)
--   * the JS online check (last_seen_at within 90 s) still works correctly
--     because the first heartbeat arrives within 20 s and keeps the dot green

-- 1. Column (already applied — kept here for reference)
ALTER TABLE cyber_campaign_signups
    ADD COLUMN IF NOT EXISTS last_seen_at TIMESTAMPTZ DEFAULT NOW();

-- 2. Index — run this if not yet created
CREATE INDEX IF NOT EXISTS idx_cyber_campaign_signups_last_seen
    ON cyber_campaign_signups (campaign_id, last_seen_at DESC NULLS LAST);

-- 3. Helper function (optional — used by server-side triggers if needed)
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
