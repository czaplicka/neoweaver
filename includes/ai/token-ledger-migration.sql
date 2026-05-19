-- ============================================================
-- NEOWEAVER — MIGRACJA: token ledger + fn_log_tokens
-- Uruchom raz w Supabase SQL Editor.
-- ============================================================

-- 1. Tabela cyber_token_ledger
-- Przechowuje każde wywołanie OpenAI per postać/sesja/protokół.
CREATE TABLE IF NOT EXISTS public.cyber_token_ledger (
    id                BIGSERIAL PRIMARY KEY,
    wp_user_id        INTEGER       NOT NULL,
    char_id           UUID          REFERENCES public.cyber_characters(id) ON DELETE SET NULL,
    session_id        UUID,
    campaign_id       UUID,
    channel_id        UUID,
    protocol          TEXT          NOT NULL DEFAULT 'UNKNOWN',
    prompt_tokens     INTEGER       NOT NULL DEFAULT 0,
    completion_tokens INTEGER       NOT NULL DEFAULT 0,
    model             TEXT          NOT NULL DEFAULT 'gpt-4o',
    created_at        TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

-- Indeksy do szybkich zapytań per user i per char
CREATE INDEX IF NOT EXISTS idx_token_ledger_wp_user  ON public.cyber_token_ledger (wp_user_id);
CREATE INDEX IF NOT EXISTS idx_token_ledger_char     ON public.cyber_token_ledger (char_id);
CREATE INDEX IF NOT EXISTS idx_token_ledger_created  ON public.cyber_token_ledger (created_at);

-- RLS: gracze widzą tylko własne tokeny
ALTER TABLE public.cyber_token_ledger ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "token_ledger_owner" ON public.cyber_token_ledger;
CREATE POLICY "token_ledger_owner"
  ON public.cyber_token_ledger
  FOR SELECT
  USING ( wp_user_id = (current_setting('request.jwt.claims', true)::json->>'sub')::int );

-- ============================================================
-- 2. Funkcja fn_log_tokens
-- Wywoływana z PHP przez tw_supabase_rpc('fn_log_tokens', [...])
-- Używa SECURITY DEFINER żeby ominąć RLS przy zapisie z backendu.
-- ============================================================

CREATE OR REPLACE FUNCTION public.fn_log_tokens(
    p_wp_user_id        INTEGER,
    p_char_id           UUID        DEFAULT NULL,
    p_session_id        UUID        DEFAULT NULL,
    p_campaign_id       UUID        DEFAULT NULL,
    p_channel_id        UUID        DEFAULT NULL,
    p_protocol          TEXT        DEFAULT 'UNKNOWN',
    p_prompt_tokens     INTEGER     DEFAULT 0,
    p_completion_tokens INTEGER     DEFAULT 0,
    p_model             TEXT        DEFAULT 'gpt-4o'
)
RETURNS void
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
BEGIN
    INSERT INTO public.cyber_token_ledger (
        wp_user_id, char_id, session_id, campaign_id, channel_id,
        protocol, prompt_tokens, completion_tokens, model
    ) VALUES (
        p_wp_user_id, p_char_id, p_session_id, p_campaign_id, p_channel_id,
        p_protocol, p_prompt_tokens, p_completion_tokens, p_model
    );
END;
$$;

-- ============================================================
-- 3. Widok: koszty miesięczne per gracz (admin)
-- Ceny GPT-4o: input $2.50 / 1M, output $10.00 / 1M
-- ============================================================

CREATE OR REPLACE VIEW public.v_token_costs_monthly AS
SELECT
    tl.wp_user_id,
    tl.char_id,
    c.name                                                           AS char_name,
    tl.model,
    tl.protocol,
    SUM(tl.prompt_tokens)                                            AS total_prompt_tokens,
    SUM(tl.completion_tokens)                                        AS total_completion_tokens,
    SUM(tl.prompt_tokens + tl.completion_tokens)                     AS total_tokens,
    ROUND(
        SUM(tl.prompt_tokens * 0.0000025 + tl.completion_tokens * 0.00001)::NUMERIC,
        6
    )                                                                AS cost_usd,
    COUNT(*)                                                         AS call_count,
    DATE_TRUNC('month', tl.created_at)                              AS month
FROM public.cyber_token_ledger tl
LEFT JOIN public.cyber_characters c ON c.id = tl.char_id
WHERE tl.created_at >= NOW() - INTERVAL '30 days'
GROUP BY tl.wp_user_id, tl.char_id, c.name, tl.model, tl.protocol,
         DATE_TRUNC('month', tl.created_at)
ORDER BY cost_usd DESC;
