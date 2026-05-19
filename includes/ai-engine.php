<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NEOWEAVER — AI ENGINE
 *
 * Dwa agenty AI:
 *   tw_ai_router() — klasyfikuje intencję gracza (gpt-4o-mini, temp=0, tanie)
 *   tw_ai_gm()     — narracja GM z pełnym kontekstem (gpt-4o, temp=0.8)
 *
 * Po każdym wywołaniu tokeny są logowane przez fn_log_tokens() w Supabase.
 *
 * Wymagane stałe w wp-config.php:
 *   NEOWEAVER_OPENAI_API_KEY   — klucz API
 *   NEOWEAVER_MODEL_ROUTER     — domyślnie 'gpt-4o-mini'
 *   NEOWEAVER_MODEL_GM         — domyślnie 'gpt-4o'
 *   NEOWEAVER_TOKENS_ROUTER    — domyślnie 30
 *   NEOWEAVER_TOKENS_GM        — domyślnie 600
 *
 * Kompatybilność: PHP 7.4+
 * Uwaga: union return types (array|WP_Error) wymagają PHP 8.0 — zastąpione
 * przez @return PHPDoc i sprawdzenie is_wp_error() po stronie wywołującego.
 */

// ============================================================
// STAŁE DOMYŚLNE (nadpisywane przez wp-config)
// ============================================================

if ( ! defined( 'NEOWEAVER_MODEL_ROUTER' ) )  define( 'NEOWEAVER_MODEL_ROUTER',  'gpt-4o-mini' );
if ( ! defined( 'NEOWEAVER_MODEL_GM' ) )       define( 'NEOWEAVER_MODEL_GM',      'gpt-4o' );
if ( ! defined( 'NEOWEAVER_TOKENS_ROUTER' ) )  define( 'NEOWEAVER_TOKENS_ROUTER', 30 );
if ( ! defined( 'NEOWEAVER_TOKENS_GM' ) )      define( 'NEOWEAVER_TOKENS_GM',     600 );

// ============================================================
// WEWNĘTRZNY HELPER HTTP → OpenAI
// Zwraca: array z 'content' i 'usage' LUB WP_Error
// Kompatybilność: PHP 7.4+ (brak union return type)
// ============================================================

if ( ! function_exists( 'tw_ai_call_openai' ) ) {
	/**
	 * @param array  $messages
	 * @param string $model
	 * @param int    $max_tokens
	 * @param float  $temperature
	 * @return array|WP_Error  array('content'=>string, 'usage'=>array) lub WP_Error
	 */
	function tw_ai_call_openai( array $messages, string $model, int $max_tokens, float $temperature ) {
		if ( ! defined( 'NEOWEAVER_OPENAI_API_KEY' ) || empty( NEOWEAVER_OPENAI_API_KEY ) ) {
			return new WP_Error( 'tw_ai_no_key', 'Brak klucza NEOWEAVER_OPENAI_API_KEY w wp-config.php' );
		}

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			[
				'timeout' => 45,
				'headers' => [
					'Authorization' => 'Bearer ' . NEOWEAVER_OPENAI_API_KEY,
					'Content-Type'  => 'application/json',
				],
				'body' => wp_json_encode( [
					'model'       => $model,
					'messages'    => $messages,
					'max_tokens'  => $max_tokens,
					'temperature' => $temperature,
				] ),
			]
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'TW tw_ai_call_openai network error: ' . $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$err = isset( $body['error']['message'] ) ? $body['error']['message'] : 'OpenAI HTTP ' . $code;
			error_log( 'TW tw_ai_call_openai HTTP ' . $code . ': ' . $err );
			return new WP_Error( 'tw_ai_http_' . $code, $err );
		}

		$content = isset( $body['choices'][0]['message']['content'] ) ? $body['choices'][0]['message']['content'] : '';
		$usage   = isset( $body['usage'] ) ? $body['usage'] : [ 'prompt_tokens' => 0, 'completion_tokens' => 0 ];

		return [
			'content' => trim( $content ),
			'usage'   => $usage,
		];
	}
}

// ============================================================
// WEWNĘTRZNY HELPER — logowanie tokenów do Supabase
// ============================================================

if ( ! function_exists( 'tw_ai_log_tokens' ) ) {
	function tw_ai_log_tokens(
		array  $usage,
		string $model,
		string $protocol,
		int    $wp_user_id,
		$char_id,
		$session_id,
		$campaign_id,
		$channel_id
	) {
		if ( ! function_exists( 'tw_supabase_rpc' ) ) {
			error_log( 'TW tw_ai_log_tokens: tw_supabase_rpc() niedostępne.' );
			return;
		}

		$result = tw_supabase_rpc( 'fn_log_tokens', [
			'p_wp_user_id'          => $wp_user_id,
			'p_char_id'             => $char_id,
			'p_session_id'          => $session_id,
			'p_campaign_id'         => $campaign_id,
			'p_channel_id'          => $channel_id,
			'p_protocol'            => $protocol,
			'p_prompt_tokens'       => (int) ( isset( $usage['prompt_tokens'] ) ? $usage['prompt_tokens'] : 0 ),
			'p_completion_tokens'   => (int) ( isset( $usage['completion_tokens'] ) ? $usage['completion_tokens'] : 0 ),
			'p_model'               => $model,
		] );

		if ( is_wp_error( $result ) ) {
			error_log( 'TW tw_ai_log_tokens RPC error: ' . $result->get_error_message() );
		}
	}
}

// ============================================================
// tw_ai_router()
//
// Klasyfikuje intencję gracza na jeden z protokołów.
//
// @param string $message  Wiadomość gracza z czatu.
// @return string          Jedna z: TRAVEL|COMBAT|TRADE|DIALOG|LORE|REST|DECK|META|UNKNOWN
// ============================================================

if ( ! function_exists( 'tw_ai_router' ) ) {
	function tw_ai_router( string $message ) {
		$system_prompt = <<<PROMPT
You are an intent classifier for a text RPG game. 
Analyze the player's message and return ONLY one word from this list:
TRAVEL, COMBAT, TRADE, DIALOG, LORE, REST, DECK, META, UNKNOWN

Rules:
- TRAVEL: movement, going somewhere, exploring locations
- COMBAT: attacking, fighting, using combat skills or cards
- TRADE: buying, selling, examining merchant inventory
- DIALOG: talking to NPCs, asking questions to characters
- LORE: asking about world history, factions, lore, rumors
- REST: resting, sleeping, waiting, recovering
- DECK: card draw, hand management, deck actions
- META: checking own stats, HP, inventory, status (no AI narration needed)
- UNKNOWN: unclear or out-of-scope

Respond with exactly ONE word. No punctuation. No explanation.
PROMPT;

		$result = tw_ai_call_openai(
			[
				[ 'role' => 'system', 'content' => $system_prompt ],
				[ 'role' => 'user',   'content' => $message ],
			],
			NEOWEAVER_MODEL_ROUTER,
			NEOWEAVER_TOKENS_ROUTER,
			0.0
		);

		if ( is_wp_error( $result ) ) {
			error_log( 'TW tw_ai_router error: ' . $result->get_error_message() );
			return 'UNKNOWN';
		}

		// Loguj tokeny routera (tanie, ale warto śledzić)
		tw_ai_log_tokens(
			$result['usage'],
			NEOWEAVER_MODEL_ROUTER,
			'ROUTER',
			get_current_user_id(),
			null, null, null, null
		);

		$protocol = strtoupper( trim( $result['content'] ) );
		$allowed  = [ 'TRAVEL', 'COMBAT', 'TRADE', 'DIALOG', 'LORE', 'REST', 'DECK', 'META', 'UNKNOWN' ];

		return in_array( $protocol, $allowed, true ) ? $protocol : 'UNKNOWN';
	}
}

// ============================================================
// tw_ai_gm()
//
// Wywołuje GM agenta z pełnym kontekstem.
//
// @param array  $context  [
//     'char'       => [...],   // dane postaci z Supabase
//     'location'   => [...],   // dane lokacji
//     'world'      => [...],   // dane świata
//     'protocol'   => string,  // wynik tw_ai_router()
//     'extra'      => string,  // opcjonalne dane protokołu (NPC, sklep itp.)
// ]
// @param array  $history  Ostatnie wiadomości [ ['role'=>'user','content'=>'...'], ... ]
// @param string $message  Bieżąca wiadomość gracza
// @param array  $ids      [ 'char_id', 'session_id', 'campaign_id', 'channel_id' ]
//
// @return array|WP_Error  array('text'=>string, 'tags'=>array) lub WP_Error
// Kompatybilność: PHP 7.4+ (brak union return type)
// ============================================================

if ( ! function_exists( 'tw_ai_gm' ) ) {
	function tw_ai_gm( array $context, array $history, string $message, array $ids = [] ) {
		$char     = isset( $context['char'] )     ? $context['char']     : [];
		$location = isset( $context['location'] ) ? $context['location'] : [];
		$world    = isset( $context['world'] )    ? $context['world']    : [];
		$protocol = isset( $context['protocol'] ) ? $context['protocol'] : 'UNKNOWN';
		$extra    = isset( $context['extra'] )    ? $context['extra']    : '';

		// Blok A — tożsamość GM
		$system_a = <<<PROMPT
You are the AI Game Master of NeoWeave — a dark cyberpunk RPG world.
You narrate in second person ("you"). Be vivid, tense, and immersive.
Keep responses under 120 words unless combat or trade requires detail.
Embed system tags in your response using format: #TAG or #TAG:value
Common tags: #LOC:uuid (location change), #HP_CHANGE:-5 (HP delta),
#ENTROPY_UP:3 (world entropy), #STATUS_ADD:poisoned, #ITEM_GET:item_id,
#GOLD_CHANGE:-10, #SESSION_END
Tags are parsed by the system — do not explain them to the player.
PROMPT;

		// Blok B — stan świata
		$char_name  = esc_html( isset( $char['name'] )              ? $char['name']              : 'Agent' );
		$hp         = (int) ( isset( $char['currenthp'] )           ? $char['currenthp']         : 0 );
		$max_hp     = (int) ( isset( $char['maxhp'] )               ? $char['maxhp']             : 100 );
		$mp         = (int) ( isset( $char['mp'] )                  ? $char['mp']                : 0 );
		$gold       = (int) ( isset( $char['gold'] )                ? $char['gold']              : 0 );
		$loc_name   = esc_html( isset( $location['locationname'] )  ? $location['locationname']  : 'Unknown' );
		$loc_tags   = esc_html( isset( $location['instancetags'] )  ? $location['instancetags']  : '' );
		$loc_prompt = esc_html( isset( $location['aiprompt'] )      ? $location['aiprompt']      : '' );
		$entropy    = (int) ( isset( $world['entropy'] )            ? $world['entropy']          : 0 );
		$w_tags     = esc_html( implode( ', ', array_filter( [
			isset( $world['globaltag1'] ) ? $world['globaltag1'] : '',
			isset( $world['globaltag2'] ) ? $world['globaltag2'] : '',
			isset( $world['globaltag3'] ) ? $world['globaltag3'] : '',
		] ) ) );

		$system_b  = "WORLD STATE\n";
		$system_b .= "Entropy: {$entropy}/100 | World tags: {$w_tags}\n";
		$system_b .= "AGENT: {$char_name} | HP: {$hp}/{$max_hp} | MP: {$mp} | Gold: {$gold}\n";
		$system_b .= "LOCATION: {$loc_name}\n";
		if ( $loc_tags )   { $system_b .= "Tags: {$loc_tags}\n"; }
		if ( $loc_prompt ) { $system_b .= "Context: {$loc_prompt}\n"; }

		// Blok C — dane protokołu
		$system_c  = "PROTOCOL: {$protocol}\n";
		if ( $extra ) {
			$system_c .= $extra . "\n";
		}

		$system_prompt = $system_a . "\n---\n" . $system_b . "---\n" . $system_c;

		// Historia + bieżąca wiadomość
		$messages = array_merge(
			[ [ 'role' => 'system', 'content' => $system_prompt ] ],
			array_slice( $history, -14 ), // max 14 wiadomości historii
			[ [ 'role' => 'user', 'content' => $message ] ]
		);

		$result = tw_ai_call_openai(
			$messages,
			NEOWEAVER_MODEL_GM,
			NEOWEAVER_TOKENS_GM,
			0.8
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Loguj tokeny GM
		tw_ai_log_tokens(
			$result['usage'],
			NEOWEAVER_MODEL_GM,
			$protocol,
			get_current_user_id(),
			isset( $ids['char_id'] )     ? $ids['char_id']     : null,
			isset( $ids['session_id'] )  ? $ids['session_id']  : null,
			isset( $ids['campaign_id'] ) ? $ids['campaign_id'] : null,
			isset( $ids['channel_id'] )  ? $ids['channel_id']  : null
		);

		// Parser tagów
		$raw_text = $result['content'];
		$tags     = [];

		$clean_text = preg_replace_callback(
			'/#([A-Z][A-Z0-9_]+)(?::([\w\-]+))?/',
			function ( $m ) use ( &$tags ) {
				$tags[] = [
					'tag' => $m[1],
					'val' => isset( $m[2] ) ? $m[2] : null,
				];
				return '';
			},
			$raw_text
		);

		return [
			'text' => trim( preg_replace( '/\s+/', ' ', $clean_text ) ),
			'tags' => $tags,
		];
	}
}
