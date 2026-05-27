-- =============================================================
-- NeoWeaver · Ascension System Migration
-- Run once in Supabase SQL Editor
-- =============================================================

-- 1. Add ascension_level column to cyber_character_deck
ALTER TABLE public.cyber_character_deck
  ADD COLUMN IF NOT EXISTS ascension_level smallint NOT NULL DEFAULT 0;

-- Constraint: 0–5 ascensions max
ALTER TABLE public.cyber_character_deck
  ADD CONSTRAINT ascension_level_check CHECK (ascension_level >= 0 AND ascension_level <= 5);

-- 2. Index for quick lookups
CREATE INDEX IF NOT EXISTS idx_character_deck_ascension
  ON public.cyber_character_deck (character_id, deck_id);

-- =============================================================
-- ASCENSION RULES (informational comment)
-- Ascension I   → 2 identical cards (same deck_id) → merged → 1 card with ascension_level=1
-- Ascension II  → 3 copies needed (ascension_level=0)
-- Ascension III → 4 copies
-- Ascension IV  → 5 copies
-- Ascension V   → 6 copies
-- After merge: keep highest current_level and current_xp from source cards
-- =============================================================
