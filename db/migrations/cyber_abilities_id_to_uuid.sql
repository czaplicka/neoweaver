-- ============================================================
-- MIGRATION: cyber_abilities.id  integer → uuid
-- Run each STEP in Supabase SQL Editor one by one.
-- ============================================================

-- ============================================================
-- STEP 1: Drop dependent views
-- ============================================================
DROP VIEW IF EXISTS public.v_cyber_character_abilities_cards;
DROP VIEW IF EXISTS public.cyber_character_complete_tags;

-- ============================================================
-- STEP 2: Add uuid column to cyber_abilities & fill it
-- ============================================================
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

ALTER TABLE public.cyber_abilities
  ADD COLUMN new_id uuid DEFAULT gen_random_uuid() NOT NULL;

-- ============================================================
-- STEP 3: Add matching uuid column to cyber_character_abilities
--         and populate it from the join
-- ============================================================
ALTER TABLE public.cyber_character_abilities
  ADD COLUMN ability_uuid uuid;

UPDATE public.cyber_character_abilities ca
SET ability_uuid = a.new_id
FROM public.cyber_abilities a
WHERE ca.ability_id = a.id;

ALTER TABLE public.cyber_character_abilities
  ALTER COLUMN ability_uuid SET NOT NULL;

-- ============================================================
-- STEP 4: Drop old FK constraint + old ability_id column
--         (drop the FK constraint by its actual name first)
-- ============================================================
-- Find constraint name with:
-- SELECT conname FROM pg_constraint
--   WHERE conrelid = 'public.cyber_character_abilities'::regclass
--   AND contype = 'f';
--
-- Then replace 'cyber_character_abilities_ability_id_fkey' below
-- with the real name if different:
ALTER TABLE public.cyber_character_abilities
  DROP CONSTRAINT IF EXISTS cyber_character_abilities_ability_id_fkey;

ALTER TABLE public.cyber_character_abilities
  DROP COLUMN ability_id;

-- ============================================================
-- STEP 5: Promote new_id to PK on cyber_abilities
-- ============================================================
ALTER TABLE public.cyber_abilities
  DROP CONSTRAINT cyber_abilities_pkey;

ALTER TABLE public.cyber_abilities
  DROP COLUMN id;

ALTER TABLE public.cyber_abilities
  RENAME COLUMN new_id TO id;

ALTER TABLE public.cyber_abilities
  ADD CONSTRAINT cyber_abilities_pkey PRIMARY KEY (id);

ALTER TABLE public.cyber_abilities
  ALTER COLUMN id SET DEFAULT gen_random_uuid();

-- ============================================================
-- STEP 6: Finalise FK in cyber_character_abilities
-- ============================================================
ALTER TABLE public.cyber_character_abilities
  RENAME COLUMN ability_uuid TO ability_id;

ALTER TABLE public.cyber_character_abilities
  ADD CONSTRAINT cyber_character_abilities_ability_id_fkey
  FOREIGN KEY (ability_id)
  REFERENCES public.cyber_abilities(id);

-- ============================================================
-- STEP 7: Recreate views
-- ============================================================
CREATE OR REPLACE VIEW public.v_cyber_character_abilities_cards AS
SELECT
  cca."ID"         AS char_ability_id,
  cca.character_id,
  ca.id            AS ability_id,
  ca.name,
  ca.description,
  ca.type,
  ca.source        AS ability_source,
  ca."GM",
  ca.cost,
  ca.img_url,
  ca.tags,
  cca.source       AS char_source
FROM cyber_character_abilities cca
JOIN cyber_abilities ca ON ca.id = cca.ability_id;

CREATE OR REPLACE VIEW public.cyber_character_complete_tags AS
SELECT
  c.id AS character_id,
  jsonb_array_elements_text(cl.tags) AS label,
  'class'::text  AS category,
  '#ffffff'::text AS color,
  'class'::text  AS source_type,
  cl.gm_instructions AS ai_instructions
FROM cyber_characters c
JOIN cyber_classes cl ON c.class_id = cl.id
UNION ALL
SELECT
  c.id AS character_id,
  jsonb_array_elements_text(r.tags) AS label,
  'race'::text   AS category,
  '#e0e0e0'::text AS color,
  'race'::text   AS source_type,
  r.gm_instructions AS ai_instructions
FROM cyber_characters c
JOIN cyber_races r
  ON c.race_id = r.id
  OR r.name = (SELECT cyber_races.parent_race FROM cyber_races WHERE cyber_races.id = c.race_id)
UNION ALL
SELECT
  cs.character_id,
  s.name     AS label,
  s.category,
  '#66ccff'::text AS color,
  'skill'::text  AS source_type,
  s.card_effect  AS ai_instructions
FROM cyber_character_skills cs
JOIN cyber_skills s ON cs.skill_id = s.id
UNION ALL
SELECT
  ca.character_id,
  a.name     AS label,
  a.type     AS category,
  '#ffcc33'::text AS color,
  'ability'::text AS source_type,
  a."GM"     AS ai_instructions
FROM cyber_character_abilities ca
JOIN cyber_abilities a ON ca.ability_id = a.id
UNION ALL
SELECT
  ci.character_id,
  replace(unnest(i.tags), '#'::text, ''::text) AS label,
  i.type     AS category,
  '#adff00'::text AS color,
  'item'::text   AS source_type,
  i.description  AS ai_instructions
FROM cyber_character_inventory ci
JOIN cyber_items i ON ci.item_id = i.id
WHERE ci.is_equipped = true
UNION ALL
SELECT
  cst.character_id,
  replace(st.label, '#'::text, ''::text) AS label,
  st.category,
  st.color_hex   AS color,
  'status'::text AS source_type,
  st.effect_description AS ai_instructions
FROM cyber_character_status cst
JOIN cyber_status_tags st ON cst.status_id = st.id
UNION ALL
SELECT
  ct.character_id,
  t.label,
  t.category,
  t.color,
  'narrative'::text AS source_type,
  t.gm AS ai_instructions
FROM cyber_character_tags ct
JOIN cyber_char_tags t ON ct.tag_id = t.id;
