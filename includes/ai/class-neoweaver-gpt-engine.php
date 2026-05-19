<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/class-neoweaver-intent-router.php';
require_once __DIR__ . '/class-neoweaver-context-builder.php';
require_once dirname(__DIR__) . '/supabase-config.php';

/**
 * NeoWeaver GPT Engine
 * Uses OpenAI Responses API (replaces deprecated Assistants API & chat/completions).
 *
 * Key differences vs old chat/completions:
 *  - Endpoint: /v1/responses
 *  - History:  previous_response_id (OpenAI stores context server-side, no local message array)
 *              Session (last_response_id) stored in Supabase: cyber_chat_sessions
 *  - Lore:     file_search tool + Vector Store (no need to inject lore into every prompt)
 *  - Tokens:   input_tokens / output_tokens (not prompt_tokens / completion_tokens)
 *
 * wp-config.php constants used:
 *  - NEOWEAVER_OPENAI_API_KEY   — klucz OpenAI
 *  - NEOWEAVER_MODEL_GM         — model GM (gpt-4o)
 *  - NEOWEAVER_MODEL_ROUTER     — model routera (gpt-4o-mini)
 *  - NEOWEAVER_TOKENS_GM        — max output tokens dla GM (600)
 *  - NEOWEAVER_VECTOR_STORE_ID  — ID Vector Store do file_search
 *  - TW_SUPABASE_PROJECT_ID     — ID projektu Supabase (przez tw_supabase_url())
 *  - TW_SUPABASE_SERVICE_KEY    — service key Supabase (przez tw_supabase_service_key())
 */
class NeoWeaver_GPT_Engine {

    /**
     * Blok A — stały system prompt (cache'owany po stronie OpenAI).
     * Parametr 'instructions' w Responses API.
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

        // META = dane bez wywołania GPT
        if ($protocol === 'META') {
            return $this->handle_meta($char_id);
        }

        // 2. Pobierz dynamiczny kontekst z Supabase (Bloki B + C)
        $ctx             = $this->context->build($char_id, $protocol);
        $dynamic_context = $ctx['block_b'] . "\n\n---\n\n" . $ctx['block_c'];

        // 3. Zbuduj wejście — stan gry + wiadomość gracza
        $input_message = "[GAME STATE]\n{$dynamic_context}\n\n[PLAYER]\n{$message}";

        // 4. Pobierz ID poprzedniej odpowiedzi z Supabase (zastępuje tablicę messages)
        $previous_response_id = $this->get_previous_response_id($char_id);

        // 5. Wywołanie Responses API
        $request_body = [
            'model'             => NEOWEAVER_MODEL_GM,
            'instructions'      => self::SYSTEM_INSTRUCTIONS,
            'input'             => $input_message,
            'max_output_tokens' => NEOWEAVER_TOKENS_GM,
            'temperature'       => 0.85,
            'store'             => true, // wymagane do previous_response_id
            'tools' => defined('NEOWEAVER_VECTOR_STORE_ID') && NEOWEAVER_VECTOR_STORE_ID ? [
    [
        'type'             => 'file_search',
        'vector_store_ids' => [ NEOWEAVER_VECTOR_STORE_ID ],
    ],
] : [],
        ];

        // Dołącz historię konwersacji jeśli istnieje
        if ($previous_response_id) {
            $request_body['previous_response_id'] = $previous_response_id;
        }

        $api_response = wp_remote_post('https://api.openai.com/v1/responses', [
            'headers' => [
                'Authorization' => 'Bearer ' . NEOWEAVER_OPENAI_API_KEY,
                'Content-Type'  => 'application/json',
            ],
            'body'    => json_encode($request_body),
            'timeout' => 30,
        ]);

        if (is_wp_error($api_response)) {
            return ['error' => 'Połączenie z AI niedostępne. Spróbuj za chwilę.'];
        }

        $data = json_decode(wp_remote_retrieve_body($api_response), true);

        if (!empty($data['error'])) {
            return ['error' => $data['error']['message'] ?? 'Błąd OpenAI API'];
        }

        // 6. Wyciągnij tekst z nowej struktury odpowiedzi
        $raw         = '';
        $response_id = $data['id'] ?? null;

        foreach ($data['output'] ?? [] as $output_item) {
            if (($output_item['type'] ?? '') === 'message') {
                foreach ($output_item['content'] ?? [] as $content) {
                    if (($content['type'] ?? '') === 'output_text') {
                        $raw .= $content['text'];
                    }
                }
            }
        }

        // Responses API zwraca input_tokens / output_tokens (nie prompt/completion)
        $usage = $data['usage'] ?? [];

        // 7. Zapisz nowe response_id do Supabase (historia po stronie OpenAI)
        if ($response_id) {
            $this->save_response_id($char_id, $response_id, $ctx['world_id'] ?? null);
        }

        // 8. Loguj tokeny do Supabase
        $this->log_tokens($char_id, $ctx['world_id'] ?? null, $usage, $protocol);

        // 9. Parsuj tagi systemowe z odpowiedzi GM-a
        $parsed = $this->parse_tags($raw);

        return [
            'text'        => $parsed['text'],
            'tags'        => $parsed['tags'],
            'protocol'    => $protocol,
            'response_id' => $response_id,
            'tokens'      => [
                'prompt'     => $usage['input_tokens']  ?? 0,
                'completion' => $usage['output_tokens'] ?? 0,
            ],
        ];
    }

    // ============================================================
    // Pomocniczy helper Supabase REST — używa tw_supabase_url()
    // i tw_supabase_service_key() z includes/supabase-config.php
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
            error_log('[NeoWeaver GPT] Supabase request failed: ' . $response->get_error_message());
            return null;
        }
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($decoded) ? $decoded : null;
    }

    // ============================================================
    // Historia sesji — Supabase: cyber_chat_sessions
    // Przechowujemy JEDEN response_id per postać.
    // OpenAI odtwarza pełną historię rozmowy po swojej stronie.
    // ============================================================

    private function get_previous_response_id(string $char_id): ?string {
        $result = $this->supabase_request(
            'GET',
            '/rest/v1/cyber_chat_sessions?char_id=eq.' . urlencode($char_id) . '&select=last_response_id&limit=1'
        );
        return $result[0]['last_response_id'] ?? null;
    }

    private function save_response_id(string $char_id, string $response_id, ?string $world_id): void {
        $this->supabase_request(
            'POST',
            '/rest/v1/cyber_chat_sessions',
            [
                'char_id'          => $char_id,
                'last_response_id' => $response_id,
                'world_id'         => $world_id,
                'updated_at'       => gmdate('c'),
            ],
            // UPSERT — aktualizuje jeśli char_id już istnieje (UNIQUE constraint)
            ['Prefer' => 'resolution=merge-duplicates,return=minimal']
        );
    }

    /**
     * Reset historii gracza (np. nowa sesja gry, śmierć postaci).
     * Usuwa response_id — następna wiadomość zacznie nową rozmowę z AI.
     */
    public function reset_history(string $char_id): void {
        $this->supabase_request(
            'DELETE',
            '/rest/v1/cyber_chat_sessions?char_id=eq.' . urlencode($char_id)
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
    // META — odpowiedź bez wywołania GPT (status, HP, mapa)
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
