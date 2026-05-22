<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/class-neoweaver-intent-router.php';
require_once __DIR__ . '/class-neoweaver-context-builder.php';
require_once __DIR__ . '/class-neoweaver-claude-client.php';
require_once dirname(__DIR__) . '/supabase-config.php';

/**
 * NeoWeaver Claude Engine
 * Uses Anthropic Claude API via NeoWeaver_Claude_Client.
 *
 * History is stored in Supabase (cyber_chat_messages) and passed
 * as a messages array on every request — Claude has no server-side threading.
 *
 * wp-config.php constants used:
 *  - NEOWEAVER_MODEL_GM         — model GM (claude-sonnet-4-5 or similar)
 *  - NEOWEAVER_TOKENS_GM        — max output tokens dla GM (600)
 *  - TW_SUPABASE_PROJECT_ID     — ID projektu Supabase (przez tw_supabase_url())
 *  - TW_SUPABASE_SERVICE_KEY    — service key Supabase (przez tw_supabase_service_key())
 */
class NeoWeaver_Claude_Engine {

    /**
     * Stały system prompt — instrukcje dla GM-a.
     */
    private const SYSTEM_INSTRUCTIONS = <<<PROMPT
You are the AI Game Master of NeoWeave — a dark, narrative RPG.
Rules: Respond in character as the world. Keep answers under 120 words unless combat demands more.
Embed system tags in your response using syntax #TAG or #TAG:value
(e.g. #ENTROPY_UP:5, #LOC:42, #STATUS_POISONED, #HP_CHANGE:-10, #COMBAT_START, #GOLD_CHANGE:-5).
Tags are parsed by the system — the player never sees them. Never explain tags to the player.
Respond in the same language the player uses.
PROMPT;

    private NeoWeaver_Context_Builder $context;

    public function __construct() {
        $this->context = new NeoWeaver_Context_Builder();
    }

    // ============================================================
    // PUBLIC: główna metoda — wywołaj z AJAX handlera
    // Zwraca: ['text'=>'...', 'tags'=>[...], 'protocol'=>'...', 'tokens'=>[...]]
    // ============================================================
    public function process(string $char_id, string $message): array {

        // 1. Klasyfikacja intencji (regex + GPT-mini fallback)
        $protocol = NeoWeaver_Intent_Router::classify($message);

        // META = dane bez wywołania AI
        if ($protocol === 'META') {
            return $this->handle_meta($char_id);
        }

        // 2. Pobierz dynamiczny kontekst z Supabase (Bloki B + C)
        $ctx             = $this->context->build($char_id, $protocol);
        $dynamic_context = $ctx['block_b'] . "\n\n---\n\n" . $ctx['block_c'];
        $system_prompt   = self::SYSTEM_INSTRUCTIONS;

        // 3. Pobierz historię konwersacji (ostatnie 14 wiadomości) z cyber_chat_messages
        $history = $this->get_history($char_id);

        // 4. Dołącz aktualną wiadomość gracza (z kontekstem stanu gry)
        $history[] = [
            'role'    => 'user',
            'content' => "[GAME STATE]\n{$dynamic_context}\n\n[PLAYER]\n{$message}",
        ];

        // 5. Wywołaj Claude
        $result = NeoWeaver_Claude_Client::call(
            $system_prompt,
            $history,
            NEOWEAVER_MODEL_GM,
            NEOWEAVER_TOKENS_GM,
            0.85
        );

        if (is_wp_error($result)) {
            return ['error' => $result->get_error_message()];
        }

        // 6. Zapisz parę user+assistant do historii w Supabase
        $this->save_to_history($char_id, $message, $result['content'], $ctx['world_id'] ?? null);

        // 7. Loguj tokeny do Supabase
        $this->log_tokens($char_id, $ctx['world_id'] ?? null, $result['usage'], $protocol);

        // 8. Parsuj tagi systemowe z odpowiedzi GM-a
        $parsed = $this->parse_tags($result['content']);

        return [
            'text'     => $parsed['text'],
            'tags'     => $parsed['tags'],
            'protocol' => $protocol,
            'tokens'   => [
                'prompt'     => $result['usage']['input_tokens']  ?? 0,
                'completion' => $result['usage']['output_tokens'] ?? 0,
            ],
        ];
    }

    // ============================================================
    // Pomocniczy helper Supabase REST
    // ============================================================
    private function supabase_request(string $method, string $endpoint, array $body = [], array $extra_headers = []): array|null {
        $args = [
            'method'  => $method,
            'headers' => array_merge([
                'apikey'        => tw_supabase_service_key(),
                'Authorization' => 'Bearer ' . tw_supabase_service_key(),
                'Content-Type'  => 'application/json',
            ], $extra_headers),
            'timeout' => 10,
        ];
        if (!empty($body)) {
            $args['body'] = json_encode($body);
        }
        $response = wp_remote_request(trailingslashit(tw_supabase_url()) . ltrim($endpoint, '/'), $args);
        if (is_wp_error($response)) {
            error_log('[NeoWeaver Claude] Supabase request failed: ' . $response->get_error_message());
            return null;
        }
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($decoded) ? $decoded : null;
    }

    // ============================================================
    // Historia konwersacji — Supabase: cyber_chat_messages
    // Pobieramy ostatnie 14 wiadomości (7 tur) posortowane ASC.
    // ============================================================

    /**
     * Pobierz historię jako tablicę [{role, content}, ...].
     */
    private function get_history(string $char_id): array {
        // Pobieramy 14 ostatnich DESC, potem odwracamy do ASC
        $result = $this->supabase_request(
            'GET',
            '/rest/v1/cyber_chat_messages'
            . '?char_id=eq.' . urlencode($char_id)
            . '&select=role,content'
            . '&order=created_at.desc'
            . '&limit=14'
        );

        if (empty($result)) {
            return [];
        }

        // Odwróć kolejność — chcemy od najstarszej do najnowszej
        return array_reverse(array_map(fn($row) => [
            'role'    => $row['role'],
            'content' => $row['content'],
        ], $result));
    }

    /**
     * Zapisz wiadomość gracza i odpowiedź GM-a do historii.
     * Uwaga: zapisujemy oryginalną wiadomość gracza (bez prefixu GAME STATE).
     */
    private function save_to_history(string $char_id, string $user_message, string $assistant_message, ?string $world_id): void {
        // Wstaw user
        $this->supabase_request(
            'POST',
            '/rest/v1/cyber_chat_messages',
            [
                'char_id'    => $char_id,
                'world_id'   => $world_id,
                'role'       => 'user',
                'content'    => $user_message,
                'created_at' => gmdate('c'),
            ],
            ['Prefer' => 'return=minimal']
        );

        // Wstaw assistant
        $this->supabase_request(
            'POST',
            '/rest/v1/cyber_chat_messages',
            [
                'char_id'    => $char_id,
                'world_id'   => $world_id,
                'role'       => 'assistant',
                'content'    => $assistant_message,
                'created_at' => gmdate('c'),
            ],
            ['Prefer' => 'return=minimal']
        );
    }

    /**
     * Reset historii gracza (nowa sesja gry, śmierć postaci itp.).
     * Usuwa wszystkie wiadomości z cyber_chat_messages dla danej postaci.
     */
    public function reset_history(string $char_id): void {
        $this->supabase_request(
            'DELETE',
            '/rest/v1/cyber_chat_messages?char_id=eq.' . urlencode($char_id)
        );
    }

    // ============================================================
    // Logowanie tokenów do Supabase (cyber_token_ledger)
    // ============================================================
    private function log_tokens(string $char_id, ?string $world_id, array $usage, string $protocol): void {
        $this->supabase_request(
            'POST',
            '/rest/v1/cyber_token_ledger',
            [
                'char_id'           => $char_id,
                'world_id'          => $world_id,
                'prompt_tokens'     => $usage['input_tokens']  ?? 0,
                'completion_tokens' => $usage['output_tokens'] ?? 0,
                'model'             => NEOWEAVER_MODEL_GM,
                'protocol'          => $protocol,
            ],
            ['Prefer' => 'return=minimal']
        );
    }

    // ============================================================
    // Parser tagów systemowych z odpowiedzi GM-a.
    // Usuwa tagi z tekstu przed wyświetleniem graczowi.
    // ============================================================
    private function parse_tags(string $raw): array {
        $tags = [];
        $text = preg_replace_callback(
            '/#([A-Z][A-Z0-9_]+)(?::([a-zA-Z0-9_\-]+))?/',
            function ($m) use (&$tags) {
                $tags[] = ['tag' => $m[1], 'val' => $m[2] ?? null];
                return '';
            },
            $raw
        );

        return [
            'text' => trim(preg_replace('/\s+/', ' ', $text)),
            'tags' => $tags,
        ];
    }

    // ============================================================
    // META — odpowiedź bez wywołania AI (status, HP, mapa)
    // ============================================================
    private function handle_meta(string $char_id): array {
        return [
            'text'     => '',
            'tags'     => [['tag' => 'HUD_REFRESH', 'val' => null]],
            'protocol' => 'META',
            'tokens'   => ['prompt' => 0, 'completion' => 0],
        ];
    }
}
