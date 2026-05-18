<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/class-neoweaver-intent-router.php';
require_once __DIR__ . '/class-neoweaver-context-builder.php';

class NeoWeaver_GPT_Engine {

    private const MAX_HISTORY   = 12;  // wiadomości w oknie kontekstowym
    private const MAX_TOKENS    = 600;
    private const MODEL         = 'gpt-4o';

    private NeoWeaver_Context_Builder $context;

    public function __construct() {
        $this->context = new NeoWeaver_Context_Builder();
    }

    /**
     * Główna metoda — wywołaj ją z AJAX handlera.
     * Zwraca ['text' => '...', 'tags' => [...], 'tokens' => [...]]
     */
    public function process(string $char_id, string $session_id, string $message): array {
        // 1. Klasyfikacja
        $protocol = NeoWeaver_Intent_Router::classify($message);

        // META = zwracamy dane bez GPT
        if ($protocol === 'META') {
            return $this->handle_meta($char_id);
        }

        // 2. Kontekst
        $ctx = $this->context->build($char_id, $protocol);

        $system_prompt = implode("\n\n---\n\n", [
            $ctx['block_a'],
            $ctx['block_b'],
            $ctx['block_c'],
        ]);

        // 3. Historia konwersacji (przycinana)
        $history = $this->get_history($session_id);

        // 4. Wywołanie API
        $api_response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . NEOWEAVER_OPENAI_KEY,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'model'    => self::MODEL,
                'messages' => array_merge(
                    [['role' => 'system', 'content' => $system_prompt]],
                    $history,
                    [['role' => 'user', 'content' => $message]]
                ),
                'max_tokens' => self::MAX_TOKENS,
                'temperature' => 0.85,
                'user' => 'neoweaver_' . $char_id,
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($api_response)) {
            return ['error' => 'Połączenie z AI niedostępne. Spróbuj za chwilę.'];
        }

        $body  = json_decode(wp_remote_retrieve_body($api_response), true);
        $raw   = $body['choices'][0]['message']['content'] ?? '';
        $usage = $body['usage'] ?? [];

        // 5. Zapis tokenów
        $this->log_tokens($char_id, $session_id, $ctx['world_id'], $usage, $protocol);

        // 6. Zapis historii
        $this->save_history($session_id, $message, $raw);

        // 7. Parse tagów
        $parsed = $this->parse_tags($raw);

        return [
            'text'     => $parsed['text'],
            'tags'     => $parsed['tags'],
            'protocol' => $protocol,
            'tokens'   => [
                'prompt'     => $usage['prompt_tokens'] ?? 0,
                'completion' => $usage['completion_tokens'] ?? 0,
            ],
        ];
    }

    // ------------------------------------------------
    // Historia konwersacji (przechowywana w Supabase)
    // ------------------------------------------------
    private function get_history(string $session_id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT role, content FROM {$wpdb->prefix}neoweaver_chat_history
             WHERE session_id = %s ORDER BY created_at DESC LIMIT %d",
            $session_id, self::MAX_HISTORY
        ), ARRAY_A);

        return array_reverse($rows ?? []);
    }

    private function save_history(string $session_id, string $user_msg, string $ai_msg): void {
        global $wpdb;
        $table = $wpdb->prefix . 'neoweaver_chat_history';

        $wpdb->insert($table, [
            'session_id' => $session_id,
            'role'       => 'user',
            'content'    => $user_msg,
            'created_at' => current_time('mysql'),
        ]);

        $wpdb->insert($table, [
            'session_id' => $session_id,
            'role'       => 'assistant',
            'content'    => $ai_msg,
            'created_at' => current_time('mysql'),
        ]);

        // Przytnij historię — max 30 wierszy per sesja
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE session_id = %s AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM {$table} WHERE session_id = %s ORDER BY created_at DESC LIMIT 30
                ) AS t
            )",
            $session_id, $session_id
        ));
    }

    // ------------------------------------------------
    // Logowanie tokenów do Supabase
    // ------------------------------------------------
    private function log_tokens(string $char_id, string $session_id, ?string $world_id, array $usage, string $protocol): void {
        wp_remote_post(NEOWEAVER_SUPABASE_URL . '/rest/v1/cyber_token_ledger', [
            'headers' => [
                'apikey'        => NEOWEAVER_SUPABASE_KEY,
                'Authorization' => 'Bearer ' . NEOWEAVER_SUPABASE_KEY,
                'Content-Type'  => 'application/json',
                'Prefer'        => 'return=minimal',
            ],
            'body' => json_encode([
                'char_id'           => $char_id,
                'session_id'        => $session_id,
                'world_id'          => $world_id,
                'prompt_tokens'     => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'model'             => self::MODEL,
                'protocol'          => $protocol,
            ]),
            'timeout' => 5,
        ]);
    }

    // ------------------------------------------------
    // Parser tagów systemowych z odpowiedzi GM-a
    // ------------------------------------------------
    private function parse_tags(string $raw): array {
        $tags = [];
        $text = preg_replace_callback(
            '/#([A-Z][A-Z0-9_]+)(?::([a-zA-Z0-9_\-]+))?/',
            function($m) use (&$tags) {
                $tags[] = ['tag' => $m[1], 'val' => $m[2] ?? null];
                return ''; // usuń z tekstu
            },
            $raw
        );

        return [
            'text' => trim(preg_replace('/\s+/', ' ', $text)),
            'tags' => $tags,
        ];
    }

    // ------------------------------------------------
    // META — odpowiedź bez GPT
    // ------------------------------------------------
    private function handle_meta(string $char_id): array {
        $ctx = $this->context->build($char_id, 'META');
        return [
            'text'     => '', // JS wyrenderuje HUD
            'tags'     => [['tag' => 'HUD_REFRESH', 'val' => null]],
            'protocol' => 'META',
            'tokens'   => ['prompt' => 0, 'completion' => 0],
        ];
    }
}
