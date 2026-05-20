<?php
/**
 * NW_Chat_GPT
 *
 * Obsługuje całą komunikację z OpenAI Chat Completions API:
 *   - buduje prompt systemowy (kontekst postaci + lokacja + memory)
 *   - zarządza historią konwersacji (przechowuje w Supabase, przycina okno)
 *   - wywołuje API i loguje tokeny do cyber_token_ledger
 *
 * Wymagane stałe w wp-config.php:
 *   define( 'NW_OPENAI_API_KEY', 'sk-...' );
 *   define( 'NW_SUPABASE_URL',   'https://xxx.supabase.co' );   // już używane w pluginie
 *   define( 'NW_SUPABASE_SERVICE_KEY', 'service_role_key' );    // już używane w pluginie
 *
 * Opcjonalne:
 *   define( 'NW_GPT_MODEL',       'gpt-4o' );          // domyślnie gpt-4o
 *   define( 'NW_GPT_MAX_TOKENS',  600 );               // domyślnie 600
 *   define( 'NW_GPT_HISTORY_LEN', 12 );                // ile wiadomości historii (domyślnie 12)
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NW_Chat_GPT {

	// --------------------------------------------------------
	// KONFIGURACJA
	// --------------------------------------------------------

	private string $model;
	private int    $max_tokens;
	private int    $history_len;

	public function __construct() {
		$this->model       = defined( 'NW_GPT_MODEL' )       ? NW_GPT_MODEL       : 'gpt-4o';
		$this->max_tokens  = defined( 'NW_GPT_MAX_TOKENS' )  ? NW_GPT_MAX_TOKENS  : 600;
		$this->history_len = defined( 'NW_GPT_HISTORY_LEN' ) ? NW_GPT_HISTORY_LEN : 12;
	}

	// =========================================================
	// GŁÓWNA METODA
	// =========================================================

	/**
	 * Wyślij wiadomość gracza do GPT i zwróć surową odpowiedź.
	 *
	 * @param string $user_message   Treść wiadomości gracza
	 * @param array  $context        Dane kontekstu — patrz niżej
	 * @param string $protocol       Klasyfikacja intencji: TRAVEL|COMBAT|TRADE|DIALOG|LORE|REST|META
	 *
	 * Context keys:
	 *   char_id      string  UUID postaci
	 *   world_id     string  UUID świata
	 *   session_id   string  UUID sesji (opcjonalne)
	 *   channel_id   string  UUID kanału czatu
	 *   campaign_id  string  UUID kampanii (opcjonalne)
	 *   wp_user_id   int     ID użytkownika WP
	 *   char         array   Wiersz z cyber_characters
	 *   location     array   Wiersz z cyber_worldmap
	 *   world        array   Wiersz z cyber_worlds
	 *
	 * @return array {
	 *   'raw'     => string,   // surowa odpowiedź GPT
	 *   'error'   => ?string,  // null jeśli OK
	 *   'usage'   => array,    // prompt_tokens, completion_tokens, total_tokens
	 * }
	 */
	public function send( string $user_message, array $context, string $protocol = 'DIALOG' ): array {

		if ( ! defined( 'NW_OPENAI_API_KEY' ) || empty( NW_OPENAI_API_KEY ) ) {
			return [ 'raw' => '', 'error' => 'Brak NW_OPENAI_API_KEY.', 'usage' => [] ];
		}

		$char_id    = $context['char_id']    ?? '';
		$world_id   = $context['world_id']   ?? '';
		$session_id = $context['session_id'] ?? '';
		$channel_id = $context['channel_id'] ?? '';

		// 1. Zbuduj prompt systemowy
		$system_prompt = $this->build_system_prompt( $context, $protocol );

		// 2. Pobierz historię z Supabase
		$history = $this->get_history( $channel_id, $char_id );

		// 3. Wywołaj API
		$api_result = $this->call_api( $system_prompt, $history, $user_message, $char_id );

		if ( ! empty( $api_result['error'] ) ) {
			return $api_result;
		}

		// 4. Zaloguj tokeny
		if ( ! empty( $api_result['usage'] ) ) {
			$this->log_tokens( $api_result['usage'], $context, $protocol );
		}

		return $api_result;
	}

	// =========================================================
	// BUDOWANIE PROMPTU SYSTEMOWEGO
	// =========================================================

	private function build_system_prompt( array $ctx, string $protocol ): string {
		$char     = $ctx['char']     ?? [];
		$location = $ctx['location'] ?? [];
		$world    = $ctx['world']    ?? [];

		// --- Blok A: Tożsamość GM-a (stały, nadaje się do prompt caching) ---
		$blok_a = <<<PROMPT
Yesteś Mistrzem Gry (GM) w świecie NeoWeave — mrocznym, cyberpunkowym fantasy.
Odpowiadasz jako narrator i GM w języku polskim, chyba że gracz pisze po angielsku.
Archetyp: EPIC | Styl: noir, cyberpunk, immersyjny.
Zasady:
- Narracja max 3 akapity, zwięzła i klimatyczna.
- Decyzje gracza mają realne konsekwencje.
- NIE decydujesz za gracza. Opisujesz świat, reagujesz na akcje.
- Na końcu odpowiedzi umieść blok ---SYSTEM--- z tagami #MEMORY jeśli zaszło coś ważnego.
  Format bloków: ---SYSTEM---\n#MEMORY:topic:treść\n---END---
  Dostępne topici: character, npc, location, faction, campaign, item, summary
PROMPT;

		// --- Blok B: Stan świata (dynamiczny) ---
		$char_name = esc_html( $char['name'] ?? 'Nieznany' );
		$hp        = (int) ( $char['currenthp'] ?? 0 );
		$hp_max    = (int) ( $char['maxhp']     ?? 0 );
		$mp        = (int) ( $char['mp']        ?? 0 );
		$gold      = (int) ( $char['gold']      ?? 0 );
		$loc_name  = esc_html( $location['locationname'] ?? 'Nieznana lokacja' );
		$loc_tags  = esc_html( $location['instancetags']  ?? '' );
		$loc_prompt = esc_html( $location['aiprompt']     ?? '' );
		$entropy   = (int) ( $world['entropy']   ?? 0 );
		$w_tags    = esc_html( $world['globaltag1'] ?? '' );

		$blok_b = "AGENT: {$char_name} | HP: {$hp}/{$hp_max} | MP: {$mp} | Gold: {$gold}g\n";
		$blok_b .= "LOKACJA: {$loc_name} | TAGI: {$loc_tags}\n";
		if ( $loc_prompt ) $blok_b .= "KONTEKST LOKACJI: {$loc_prompt}\n";
		$blok_b .= "ŚWIAT: Entropia {$entropy}/100 | {$w_tags}";

		// --- Blok C: Memory ---
		$memory_parser = new NW_Memory_Parser();
		$blok_c = $memory_parser->build_prompt_block(
			$ctx['char_id']  ?? '',
			$ctx['world_id'] ?? ''
		);

		// --- Blok D: Protokół ---
		$blok_d = "PROTOKÓŁ: {$protocol}";

		$parts = array_filter( [ $blok_a, $blok_b, $blok_c, $blok_d ] );
		return implode( "\n\n", $parts );
	}

	// =========================================================
	// HISTORIA KONWERSACJI (z Supabase)
	// =========================================================

	private function get_history( string $channel_id, string $char_id ): array {
		if ( ! $channel_id || ! function_exists( 'tw_supabase_get' ) ) {
			return [];
		}

		// Pobierz ostatnie N wiadomości z kanału — tylko rola user/assistant
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

		// Supabase zwraca od najnowszych — odwróć kolejność
		$reversed = array_reverse( $rows );

		// Mapuj do formatu OpenAI messages
		return array_map( fn( $r ) => [
			'role'    => $r['role'],
			'content' => $r['content'],
		], $reversed );
	}

	// =========================================================
	// WYWOŁANIE OPENAI API
	// =========================================================

	private function call_api( string $system_prompt, array $history, string $user_message, string $char_id ): array {

		$messages = array_merge(
			[ [ 'role' => 'system', 'content' => $system_prompt ] ],
			$history,
			[ [ 'role' => 'user',   'content' => $user_message ] ]
		);

		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
			'headers' => [
				'Authorization' => 'Bearer ' . NW_OPENAI_API_KEY,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( [
				'model'      => $this->model,
				'messages'   => $messages,
				'max_tokens' => $this->max_tokens,
				'user'       => 'neoweaver_char_' . sanitize_key( $char_id ),
			] ),
			'timeout' => 45,
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'raw' => '', 'error' => $response->get_error_message(), 'usage' => [] ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || empty( $body['choices'][0]['message']['content'] ) ) {
			$err = $body['error']['message'] ?? "HTTP {$code}";
			error_log( '[NW_Chat_GPT] OpenAI error: ' . $err );
			return [ 'raw' => '', 'error' => $err, 'usage' => [] ];
		}

		return [
			'raw'   => $body['choices'][0]['message']['content'],
			'error' => null,
			'usage' => [
				'prompt_tokens'     => $body['usage']['prompt_tokens']     ?? 0,
				'completion_tokens' => $body['usage']['completion_tokens'] ?? 0,
				'total_tokens'      => $body['usage']['total_tokens']      ?? 0,
			],
		];
	}

	// =========================================================
	// LOGOWANIE TOKENÓW DO cyber_token_ledger
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
				'prompt_tokens'     => $usage['prompt_tokens']     ?? 0,
				'completion_tokens' => $usage['completion_tokens'] ?? 0,
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
