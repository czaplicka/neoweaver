<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/class-neoweaver-intent-router.php';
require_once __DIR__ . '/class-neoweaver-context-builder.php';
require_once __DIR__ . '/class-neoweaver-claude-client.php';
require_once dirname(__DIR__) . '/supabase-config.php';

// ── Fallback constants (in case wp-config.php is missing them) ──────────────
if ( ! defined( 'NEOWEAVER_MODEL_GM' ) ) {
    define( 'NEOWEAVER_MODEL_GM', 'claude-sonnet-4-5-20251001' );
}
if ( ! defined( 'NEOWEAVER_TOKENS_GM' ) ) {
    define( 'NEOWEAVER_TOKENS_GM', 1024 );
}

/**
 * NeoWeaver Claude Engine
 * Uses Anthropic Claude API via NeoWeaver_Claude_Client.
 *
 * Dwie metody wejścia:
 *
 * process( $char_id, $message )
 *   — użyj gdy engine ma sam budować kontekst i pobierać historię po char_id.
 *   — zapisuje historię do cyber_chat_messages po char_id.
 *
 * process_with_context( $context, $history, $message )
 *   — użyj gdy kontekst i historia są już zbudowane zewnętrznie (np. rest-ai-chat.php).
 *   — NIE zapisuje historii — zapis leży po stronie wywołującego.
 *   — Historia pobierana per channel_id, więc każdy kanał czatu ma swoją historię.
 *
 * Historia przechowywana w Supabase (cyber_chat_messages):
 *   message_type = 'player' | 'gm'
 *   Mapowane na 'user' | 'assistant' tylko przy przekazaniu do Claude API.
 *
 * wp-config.php constants:
 *  - NEOWEAVER_ANTHROPIC_API_KEY
 *  - NEOWEAVER_MODEL_GM          (np. claude-sonnet-4-5)
 *  - NEOWEAVER_TOKENS_GM         (600)
 *  - TW_SUPABASE_PROJECT_ID
 *  - TW_SUPABASE_SERVICE_KEY
 */
class NeoWeaver_Claude_Engine {

    private const SYSTEM_INSTRUCTIONS = <<<PROMPT
You are the AI Game Master of NeoWeave — a dark, narrative RPG.
Rules: Respond in character as the world. Keep answers under 120 words unless combat demands more.
Embed system tags in your response using syntax #NW_TAG or #NW_TAG:value
(e.g. #NW_ENTROPY_UP:5, #NW_LOC:42, #NW_STATUS_POISONED, #NW_HP_CHANGE:-10, #NW_COMBAT_START, #NW_GOLD_CHANGE:-5).
All system tags MUST start with NW_ prefix. Tags are parsed by the system — the player never sees them.
Never explain tags to the player. Never use #NW_ prefix in regular narrative text.
Respond in the same language the player uses.
PROMPT;

    /**
     * Whitelist of known system tag names (without the #NW_ prefix).
     * Only these tags will be stripped from narrative text and returned as parsed tags.
     * Any other #NW_WORD pattern is left untouched in the text.
     */
    private const KNOWN_TAGS = [
        'ENTROPY_UP', 'ENTROPY_DOWN',
        'LOC',
        'STATUS_POISONED', 'STATUS_STUNNED', 'STATUS_BLESSED', 'STATUS_CURSED',
        'STATUS_CLEAR',
        'HP_CHANGE',
        'MP_CHANGE',
        'GOLD_CHANGE',
        'XP_CHANGE',
        'COMBAT_START', 'COMBAT_END',
        'ITEM_GAINED', 'ITEM_LOST',
        'HUD_REFRESH',
        'SCENE_CHANGE',
        'CARD_DRAW',
    ];

    private NeoWeaver_Context_Builder $context;

    public function __construct() {
        $this->context = new NeoWeaver_Context_Builder();
    }

    // ============================================================
    // PUBLIC: process() — engine sam buduje kontekst i historię
    // Wywołuj z miejsc, gdzie nie masz jeszcze kontekstu.
    // Zapisuje historię per char_id.
    // Zwraca: ['text'=>'...', 'tags'=>[...], 'protocol'=>'...', 'tokens'=>[...]]
    // ============================================================
    public function process( string $char_id, string $message ): array {

        $protocol = NeoWeaver_Intent_Router::classify( $message );

        if ( $protocol === 'META' ) {
            return $this->handle_meta( $char_id );
        }

        $ctx             = $this->context->build( $char_id, $protocol );
        $dynamic_context = $ctx['block_b'] . "\n\n---\n\n" . $ctx['block_c'];

        $history   = $this->get_history( $char_id );
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

        $this->save_to_history( $char_id, $message, $result['content'] );
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
    // PUBLIC: process_with_context() — kontekst i historia z zewnątrz
    // Używa rest-ai-chat.php, który:
    //   — sam buduje $context przez tw_rest_ai_build_context()
    //   — sam pobiera $history przez tw_rest_ai_get_history() per channel_id
    //   — sam zapisuje wiadomości do cyber_chat_messages po rozmowie
    // Engine tutaj TYLKO: buduje prompt → wywołuje Claude → parsuje tagi.
    // Zwraca: ['text'=>'...', 'tags'=>[...], 'tokens'=>[...]] lub ['error'=>'...']
    // ============================================================
    public function process_with_context( array $context, array $history, string $message ): array {

        $protocol = $context['protocol'] ?? 'NARRATE';
        $extra    = $context['extra']    ?? '';
        $char     = $context['char']     ?? [];
        $location = $context['location'] ?? [];
        $world    = $context['world']    ?? [];
        $world_id = $world['id']         ?? null;

        $char_id = trim( $char['id'] ?? '' );
        if ( $char_id === '' ) {
            error_log( '[NeoWeaver Engine] process_with_context called without char.id' );
            return [ 'error' => 'Missing character ID — cannot process message.' ];
        }

        $dynamic_context = $this->build_context_block( $char, $location, $world, $extra );

        // Ensure history passed from outside also starts with user role.
        $history = $this->ensure_user_first( $history );

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

        $this->log_tokens( $char_id, $world_id, $result['usage'], $protocol );

        $parsed = $this->parse_tags( $result['content'] );

        return [
            'text'   => $parsed['text'],
            'tags'   => $parsed['tags'],
            'tokens' => [
                'prompt'     => $result['usage']['input_tokens']  ?? 0,
                'completion' => $result['usage']['output_tokens'] ?? 0,
            ],
        ];
    }

    // ============================================================
    // Buduje blok kontekstu gry z tablic danych (dla process_with_context)
    // ============================================================
    private function build_context_block( array $char, array $location, array $world, string $extra ): string {
        $lines = [];

        if ( ! empty( $char['name'] ) )             { $lines[] = 'CHAR: '       . $char['name']; }
        if ( isset( $char['currenthp'] ) )          { $lines[] = 'HP: '         . $char['currenthp'] . '/' . ( $char['maxhp'] ?? '?' ); }
        if ( isset( $char['gold'] ) )               { $lines[] = 'GOLD: '       . $char['gold']; }
        if ( isset( $char['satiety'] ) )            { $lines[] = 'SATIETY: '    . $char['satiety']; }
        if ( isset( $char['mp'] ) )                 { $lines[] = 'MP: '         . $char['mp']; }
        if ( ! empty( $char['echo_tags'] ) )        { $lines[] = 'ECHO_TAGS: '  . ( is_array( $char['echo_tags'] ) ? implode( ',', $char['echo_tags'] ) : $char['echo_tags'] ); }
        if ( ! empty( $location['locationname'] ) ) { $lines[] = 'LOCATION: '   . $location['locationname']; }
        if ( ! empty( $location['aiprompt'] ) )     { $lines[] = 'LOC_DESC: '   . $location['aiprompt']; }
        if ( ! empty( $location['instancetags'] ) ) { $lines[] = 'LOC_TAGS: '   . ( is_array( $location['instancetags'] ) ? implode( ',', $location['instancetags'] ) : $location['instancetags'] ); }
        if ( isset( $location['threatlevel'] ) )    { $lines[] = 'THREAT: '     . $location['threatlevel']; }
        if ( ! empty( $world['worldname'] ) )       { $lines[] = 'WORLD: '      . $world['worldname']; }
        if ( isset( $world['entropy'] ) )           { $lines[] = 'ENTROPY: '    . $world['entropy']; }
        if ( ! empty( $world['archetype'] ) )       { $lines[] = 'ARCHETYPE: '  . $world['archetype']; }
        if ( $extra !== '' )                        { $lines[] = $extra; }

        return implode( "\n", $lines );
    }

    // ============================================================
    // Pomocniczy helper Supabase REST
    // GET: endpoint = '/rest/v1/table', params = ['col' => 'eq.val', ...]
    // POST/DELETE: endpoint = '/rest/v1/table', body = [...]
    // ============================================================
    private function supabase_request( string $method, string $endpoint, array $body = [], array $extra_headers = [], array $params = [] ): ?array {
        $base_url = trailingslashit( tw_supabase_url() ) . ltrim( $endpoint, '/' );

        // For GET requests build the query string safely via add_query_arg.
        // Never concatenate raw query strings to avoid double-encoding.
        if ( $method === 'GET' && ! empty( $params ) ) {
            $base_url = add_query_arg( array_map( 'rawurlencode', $params ), $base_url );
        }

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

        $response = wp_remote_request( $base_url, $args );

        if ( is_wp_error( $response ) ) {
            error_log( '[NeoWeaver Claude] Supabase request failed: ' . $response->get_error_message() );
            return null;
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $decoded ) ? $decoded : null;
    }

    // ============================================================
    // Historia konwersacji — cyber_chat_messages
    // Zwraca historię zawsze zaczynającą się od roli 'user'.
    // Claude API wymaga: first message role = user.
    // ============================================================
    private function get_history( string $char_id ): array {
        $result = $this->supabase_request(
            'GET',
            '/rest/v1/cyber_chat_messages',
            [],
            [],
            [
                'char_id' => 'eq.' . $char_id,
                'select'  => 'message_type,content',
                'order'   => 'created_at.desc',
                'limit'   => '14',
            ]
        );

        if ( empty( $result ) ) {
            return [];
        }

        $result = array_reverse( $result );

        $history = array_map( function ( $row ) {
            $role = ( ( $row['message_type'] ?? '' ) === 'player' ) ? 'user' : 'assistant';
            return [
                'role'    => $role,
                'content' => $row['content'] ?? '',
            ];
        }, $result );

        return $this->ensure_user_first( $history );
    }

    /**
     * Drops leading assistant messages so the history always starts with role=user.
     * Claude API returns 400 if the first message is not role=user.
     */
    private function ensure_user_first( array $history ): array {
        while ( ! empty( $history ) && ( $history[0]['role'] ?? '' ) !== 'user' ) {
            array_shift( $history );
        }
        return array_values( $history );
    }

    private function save_to_history( string $char_id, string $user_message, string $gm_message ): void {
        $this->supabase_request(
            'POST',
            '/rest/v1/cyber_chat_messages',
            [
                'char_id'      => $char_id,
                'message_type' => 'player',
                'content'      => $user_message,
                'created_at'   => gmdate( 'c' ),
            ],
            [ 'Prefer' => 'return=minimal' ]
        );

        $this->supabase_request(
            'POST',
            '/rest/v1/cyber_chat_messages',
            [
                'char_id'      => $char_id,
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
    // Parser tagów systemowych (#NW_TAG lub #NW_TAG:value)
    //
    // Tylko tagi z whitelisty KNOWN_TAGS są wycinane z tekstu.
    // Inne #NW_XXXX lub dowolne #HASH są pozostawiane w tekście
    // bez zmian — nie znikają cięto narracji.
    //
    // System prompt instruuje Claude używania prefiksu NW_,
    // więc kolizja z hashtagami narracyjnymi (#NYC, #AI) jest
    // niemożliwa w prawidłowych odpowiedziach GM.
    // ============================================================
    private function parse_tags( string $raw ): array {
        $known   = implode( '|', array_map( 'preg_quote', self::KNOWN_TAGS, array_fill( 0, count( self::KNOWN_TAGS ), '/' ) ) );
        $pattern = '/#NW_(' . $known . ')(?::([a-zA-Z0-9_\-]+))?(?=[\s,\.!?]|$)/';

        $tags = [];
        $text = preg_replace_callback(
            $pattern,
            function ( $m ) use ( &$tags ) {
                $tags[] = [ 'tag' => $m[1], 'val' => $m[2] ?? null ];
                return '';
            },
            $raw
        );

        return [
            'text' => trim( preg_replace( '/  +/', ' ', $text ) ),
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
