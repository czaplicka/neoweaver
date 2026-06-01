<?php
/**
 * NW_Chat_Claude
 *
 * Handles communication with the Claude API:
 * - builds the system prompt (character + location + memory context)
 * - manages conversation history (stored in Supabase, trimmed to a fixed window)
 * - calls the Claude client and logs tokens to cyber_token_ledger
 *
 * Required constants in wp-config.php:
 *   define( 'NW_CLAUDE_API_KEY', 'sk-ant-...' );
 *   define( 'NEOWEAVER_MODEL_GM', 'claude-sonnet-4-5-20251001' );
 *   define( 'NEOWEAVER_TOKENS_GM', 1024 );
 *
 * Optional:
 *   define( 'NW_GPT_HISTORY_LEN', 12 );  // number of past messages to load
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once dirname( __DIR__ ) . '/ai/class-neoweaver-claude-client.php';

class NW_Chat_Claude {

	// --------------------------------------------------------
	// CONFIG
	// --------------------------------------------------------

	private string $model;
	private int    $max_tokens;
	private int    $history_len;

	public function __construct() {
		$this->model       = defined( 'NEOWEAVER_MODEL_GM' )  ? NEOWEAVER_MODEL_GM  : 'claude-sonnet-4-5-20251001';
		$this->max_tokens  = defined( 'NEOWEAVER_TOKENS_GM' ) ? NEOWEAVER_TOKENS_GM : 1024;
		$this->history_len = defined( 'NW_GPT_HISTORY_LEN' )  ? NW_GPT_HISTORY_LEN  : 12;
	}

	// =========================================================
	// MAIN
	// =========================================================

	/**
	 * Sends the player message to Claude and returns the raw response.
	 *
	 * @param string $user_message Player message content.
	 * @param array  $context      Context data.
	 * @param string $protocol     Intent classification: TRAVEL|COMBAT|TRADE|DIALOG|LORE|REST|META
	 *
	 * Context keys:
	 *   char_id      string  Character UUID
	 *   world_id     string  World UUID
	 *   session_id   string  Session UUID (optional)
	 *   channel_id   string  Chat channel UUID
	 *   campaign_id  string  Campaign UUID (optional)
	 *   wp_user_id   int     WP user ID
	 *   char         array   Row from cyber_characters
	 *   location     array   Row from cyber_world_map
	 *   world        array   Row from cyber_worlds
	 *
	 * @return array {
	 *   'raw'   => string,
	 *   'error' => ?string,
	 *   'usage' => array{ input_tokens: int, output_tokens: int },
	 * }
	 */
	public function send( string $user_message, array $context, string $protocol = 'DIALOG' ): array {

		$channel_id = $context['channel_id'] ?? '';

		// 1. Build system prompt
		$system_prompt = $this->build_system_prompt( $context, $protocol );

		// 2. Load conversation history from Supabase
		$history = $this->get_history( $channel_id );

		// 3. Call Claude
		$api_result = $this->call_api( $system_prompt, $history, $user_message );

		if ( ! empty( $api_result['error'] ) ) {
			return $api_result;
		}

		// 4. Log token usage
		if ( ! empty( $api_result['usage'] ) ) {
			$this->log_tokens( $api_result['usage'], $context, $protocol );
		}

		return $api_result;
	}

	// =========================================================
	// SYSTEM PROMPT
	// =========================================================

	private function build_system_prompt( array $ctx, string $protocol ): string {
		$char     = $ctx['char']     ?? [];
		$location = $ctx['location'] ?? [];
		$world    = $ctx['world']    ?? [];

		// Block A: GM identity
		$block_a = <<<PROMPT
You are the Game Master (GM) in the NeoWeave world — a dark cyberpunk fantasy setting.
Reply as a narrator and GM in Polish, unless the player writes in English.
Archetype: EPIC | Style: noir, cyberpunk, immersive.
Rules:
- Keep narration to max 3 paragraphs, concise and atmospheric.
- Player decisions have real consequences.
- NEVER decide for the player. Describe the world and react to actions.
- At the end of the response, include a ---SYSTEM--- block with #MEMORY tags if something important happened.
  Block format: ---SYSTEM---\n#MEMORY:topic:content\n---END---
  Allowed topics: character, npc, location, faction, campaign, item, summary
PROMPT;

		// Block B: Dynamic world state
		//
		// BUG 23 FIX: cyber_characters stores HP as 'hp' (not 'current_hp' / 'max_hp')
		// and MP as 'mp'. The previous code read $char['current_hp'] / $char['max_hp']
		// which are always null/0 because those columns do not exist in the schema.
		// Correct column names: hp (current), max_hp does not exist — HP is a single
		// value; render as HP: {hp}. MP column is 'mp'.
		//
		// If your schema later adds a max_hp column, update the line below accordingly.
		$char_name  = esc_html( $char['name']              ?? 'Unknown' );
		$hp         = (int) ( $char['hp']                  ?? 0 );
		$mp         = (int) ( $char['mp']                  ?? 0 );
		$gold       = (int) ( $char['gold']                ?? 0 );
		$loc_name   = esc_html( $location['location_name'] ?? 'Unknown location' );
		$loc_tags   = esc_html( $location['instance_tags'] ?? '' );
		$loc_prompt = esc_html( $location['ai_prompt']     ?? '' );
		$entropy    = (int) ( $world['entropy']            ?? 0 );
		$w_tags     = esc_html( $world['globaltag1']       ?? '' );

		$block_b  = "AGENT: {$char_name} | HP: {$hp} | MP: {$mp} | Gold: {$gold}g\n";
		$block_b .= "LOCATION: {$loc_name} | TAGS: {$loc_tags}\n";
		if ( $loc_prompt ) $block_b .= "LOCATION CONTEXT: {$loc_prompt}\n";
		$block_b .= "WORLD: Entropy {$entropy}/100 | {$w_tags}";

		// Block C: Memory
		$block_c = '';
		if ( class_exists( 'NW_Memory_Parser' ) ) {
			$memory_parser = new NW_Memory_Parser();
			$block_c = $memory_parser->build_prompt_block(
				$ctx['char_id']  ?? '',
				$ctx['world_id'] ?? ''
			);
		} else {
			error_log( '[NW_Chat_Claude] NW_Memory_Parser class not found — memory block skipped.' );
		}

		// Block D: Protocol
		$block_d = "PROTOCOL: {$protocol}";

		$parts = array_filter( [ $block_a, $block_b, $block_c, $block_d ] );
		return implode( "\n\n", $parts );
	}

	// =========================================================
	// HISTORY
	// =========================================================

	/**
	 * Load recent conversation history for a channel.
	 *
	 * BUG 24 FIX: The previous implementation used tw_supabase_get() which
	 * sends only the anon key. cyber_chat_messages is protected by RLS policies
	 * that require auth.uid() — the anon key with no JWT is blocked, so history
	 * always returned []. We now use a direct wp_remote_get() with the service
	 * key (same pattern as log_tokens), which bypasses RLS on the server side.
	 * The service key is never sent to the browser.
	 *
	 * @param string $channel_id
	 * @return array  Ordered oldest-first, roles mapped to Claude format.
	 */
	private function get_history( string $channel_id ): array {
		if ( ! $channel_id ) {
			return [];
		}

		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_service_key' ) ) {
			error_log( '[NW_Chat_Claude] get_history: tw_supabase_url or tw_supabase_service_key not available.' );
			return [];
		}

		$service_key = tw_supabase_service_key();

		$url = add_query_arg(
			[
				'channel_id'   => 'eq.' . $channel_id,
				'message_type' => 'in.(player,gm)',
				'order'        => 'created_at.desc',
				'limit'        => (string) $this->history_len,
				'select'       => 'message_type,content',
			],
			trailingslashit( tw_supabase_url() ) . 'rest/v1/cyber_chat_messages'
		);

		$response = wp_remote_get( $url, [
			'headers' => [
				'apikey'        => $service_key,
				'Authorization' => 'Bearer ' . $service_key,
				'Content-Type'  => 'application/json',
			],
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) ) {
			error_log( '[NW_Chat_Claude] get_history wp_remote_get error: ' . $response->get_error_message() );
			return [];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			error_log( '[NW_Chat_Claude] get_history HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
			return [];
		}

		$rows = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		// Reverse to chronological order (oldest first) — Claude requires user first.
		// Map message_type → Claude role: player → user, gm → assistant.
		return array_map(
			function( $r ) {
				return [
					'role'    => ( $r['message_type'] === 'player' ) ? 'user' : 'assistant',
					'content' => $r['content'],
				];
			},
			array_reverse( $rows )
		);
	}

	// =========================================================
	// CLAUDE API
	// =========================================================

	private function call_api( string $system_prompt, array $history, string $user_message ): array {

		$messages = array_merge(
			$history,
			[ [ 'role' => 'user', 'content' => $user_message ] ]
		);

		$result = NeoWeaver_Claude_Client::call(
			$system_prompt,
			$messages,
			$this->model,
			$this->max_tokens,
			0.8
		);

		if ( is_wp_error( $result ) ) {
			return [ 'raw' => '', 'error' => $result->get_error_message(), 'usage' => [] ];
		}

		return [
			'raw'   => $result['content'],
			'error' => null,
			'usage' => $result['usage'],
		];
	}

	// =========================================================
	// TOKEN LOGGING
	// Claude usage keys:  input_tokens / output_tokens
	// Ledger column names: prompt_tokens / completion_tokens
	//
	// Używamy bezpośredniego wp_remote_post z service key zamiast
	// tw_supabase_request() (anon key) — cyber_token_ledger wymaga
	// uprawnień serwera, nie gracza.
	// =========================================================

	/**
	 * Write token usage to cyber_token_ledger.
	 *
	 * BUG 25 FIX: tw_supabase_service_key() was called twice inline —
	 * once for 'apikey' and once for 'Authorization'. Assigned to a
	 * local variable first to avoid the double function call.
	 *
	 * @param array  $usage
	 * @param array  $ctx
	 * @param string $protocol
	 */
	private function log_tokens( array $usage, array $ctx, string $protocol ): void {
		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_service_key' ) ) {
			return;
		}

		$service_key = tw_supabase_service_key();

		$url  = trailingslashit( tw_supabase_url() ) . 'rest/v1/cyber_token_ledger';
		$body = [
			'wp_user_id'        => $ctx['wp_user_id']  ?? null,
			'char_id'           => $ctx['char_id']     ?? null,
			'session_id'        => $ctx['session_id']  ?? null,
			'campaign_id'       => $ctx['campaign_id'] ?? null,
			'channel_id'        => $ctx['channel_id']  ?? null,
			'protocol'          => $protocol,
			'model'             => $this->model,
			'prompt_tokens'     => $usage['prompt_tokens']     ?? $usage['input_tokens']  ?? 0,
			'completion_tokens' => $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0,
		];

		$response = wp_remote_post( $url, [
			'headers' => [
				'apikey'        => $service_key,
				'Authorization' => 'Bearer ' . $service_key,
				'Content-Type'  => 'application/json',
				'Prefer'        => 'return=minimal',
			],
			'body'    => wp_json_encode( $body ),
			'timeout' => 5,
		] );

		if ( is_wp_error( $response ) ) {
			error_log( '[NeoWeaver NW_Chat_Claude] log_tokens failed: ' . $response->get_error_message() );
		}
	}
}
