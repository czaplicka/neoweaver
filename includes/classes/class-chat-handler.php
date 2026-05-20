<?php
/**
 * NW_Chat_Handler
 *
 * Łączy wszystkie klasy i wystawia jeden AJAX endpoint:
 *   wp_ajax_nw_chat_message        (zalogowani)
 *   wp_ajax_nopriv_nw_chat_message (niezalogowani — opcjonalne)
 *
 * Flow:
 *   1. Przyjmij + zwaliduj dane od gracza
 *   2. Zapisz wiadomość gracza do cyber_chat_messages
 *   3. Pobierz kontekst (postać, lokacja, świat) z Supabase
 *   4. Zaklasyfikuj intencję (protokół)
 *   5. Wyślij do GPT przez NW_Chat_GPT
 *   6. Parsuj odpowiedź przez NW_Memory_Parser
 *   7. Zapisz odpowiedź GM-a do cyber_chat_messages
 *   8. Zwróć JSON do frontendu
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NW_Chat_Handler {

	public function __construct() {
		add_action( 'wp_ajax_nw_chat_message',        [ $this, 'handle' ] );
		// Odkomentuj poniżej jeśli chcesz obsługiwać niezalogowanych:
		// add_action( 'wp_ajax_nopriv_nw_chat_message', [ $this, 'handle' ] );
	}

	// =========================================================
	// GŁÓWNY HANDLER AJAX
	// =========================================================

	public function handle(): void {
		// 1. Weryfikacja nonce
		if ( ! check_ajax_referer( 'nw_chat_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Nieprawidłowy token bezpieczeństwa.' ], 403 );
		}

		// 2. Walidacja danych wejściowych
		$user_message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$char_id      = sanitize_text_field( $_POST['char_id']      ?? '' );
		$channel_id   = sanitize_text_field( $_POST['channel_id']   ?? '' );
		$world_id     = sanitize_text_field( $_POST['world_id']     ?? '' );
		$session_id   = sanitize_text_field( $_POST['session_id']   ?? '' );
		$campaign_id  = sanitize_text_field( $_POST['campaign_id']  ?? '' );

		if ( empty( $user_message ) || empty( $char_id ) || empty( $channel_id ) ) {
			wp_send_json_error( [ 'message' => 'Brak wymaganych pól.' ], 400 );
		}

		$wp_user_id = get_current_user_id();

		// 3. Zapisz wiadomość gracza do Supabase
		$this->save_message( [
			'channel_id' => $channel_id,
			'char_id'    => $char_id,
			'role'       => 'user',
			'content'    => $user_message,
			'meta'       => null,
		] );

		// 4. Pobierz kontekst
		$context = $this->build_context( $char_id, $world_id, $channel_id, $session_id, $campaign_id, $wp_user_id );

		if ( isset( $context['error'] ) ) {
			wp_send_json_error( [ 'message' => 'Błąd pobierania kontekstu: ' . $context['error'] ], 500 );
		}

		// 5. Klasyfikuj intencję
		$protocol = $this->classify_intent( $user_message );

		// 6. Wywołaj GPT
		$gpt     = new NW_Chat_GPT();
		$gpt_res = $gpt->send( $user_message, $context, $protocol );

		if ( ! empty( $gpt_res['error'] ) ) {
			wp_send_json_error( [ 'message' => 'Błąd GPT: ' . $gpt_res['error'] ], 502 );
		}

		// 7. Parsuj odpowiedź
		$parser = new NW_Memory_Parser();
		$parsed = $parser->parse( $gpt_res['raw'], $char_id, $world_id, $session_id );

		// 8. Zapisz odpowiedź GM-a
		$this->save_message( [
			'channel_id' => $channel_id,
			'char_id'    => $char_id,
			'role'       => 'assistant',
			'content'    => $parsed['clean_text'],
			'meta'       => [
				'protocol' => $protocol,
				'tags'     => $parsed['tags'],
				'usage'    => $gpt_res['usage'],
			],
		] );

		// 9. Odpowiedź do JS
		wp_send_json_success( [
			'reply'    => $parsed['clean_text'],
			'tags'     => $parsed['tags'],
			'memories' => count( $parsed['memories'] ),
			'usage'    => $gpt_res['usage'],
			'protocol' => $protocol,
		] );
	}

	// =========================================================
	// KLASYFIKATOR INTENCJI
	// =========================================================

	private function classify_intent( string $message ): string {
		$msg = mb_strtolower( $message );

		$patterns = [
			'COMBAT'  => '/\b(ataku|atak|fight|attack|walcz|combat|uderz|strzel|rzuc zaklec|cast)/',
			'TRAVEL'  => '/\b(idę|ide|go|move|travel|porusz|kieruj|przesuń|north|south|east|west|pnoc|poludnie|wschod|zachod)/',
			'TRADE'   => '/\b(kupuję|kup|sprzedaj|trade|buy|sell|handel|cena|ile kosztuje|want to buy|sklep|merchant)/',
			'REST'    => '/\b(odpoczywa|odpocz|rest|sleep|śpij|nocleg|rozbij oboz|camp)/',
			'LORE'    => '/\b(co wiem|opowiedz|historia|lore|kim jest|what is|tell me|gossip|plotki|legenda)/',
			'META'    => '/\b(ile mam|status|hp|mp|gold|statystyk|ekwip|inventory|show stats)/',
		];

		foreach ( $patterns as $protocol => $regex ) {
			if ( preg_match( $regex, $msg ) ) {
				return $protocol;
			}
		}

		return 'DIALOG'; // domyślny protokół
	}

	// =========================================================
	// BUDOWANIE KONTEKSTU
	// =========================================================

	private function build_context( string $char_id, string $world_id, string $channel_id, string $session_id, string $campaign_id, int $wp_user_id ): array {
		if ( ! function_exists( 'tw_supabase_get' ) ) {
			return [ 'error' => 'tw_supabase_get niedostępne' ];
		}

		// Postać
		$char_rows = tw_supabase_get( 'cyber_characters', [
			'id'     => 'eq.' . sanitize_text_field( $char_id ),
			'select' => 'id,name,currenthp,maxhp,mp,gold,locationid,echo_tags,satiety,hydration',
			'limit'  => 1,
		] );
		$char = ( is_array( $char_rows ) && ! empty( $char_rows[0] ) ) ? $char_rows[0] : [];

		// Lokacja
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

		// Świat
		$world = [];
		if ( $world_id ) {
			$world_rows = tw_supabase_get( 'cyber_worlds', [
				'id'     => 'eq.' . sanitize_text_field( $world_id ),
				'select' => 'id,entropy,globaltag1,globaltag2,globaltag3,difficulty',
				'limit'  => 1,
			] );
			$world = ( is_array( $world_rows ) && ! empty( $world_rows[0] ) ) ? $world_rows[0] : [];
		}

		return compact( 'char', 'location', 'world', 'char_id', 'world_id', 'channel_id', 'session_id', 'campaign_id', 'wp_user_id' );
	}

	// =========================================================
	// ZAPIS WIADOMOŚCI DO cyber_chat_messages
	// =========================================================

	private function save_message( array $data ): void {
		if ( ! function_exists( 'tw_supabase_request' ) ) return;

		$key = function_exists( 'tw_supabase_service_key' ) ? tw_supabase_service_key() : '';

		$payload = [
			'channel_id' => $data['channel_id'],
			'char_id'    => $data['char_id']  ?? null,
			'role'       => $data['role'],
			'content'    => $data['content'],
			'meta'       => ! empty( $data['meta'] ) ? $data['meta'] : null,
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
