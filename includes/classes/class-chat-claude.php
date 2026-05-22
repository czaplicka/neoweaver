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

require_once __DIR__ . '/class-neoweaver-claude-client.php';

class NW_Chat_Claude {

	// --------------------------------------------------------
	// CONFIG
	// --------------------------------------------------------

	private string $model;
	private int    $max_tokens;
	private int    $history_len;

	public function __construct() {
		$this->model       = defined( 'NEOWEAVER_MODEL_GM' )    ? NEOWEAVER_MODEL_GM    : 'claude-sonnet-4-5-20251001';
		$this->max_tokens  = defined( 'NEOWEAVER_TOKENS_GM' )   ? NEOWEAVER_TOKENS_GM   : 1024;
		$this->history_len = defined( 'NW_GPT_HISTORY_LEN' )    ? NW_GPT_HISTORY_LEN    : 12;
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
	 *   location     array   Row from cyber_worldmap
	 *   world        array   Row from cyber_worlds
	 *
	 * @return array {
	 *   'raw'   => string,
	 *   'error' => ?string,
	 *   'usage' => array{ input_tokens: int, output_tokens: int },
	 * }
	 */
	public function send( string $user_message, array $context, string $protocol = 'DIALOG' ): array {

		$char_id    = $context['char_id']    ?? '';
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
		$char_name  = esc_html( $char['name']             ?? 'Unknown' );
		$hp         = (int) ( $char['currenthp']          ?? 0 );
		$hp_max     = (int) ( $char['maxhp']              ?? 0 );
		$mp         = (int) ( $char['mp']                 ?? 0 );
		$gold       = (int) ( $char['gold']               ?? 0 );
		$loc_name   = esc_html( $location['locationname'] ?? 'Unknown location' );
		$loc_tags   = esc_html( $location['instancetags'] ?? '' );
		$loc_prompt = esc_html( $location['aiprompt']     ?? '' );
		$entropy    = (int) ( $world['entropy']           ?? 0 );
		$w_tags     = esc_html( $world['globaltag1']      ?? '' );

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

		$rows = tw_supabase_get( 'cyber_chat_messages', [
			'channel_id' => 'eq.' . $channel_id,
			'role'       => 'in.(user,assistant)',
			'order'      => 'created_at.desc',
			'limit'      => $this->history_len,
			'select'     => 'role,content',
		] );

		if ( is_wp_error( $rows ) || ! is_array( $rows ) ) {
			return [];
		}

		// Reverse so messages are in chronological order (oldest first)
		$reversed = array_reverse( $rows );

		return array_map( fn( $r ) => [
			'role'    => $r['role'],
			'content' => $r['content'],
		], $reversed );
	}

	// =========================================================
	// CLAUDE API
	// =========================================================

	private function call_api( string $system_prompt, array $history, string $user_message ): array {

		$messages = array_merge(
			$history,
			[
				[ 'role' => 'user', 'content' => $user_message ],
			]
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
			'usage' => $result['usage'],  // ['input_tokens' => int, 'output_tokens' => int]
		];
	}

	// =========================================================
	// TOKEN LOGGING
	// input_tokens / output_tokens = Claude API naming convention
	// prompt_tokens / completion_tokens = column names in cyber_token_ledger
	// =========================================================

	private function log_tokens( array $usage, array $ctx, string $protocol ): void {
		if ( ! function_exists( 'tw_supabase_request' ) ) return;

		$key = function_exists( 'tw_supabase_service_key' ) ? tw_supabase_service_key() : '';

		tw_supabase_request(
			'POST',
			'cyber_token_ledger',
			[],
			[
				'wp_user_id'        => $ctx['wp_user_id']  ?? null,
				'char_id'           => $ctx['char_id']     ?? null,
				'session_id'        => $ctx['session_id']  ?? null,
				'campaign_id'       => $ctx['campaign_id'] ?? null,
				'channel_id'        => $ctx['channel_id']  ?? null,
				'protocol'          => $protocol,
				'model'             => $this->model,
				'prompt_tokens'     => $usage['input_tokens']  ?? 0,  // Claude → ledger mapping
				'completion_tokens' => $usage['output_tokens'] ?? 0,  // Claude → ledger mapping
			],
			[
				'headers' => [
					'apikey'        => $key,
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
					'Prefer'        => 'return=minimal',
				],
			]
		);
	}
}
