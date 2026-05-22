<?php
/**
 * NW_Chat_Handler
 *
 * Connects all chat-related classes and exposes one AJAX endpoint:
 *   wp_ajax_nw_chat_message
 *
 * Flow:
 *   1. Accept and validate player input
 *   2. Save the player message to cyber_chat_messages
 *   3. Load gameplay context (character, location, world) from Supabase
 *   4. Classify intent (protocol)
 *   5. Send the message to Claude via NW_Chat_Claude
 *   6. Parse the response with NW_Memory_Parser
 *   7. Save the GM response to cyber_chat_messages
 *   8. Return JSON to the frontend
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NW_Chat_Handler {

	public function __construct() {
		add_action( 'wp_ajax_nw_chat_message', [ $this, 'handle' ] );
		// Uncomment if you want guest access:
		// add_action( 'wp_ajax_nopriv_nw_chat_message', [ $this, 'handle' ] );
	}

	// =========================================================
	// MAIN AJAX HANDLER
	// =========================================================

	public function handle(): void {

		if ( ! check_ajax_referer( 'nw_chat_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Nieprawidłowy token bezpieczeństwa.' ], 403 );
		}

		$user_message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$char_id      = sanitize_text_field( $_POST['char_id'] ?? '' );
		$channel_id   = sanitize_text_field( $_POST['channel_id'] ?? '' );
		$world_id     = sanitize_text_field( $_POST['world_id'] ?? '' );
		$session_id   = sanitize_text_field( $_POST['session_id'] ?? '' );
		$campaign_id  = sanitize_text_field( $_POST['campaign_id'] ?? '' );

		if ( empty( $user_message ) || empty( $char_id ) || empty( $channel_id ) ) {
			wp_send_json_error( [ 'message' => 'Brak wymaganych pól.' ], 400 );
		}

		$wp_user_id = get_current_user_id();

		if ( ! $wp_user_id ) {
			wp_send_json_error( [ 'message' => 'Musisz być zalogowana_y.' ], 401 );
		}

		// 1. Save player message first
		$this->save_message( [
			'channel_id'   => $channel_id,
			'campaign_id'  => $campaign_id ?: null,
			'char_id'      => $char_id,
			'wp_user_id'   => $wp_user_id,
			'message_type' => 'player',
			'content'      => $user_message,
			'is_ready'     => true,
			'meta'         => null,
		] );

		// 2. Build runtime context
		$context = $this->build_context(
			$char_id,
			$world_id,
			$channel_id,
			$session_id,
			$campaign_id,
			$wp_user_id
		);

		if ( isset( $context['error'] ) ) {
			wp_send_json_error( [ 'message' => 'Błąd pobierania kontekstu: ' . $context['error'] ], 500 );
		}

		// 3. Classify intent
		$protocol = $this->classify_intent( $user_message );

		// 4. Send to Claude
		$chat     = new NW_Chat_Claude();
		$chat_res = $chat->send( $user_message, $context, $protocol );

		if ( ! empty( $chat_res['error'] ) ) {
			wp_send_json_error( [ 'message' => 'Błąd Claude: ' . $chat_res['error'] ], 502 );
		}

		// 5. Parse response
		$parser = new NW_Memory_Parser();
		$parsed = $parser->parse(
			$chat_res['raw'],
			$char_id,
			$world_id,
			$session_id
		);

		// 6. Save GM response
		$this->save_message( [
			'channel_id'   => $channel_id,
			'campaign_id'  => $campaign_id ?: null,
			'char_id'      => $char_id,
			'wp_user_id'   => $wp_user_id,
			'message_type' => 'gm',
			'content'      => $parsed['clean_text'],
			'is_ready'     => true,
			'meta'         => [
				'protocol' => $protocol,
				'tags'     => $parsed['tags'] ?? [],
				'usage'    => $chat_res['usage'] ?? [],
				'model'    => defined( 'NW_GPT_MODEL' ) ? NW_GPT_MODEL : 'claude-sonnet-4-5-20251001',
				'source'   => 'nw_chat_handler',
			],
		] );

		// 7. Return response to frontend
		wp_send_json_success( [
			'reply'    => $parsed['clean_text'],
			'tags'     => $parsed['tags'] ?? [],
			'memories' => count( $parsed['memories'] ?? [] ),
			'usage'    => $chat_res['usage'] ?? [],
			'protocol' => $protocol,
		] );
	}

	// =========================================================
	// INTENT CLASSIFICATION
	// =========================================================

	private function classify_intent( string $message ): string {
		$msg = mb_strtolower( $message );

		$patterns = [
			'COMBAT' => '/\\b(ataku|atak|fight|attack|walcz|combat|uderz|strzel|rzuc zaklec|cast)/',
			'TRAVEL' => '/\\b(idę|ide|go|move|travel|porusz|kieruj|przesuń|north|south|east|west|pnoc|poludnie|wschod|zachod)/',
			'TRADE'  => '/\\b(kupuję|kup|sprzedaj|trade|buy|sell|handel|cena|ile kosztuje|want to buy|sklep|merchant)/',
			'REST'   => '/\\b(odpoczywa|odpocz|rest|sleep|śpij|nocleg|rozbij oboz|camp)/',
			'LORE'   => '/\\b(co wiem|opowiedz|historia|lore|kim jest|what is|tell me|gossip|plotki|legenda)/',
			'META'   => '/\\b(ile mam|status|hp|mp|gold|statystyk|ekwip|inventory|show stats)/',
		];

		foreach ( $patterns as $protocol => $regex ) {
			if ( preg_match( $regex, $msg ) ) {
				return $protocol;
			}
		}

		return 'DIALOG';
	}

	// =========================================================
	// CONTEXT
	// =========================================================

	private function build_context(
		string $char_id,
		string $world_id,
		string $channel_id,
		string $session_id,
		string $campaign_id,
		int $wp_user_id
	): array {
		if ( ! function_exists( 'tw_supabase_get' ) ) {
			return [ 'error' => 'tw_supabase_get niedostępne' ];
		}

		$char_rows = tw_supabase_get( 'cyber_characters', [
			'id'     => 'eq.' . sanitize_text_field( $char_id ),
			'select' => 'id,name,currenthp,maxhp,mp,gold,locationid,echo_tags,satiety,hydration',
			'limit'  => 1,
		] );
		$char = ( is_array( $char_rows ) && ! empty( $char_rows[0] ) ) ? $char_rows[0] : [];

		$loc_id   = $char['locationid'] ?? '';
		$location = [];

		if ( $loc_id ) {
			$loc_rows = tw_supabase_get( 'cyber_worldmap', [
				'id'     => 'eq.' . sanitize_text_field( $loc_id ),
				'select' => 'id,locationname,instancetags,aiprompt,threatlevel',
				'limit'  => 1,
			] );
			$location = ( is_array( $loc_rows ) && ! empty( $loc_rows[0] ) ) ? $loc_rows[0] : [];
		}

		$world = [];
		if ( $world_id ) {
			$world_rows = tw_supabase_get( 'cyber_worlds', [
				'id'     => 'eq.' . sanitize_text_field( $world_id ),
				'select' => 'id,entropy,globaltag1,globaltag2,globaltag3,difficulty',
				'limit'  => 1,
			] );
			$world = ( is_array( $world_rows ) && ! empty( $world_rows[0] ) ) ? $world_rows[0] : [];
		}

		return compact(
			'char',
			'location',
			'world',
			'char_id',
			'world_id',
			'channel_id',
			'session_id',
			'campaign_id',
			'wp_user_id'
		);
	}

	// =========================================================
	// SAVE MESSAGE
	// =========================================================

	private function save_message( array $data ): void {
		if ( ! function_exists( 'tw_supabase_request' ) ) return;

		$key = function_exists( 'tw_supabase_service_key' ) ? tw_supabase_service_key() : '';

		$payload = [
			'channel_id'   => $data['channel_id'] ?? null,
			'campaign_id'  => $data['campaign_id'] ?? null,
			'char_id'      => $data['char_id'] ?? null,
			'wp_user_id'   => $data['wp_user_id'] ?? null,
			'message_type' => $data['message_type'] ?? 'player',
			'content'      => $data['content'] ?? '',
			'is_ready'     => isset( $data['is_ready'] ) ? (bool) $data['is_ready'] : true,
			'meta'         => ! empty( $data['meta'] ) ? $data['meta'] : null,
		];

		tw_supabase_request(
			'POST',
			'cyber_chat_messages',
			[],
			$payload,
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
