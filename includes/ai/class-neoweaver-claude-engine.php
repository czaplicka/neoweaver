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
 * History is stored in Supabase (cyber_chat_messages) using message_type
 * values 'player' and 'gm' — mapped to 'user'/'assistant' only when
 * passing to Claude API (which requires those exact role names).
 *
 * wp-config.php constants used:
 *  - NEOWEAVER_ANTHROPIC_API_KEY — klucz Anthropic
 *  - NEOWEAVER_MODEL_GM          — model GM (np. claude-sonnet-4-5)
 *  - NEOWEAVER_TOKENS_GM         — max output tokens dla GM (600)
 *  - TW_SUPABASE_PROJECT_ID      — ID projektu Supabase
 *  - TW_SUPABASE_SERVICE_KEY     — service key Supabase
 */
class NeoWeaver_Claude_Engine {

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
    // PUBLIC: główna metoda
    // Zwraca: ['text'=>'...', 'tags'=>[...], 'protocol'=>'...', 'tokens'=>[...]]
    // ============================================================
    public function process( string $char_id, string $message ): array {

        $protocol = NeoWeaver_Intent_Router::classify( $message );

        if ( $protocol === 'META' ) {
            return $this->handle_meta( $char_id );
        }

        $ctx             = $this->context->build( $char_id, $protocol );
        $dynamic_context = $ctx['block_b'] . "\n\n---\n\n" . $ctx['block_c'];

        // Pobierz historię z Supabase (message_type player/gm → role user/assistant)
        $history = $this->get_history( $char_id );

        // Dołącz aktualną wiadomość gracza
        $history[] = [
            'role'    => 'user',
            'content' => "[GAME STATE]\n{$dynamic_context}\n\n[PLAYER]\n{$message}",
        ];

        $result = NeoWeaver_Claude_Client::call(
            self::SYSTEM_INSTRUCTIONS,
            $history,
            NEOWEAVER_MODEL_GM,
            NEOWEAVER_TOKENS_GM,
            0.85
        );

        if ( is_wp_error( $result ) ) {
            return [ 'error' => $result->get_error_message() ];
        }

        // Zapisz do Supabase z message_type = 'player' / 'gm'
        $this->save_to_history( $char_id, $message, $result['content'], $ctx['world_id'] ?? null );

        $this->log_tokens( $char_id, $ctx['world_id'] ?? null, $result['usage'], $protocol );

        $parsed = $this->parse_tags( $result['content'] );

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
    private function supabase_request( string $method, string $endpoint, array $body = [], array $extra_headers = [] ): ?array {
        $args = [
            'method'  => $method,
            'headers' => array_merge( [
                'apikey'        => tw_supabase_service_key(),
                'Authorization' => 'Bearer ' . tw_supabase_service_key(),
                'Content-Type'  => 'application/json',
            ], $extra_headers ),
            'timeout' => 10,
        ];
        if ( ! empty( $body ) ) {
            $args['body'] = json_encode( $body );
        }
        $response = wp_remote_request( trailingslashit( tw_supabase_url() ) . ltrim( $endpoint, '/' ), $args );
        if ( is_wp_error( $response ) ) {
            error_log( '[NeoWeaver Claude] Supabase request failed: ' . $response->get_error_message() );
            return null;
        }
        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $decoded ) ? $decoded : null;
    }

    // ============================================================
    // Historia konwersacji — cyber_chat_messages
    // Przechowujemy: message_type = 'player' | 'gm'
    // Do Claude API mapujemy: player → user, gm → assistant
    // ============================================================

    private function get_history( string $char_id ): array {
        $result = $this->supabase_request(
            'GET',
            '/rest/v1/cyber_chat_messages'
                . '?char_id=eq.' . urlencode( $char_id )
                . '&select=message_type,content'
                . '&order=created_at.desc'
                . '&limit=14'
        );

        if ( empty( $result ) ) {
            return [];
        }

        // Odwróć — chcemy od najstarszej do najnowszej
        $result = array_reverse( $result );

        return array_map( function ( $row ) {
            // Mapowanie message_type → role wymagane przez Claude API
            $role = ( ( $row['message_type'] ?? '' ) === 'player' ) ? 'user' : 'assistant';
            return [
                'role'    => $role,
                'content' => $row['content'] ?? '',
            ];
        }, $result );
    }

    private function save_to_history( string $char_id, string $user_message, string $gm_message, ?string $world_id ): void {
        // Wiadomość gracza
        $this->supabase_request(
            'POST',
            '/rest/v1/cyber_chat_messages',
            [
                'char_id'      => $char_id,
                'world_id'     => $world_id,
                'message_type' => 'player',
                'content'      => $user_message,
                'created_at'   => gmdate( 'c' ),
            ],
            [ 'Prefer' => 'return=minimal' ]
        );

        // Odpowiedź GM-a
        $this->supabase_request(
            'POST',
            '/rest/v1/cyber_chat_messages',
            [
                'char_id'      => $char_id,
                'world_id'     => $world_id,
                'message_type' => 'gm',
                'content'      => $gm_message,
                'created_at'   => gmdate( 'c' ),
            ],
            [ 'Prefer' => 'return=minimal' ]
        );
    }

    /**
     * Reset historii gracza (nowa sesja, śmierć postaci itp.)
     */
    public function reset_history( string $char_id ): void {
        $this->supabase_request(
            'DELETE',
            '/rest/v1/cyber_chat_messages?char_id=eq.' . urlencode( $char_id )
        );
    }

    // ============================================================
    // Logowanie tokenów (cyber_token_ledger)
    // ============================================================
    private function log_tokens( string $char_id, ?string $world_id, array $usage, string $protocol ): void {
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
            [ 'Prefer' => 'return=minimal' ]
        );
    }

    // ============================================================
    // Parser tagów systemowych (#TAG lub #TAG:value)
    // ============================================================
    private function parse_tags( string $raw ): array {
        $tags = [];
        $text = preg_replace_callback(
            '/#([A-Z][A-Z0-9_]+)(?::([a-zA-Z0-9_\-]+))?/',
            function ( $m ) use ( &$tags ) {
                $tags[] = [ 'tag' => $m[1], 'val' => $m[2] ?? null ];
                return '';
            },
            $raw
        );

        return [
            'text' => trim( preg_replace( '/\s+/', ' ', $text ) ),
            'tags' => $tags,
        ];
    }

    // ============================================================
    // META — bez wywołania AI
    // ============================================================
    private function handle_meta( string $char_id ): array {
        return [
            'text'     => '',
            'tags'     => [ [ 'tag' => 'HUD_REFRESH', 'val' => null ] ],
            'protocol' => 'META',
            'tokens'   => [ 'prompt' => 0, 'completion' => 0 ],
        ];
    }
}
