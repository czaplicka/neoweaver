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
		// Field names match current schema:
		//   cyber_characters: current_hp, max_hp (snake_case)
		//   cyber_worlds:     globaltag1
		//   cyber_world_map:  location_name, instance_tags, ai_prompt
		$char_name  = esc_html( $char['name']              ?? 'Unknown' );
		$hp         = (int) ( $char['current_hp']          ?? 0 );
		$hp_max     = (int) ( $char['max_hp']              ?? 0 );
		$mp         = (int) ( $char['mp']                  ?? 0 );
		$gold       = (int) ( $char['gold']                ?? 0 );
		$loc_name   = esc_html( $location['location_name'] ?? 'Unknown location' );
		$loc_tags   = esc_html( $location['instance_tags'] ?? '' );
		$loc_prompt = esc_html( $location['ai_prompt']     ?? '' );
		$entropy    = (int) ( $world['entropy']            ?? 0 );
		$w_tags     = esc_html( $world['globaltag1']       ?? '' );

		$block_b  = "AGENT: {$char_name} | HP: {$hp}/{$hp_max} | MP: {$mp} | Gold: {$gold}g\n";
		$block_b .= "LOCATION: {$loc_name} | TAGS: {$loc_tags}\n";
		if ( $loc_prompt ) $block_b .= "LOCATION CONTEXT: {$loc_prompt}\n";
		$block_b .= "WORLD: Entropy {$entropy}/100 | {$w_tags}";

		// Block C: Memory
		$memory_parser = new NW_Memory_Parser();
		$block_c = $memory_parser->build_prompt_block(
			$ctx['char_id']  ?? '',
			$ctx['world_id'] ?? ''
		);

		// Block D: Protocol
		$block_d = "PROTOCOL: {$protocol}";

		$parts = array_filter( [ $block_a, $block_b, $block_c, $block_d ] );
		return implode( "\n\n", $parts );
	}

	// =========================================================
	// HISTORY
	// =========================================================

	private function get_history( string $channel_id ): array {
		if ( ! $channel_id || ! function_exists( 'tw_supabase_get' ) ) {
			return [];
		}

		// message_type stores 'player' and 'gm' — map to Claude roles after fetch.
		// The column is message_type, not role.
		$rows = tw_supabase_get( 'cyber_chat_messages', [
			'channel_id'   => 'eq.' . $channel_id,
			'message_type' => 'in.(player,gm)',
			'order'        => 'created_at.desc',
			'limit'        => $this->history_len,
			'select'       => 'message_type,content',
		] );

		if ( is_wp_error( $rows ) || ! is_array( $rows ) ) {
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
	private function log_tokens( array $usage, array $ctx, string $protocol ): void {
		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_service_key' ) ) {
			return;
		}

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
				'apikey'        => tw_supabase_service_key(),
				'Authorization' => 'Bearer ' . tw_supabase_service_key(),
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
