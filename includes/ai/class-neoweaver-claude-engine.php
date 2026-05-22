<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/class-neoweaver-intent-router.php';
require_once __DIR__ . '/class-neoweaver-context-builder.php';
require_once __DIR__ . '/class-neoweaver-claude-client.php';
require_once dirname(__DIR__) . '/supabase-config.php';

/**
 * NeoWeaver Claude Engine
 *
 * Historia rozmowy trzymana w cyber_chat_messages:
 * - channel_id
 * - char_id
 * - campaign_id
 * - message_type = player / gm
 *
 * Claude nie ma previous_response_id, więc wysyłamy explicite historię wiadomości.
 */
class NeoWeaver_Claude_Engine {

    /**
     * Stały prompt systemowy dla GM-a.
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
    // PUBLIC
    // ============================================================
    public function process( string $char_id, string $message ): array {

        $protocol = NeoWeaver_Intent_Router::classify( $message );

        if ( $protocol === 'META' ) {
            return $this->handle_meta( $char_id );
        }

        $ctx             = $this->context->build( $char_id, $protocol );
        $dynamic_context = $ctx['block_b'] . "\n\n---\n\n" . $ctx['block_c'];
        $system_prompt   = self::SYSTEM_INSTRUCTIONS;

        $channel_id  = $ctx['channel_id']  ?? null;
        $campaign_id = $ctx['campaign_id'] ?? null;
        $world_id    = $ctx['world_id']    ?? null;

        if ( ! $channel_id ) {
            return [ 'error' => 'Brak channel_id dla tej rozmowy.' ];
        }

        // Ostatnie 14 wiadomości z właściwego kanału / postaci / kampanii
        $history = $this->get_history( $channel_id, $char_id, $campaign_id );

        // Doklejamy bieżącą wiadomość gracza z kontekstem gry
        $history[] = [
            'role'    => 'user',
            'content' => "[GAME STATE]\n{$dynamic_context}\n\n[PLAYER]\n{$message}",
        ];

        $result = NeoWeaver_Claude_Client::call(
            $system_prompt,
            $history,
            NEOWEAVER_MODEL_GM,
            NEOWEAVER_TOKENS_GM,
            0.85
        );

        if ( is_wp_error( $result ) ) {
            return [ 'error' => $result->get_error_message() ];
        }

        $this->save_to_history(
            $channel_id,
            $char_id,
            $campaign_id,
            $world_id,
            $message,
            $result['content']
        );

        $this->log_tokens( $char_id, $world_id, $result['usage'], $protocol );

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
    // SUPABASE HELPER
    // ============================================================
    private function supabase_request( string $method, string $endpoint, array $body = [], array $extra_headers = [] ): array|null {
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
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request(
            trailingslashit( tw_supabase_url() ) . ltrim( $endpoint, '/' ),
            $args
        );

        if ( is_wp_error( $response ) ) {
            error_log( '[NeoWeaver Claude] Supabase request failed: ' . $response->get_error_message() );
            return null;
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $decoded ) ? $decoded : null;
    }

    // ============================================================
    // HISTORY
    // ============================================================
    private function get_history( string $channel_id, string $char_id, ?string $campaign_id ): array {
        $endpoint =
            '/rest/v1/cyber_chat_messages'
            . '?channel_id=eq.' . urlencode( $channel_id )
            . '&char_id=eq.' . urlencode( $char_id )
            . '&order=created_at.desc'
            . '&limit=14'
            . '&select=message_type,content,campaign_id';

        if ( $campaign_id ) {
            $endpoint =
                '/rest/v1/cyber_chat_messages'
                . '?channel_id=eq.' . urlencode( $channel_id )
                . '&char_id=eq.' . urlencode( $char_id )
                . '&campaign_id=eq.' . urlencode( $campaign_id )
                . '&order=created_at.desc'
                . '&limit=14'
                . '&select=message_type,content';
        }

        $result = $this->supabase_request( 'GET', $endpoint );

        if ( empty( $result ) || ! is_array( $result ) ) {
            return [];
        }

        $result  = array_reverse( $result );
        $history = [];

        foreach ( $result as $row ) {
            $type = $row['message_type'] ?? '';
            $role = null;

            if ( $type === 'player' ) {
                $role = 'user';
            } elseif ( $type === 'gm' ) {
                $role = 'assistant';
            }

            if ( $role && ! empty( $row['content'] ) ) {
                $history[] = [
                    'role'    => $role,
                    'content' => $row['content'],
                ];
            }
        }

        return $history;
    }

    private function save_to_history(
        string $channel_id,
        string $char_id,
        ?string $campaign_id,
        ?string $world_id,
        string $player_message,
        string $gm_message
    ): void {
        $wp_user_id = get_current_user_id();

        if ( ! $wp_user_id ) {
            error_log( '[NeoWeaver Claude] Missing wp_user_id while saving chat history.' );
            return;
        }

        $rows = [
            [
                'channel_id'   => $channel_id,
                'campaign_id'  => $campaign_id,
                'char_id'      => $char_id,
                'wp_user_id'   => $wp_user_id,
                'message_type' => 'player',
                'content'      => $player_message,
                'created_at'   => gmdate( 'c' ),
                'is_ready'     => true,
                'meta'         => [
                    'world_id' => $world_id,
                    'source'   => 'claude_engine',
                ],
            ],
            [
                'channel_id'   => $channel_id,
                'campaign_id'  => $campaign_id,
                'char_id'      => $char_id,
                'wp_user_id'   => $wp_user_id,
                'message_type' => 'gm',
                'content'      => $gm_message,
                'created_at'   => gmdate( 'c' ),
                'is_ready'     => true,
                'meta'         => [
                    'world_id' => $world_id,
                    'source'   => 'claude_engine',
                ],
            ],
        ];

        $this->supabase_request(
            'POST',
            '/rest/v1/cyber_chat_messages',
            $rows,
            [ 'Prefer' => 'return=minimal' ]
        );
    }

    public function reset_history( string $channel_id, string $char_id, ?string $campaign_id = null ): void {
        $endpoint =
            '/rest/v1/cyber_chat_messages'
            . '?channel_id=eq.' . urlencode( $channel_id )
            . '&char_id=eq.' . urlencode( $char_id );

        if ( $campaign_id ) {
            $endpoint .= '&campaign_id=eq.' . urlencode( $campaign_id );
        }

        $this->supabase_request( 'DELETE', $endpoint );
    }

    // ============================================================
    // TOKENS
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
    // TAG PARSER
    // ============================================================
    private function parse_tags( string $raw ): array {
        $tags = [];
        $text = preg_replace_callback(
            '/#([A-Z][A-Z0-9_]+)(?::([a-zA-Z0-9_\\-]+))?/',
            function ( $m ) use ( &$tags ) {
                $tags[] = [
                    'tag' => $m[1],
                    'val' => $m[2] ?? null,
                ];
                return '';
            },
            $raw
        );

        return [
            'text' => trim( preg_replace( '/\\s+/', ' ', $text ) ),
            'tags' => $tags,
        ];
    }

    // ============================================================
    // META
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
