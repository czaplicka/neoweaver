<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NEOWEAVER — AI ENGINE (Claude / Anthropic)
 *
 * Dwa agenty AI:
 *   tw_ai_router() — klasyfikuje intencję gracza (tanie wywołanie, temp=0)
 *   tw_ai_gm()     — narracja GM z pełnym kontekstem (Claude, temp=0.8)
 *
 * Historia rozmowy: pobierana z cyber_chat_messages (ostatnie 14 wiadomości).
 * Po każdym wywołaniu tokeny są logowane przez fn_log_tokens() w Supabase.
 *
 * Wymagane stałe w wp-config.php:
 *   NEOWEAVER_CLAUDE_API_KEY   — klucz Anthropic API
 *   NEOWEAVER_MODEL_GM         — domyślnie 'claude-opus-4-5'
 *   NEOWEAVER_MODEL_ROUTER     — domyślnie 'claude-haiku-4-5'
 *   NEOWEAVER_TOKENS_ROUTER    — domyślnie 30
 *   NEOWEAVER_TOKENS_GM        — domyślnie 600
 *
 * Kompatybilność: PHP 7.4+
 */

// ============================================================
// STAŁE DOMYŚLNE (nadpisywane przez wp-config)
// ============================================================

if ( ! defined( 'NEOWEAVER_MODEL_ROUTER' ) )  define( 'NEOWEAVER_MODEL_ROUTER',  'claude-haiku-4-5' );
if ( ! defined( 'NEOWEAVER_MODEL_GM' ) )       define( 'NEOWEAVER_MODEL_GM',      'claude-opus-4-5' );
if ( ! defined( 'NEOWEAVER_TOKENS_ROUTER' ) )  define( 'NEOWEAVER_TOKENS_ROUTER', 30 );
if ( ! defined( 'NEOWEAVER_TOKENS_GM' ) )      define( 'NEOWEAVER_TOKENS_GM',     600 );

// ============================================================
// WEWNĘTRZNY HELPER HTTP → Anthropic Messages API
//
// @param string $system    Treść system prompt (string, nie tablica)
// @param array  $messages  Tablica [ ['role'=>'user'|'assistant', 'content'=>string], ... ]
// @param string $model     Model Claude (np. 'claude-opus-4-5')
// @param int    $max_tokens
// @param float  $temperature
// @return array|WP_Error   array('content'=>string, 'usage'=>array) lub WP_Error
// ============================================================

if ( ! function_exists( 'tw_ai_call_claude' ) ) {
	function tw_ai_call_claude( string $system, array $messages, string $model, int $max_tokens, float $temperature ) {
		$key_const = 'NEOWEAVER_CLAUDE_API_KEY';
		if ( ! defined( $key_const ) || empty( constant( $key_const ) ) ) {
			return new WP_Error( 'tw_ai_no_key', 'Brak stałej NEOWEAVER_CLAUDE_API_KEY w wp-config.php' );
		}

		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			[
				'timeout' => 45,
				'headers' => [
					'x-api-key'         => constant( $key_const ),
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
				],
				'body' => wp_json_encode( [
					'model'        => $model,
					'system'       => $system,
					'messages'     => $messages,
					'max_tokens'   => $max_tokens,
					'temperature'  => $temperature,
				] ),
			]
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'TW tw_ai_call_claude network error: ' . $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$err = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Anthropic HTTP ' . $code;
			error_log( 'TW tw_ai_call_claude HTTP ' . $code . ': ' . $err );
			return new WP_Error( 'tw_ai_http_' . $code, $err );
		}

		// Claude: content[0].text
		$content = '';
		if ( ! empty( $body['content'] ) && is_array( $body['content'] ) ) {
			foreach ( $body['content'] as $block ) {
				if ( isset( $block['type'] ) && $block['type'] === 'text' ) {
					$content .= $block['text'];
				}
			}
		}

		// Claude zwraca input_tokens / output_tokens
		$raw_usage = isset( $body['usage'] ) ? $body['usage'] : [];
		$usage = [
			'prompt_tokens'     => isset( $raw_usage['input_tokens'] )  ? (int) $raw_usage['input_tokens']  : 0,
			'completion_tokens' => isset( $raw_usage['output_tokens'] ) ? (int) $raw_usage['output_tokens'] : 0,
		];

		return [
			'content' => trim( $content ),
			'usage'   => $usage,
		];
	}
}

// ============================================================
// HELPER — historia czatu z cyber_chat_messages
// Pobiera ostatnie $limit wiadomości dla danej postaci (order asc).
// ============================================================

if ( ! function_exists( 'tw_ai_get_history' ) ) {
	/**
	 * @param string $char_id  UUID postaci
	 * @param int    $limit    Liczba wiadomości (domyślnie 14)
	 * @return array           Tablica [ ['role'=>string, 'content'=>string], ... ]
	 */
	function tw_ai_get_history( string $char_id, int $limit = 14 ): array {
		if ( ! function_exists( 'tw_supabase_request' ) ) {
			return [];
		}

		$endpoint = '/rest/v1/cyber_chat_messages'
			. '?char_id=eq.' . urlencode( $char_id )
			. '&select=role,content'
			. '&order=created_at.asc'
			. '&limit=' . $limit;

		$result = tw_supabase_request( 'GET', $endpoint );

		if ( ! is_array( $result ) ) {
			return [];
		}

		$history = [];
		foreach ( $result as $row ) {
			if ( ! empty( $row['role'] ) && isset( $row['content'] ) ) {
				$history[] = [
					'role'    => $row['role'],
					'content' => $row['content'],
				];
			}
		}
		return $history;
	}
}

// ============================================================
// HELPER — zapis pary user+assistant do cyber_chat_messages
// ============================================================

if ( ! function_exists( 'tw_ai_save_to_history' ) ) {
	/**
	 * @param string      $char_id       UUID postaci
	 * @param string      $user_message  Oryginalna wiadomość gracza (bez prefixu GAME STATE)
	 * @param string      $gm_response   Odpowiedź GM (po parsowaniu tagów NIE — przed, żeby historia była kompletna)
	 * @param string|null $world_id      UUID świata (opcjonalnie)
	 */
	function tw_ai_save_to_history( string $char_id, string $user_message, string $gm_response, $world_id = null ): void {
		if ( ! function_exists( 'tw_supabase_request' ) ) {
			return;
		}

		$rows = [
			[
				'char_id'  => $char_id,
				'world_id' => $world_id,
				'role'     => 'user',
				'content'  => $user_message,
			],
			[
				'char_id'  => $char_id,
				'world_id' => $world_id,
				'role'     => 'assistant',
				'content'  => $gm_response,
			],
		];

		tw_supabase_request(
			'POST',
			'/rest/v1/cyber_chat_messages',
			$rows,
			[ 'Prefer' => 'return=minimal' ]
		);
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
			'p_wp_user_id'        => $wp_user_id,
			'p_char_id'           => $char_id,
			'p_session_id'        => $session_id,
			'p_campaign_id'       => $campaign_id,
			'p_channel_id'        => $channel_id,
			'p_protocol'          => $protocol,
			'p_prompt_tokens'     => (int) ( isset( $usage['prompt_tokens'] )     ? $usage['prompt_tokens']     : 0 ),
			'p_completion_tokens' => (int) ( isset( $usage['completion_tokens'] ) ? $usage['completion_tokens'] : 0 ),
			'p_model'             => $model,
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
	function tw_ai_router( string $message ): string {
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

		$result = tw_ai_call_claude(
			$system_prompt,
			[ [ 'role' => 'user', 'content' => $message ] ],
			NEOWEAVER_MODEL_ROUTER,
			NEOWEAVER_TOKENS_ROUTER,
			0.0
		);

		if ( is_wp_error( $result ) ) {
			error_log( 'TW tw_ai_router error: ' . $result->get_error_message() );
			return 'UNKNOWN';
		}

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
// Wywołuje GM agenta z pełnym kontekstem i historią czatu.
//
// @param array  $context  [
//     'char'       => [...],   // dane postaci z Supabase
//     'location'   => [...],   // dane lokacji
//     'world'      => [...],   // dane świata
//     'protocol'   => string,  // wynik tw_ai_router()
//     'extra'      => string,  // opcjonalne dane protokołu (NPC, sklep itp.)
//     'world_id'   => string,  // UUID świata (opcjonalnie, do zapisu historii)
// ]
// @param string $message  Bieżąca wiadomość gracza
// @param array  $ids      [ 'char_id', 'session_id', 'campaign_id', 'channel_id' ]
//
// @return array|WP_Error  array('text'=>string, 'tags'=>array) lub WP_Error
// ============================================================

if ( ! function_exists( 'tw_ai_gm' ) ) {
	function tw_ai_gm( array $context, string $message, array $ids = [] ) {
		$char     = isset( $context['char'] )     ? $context['char']     : [];
		$location = isset( $context['location'] ) ? $context['location'] : [];
		$world    = isset( $context['world'] )    ? $context['world']    : [];
		$protocol = isset( $context['protocol'] ) ? $context['protocol'] : 'UNKNOWN';
		$extra    = isset( $context['extra'] )    ? $context['extra']    : '';
		$world_id = isset( $context['world_id'] ) ? $context['world_id'] : null;
		$char_id  = isset( $ids['char_id'] )      ? $ids['char_id']      : null;

		// Blok A — tożsamość GM (system prompt dla Claude)
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
		$char_name  = isset( $char['name'] )             ? $char['name']             : 'Agent';
		$hp         = (int) ( isset( $char['currenthp'] )    ? $char['currenthp']    : 0 );
		$max_hp     = (int) ( isset( $char['maxhp'] )         ? $char['maxhp']        : 100 );
		$mp         = (int) ( isset( $char['mp'] )             ? $char['mp']          : 0 );
		$gold       = (int) ( isset( $char['gold'] )           ? $char['gold']        : 0 );
		$loc_name   = isset( $location['locationname'] )  ? $location['locationname']  : 'Unknown';
		$loc_tags   = isset( $location['instancetags'] )  ? $location['instancetags']  : '';
		$loc_prompt = isset( $location['aiprompt'] )      ? $location['aiprompt']      : '';
		$entropy    = (int) ( isset( $world['entropy'] )       ? $world['entropy']    : 0 );
		$w_tags     = implode( ', ', array_filter( [
			isset( $world['globaltag1'] ) ? $world['globaltag1'] : '',
			isset( $world['globaltag2'] ) ? $world['globaltag2'] : '',
			isset( $world['globaltag3'] ) ? $world['globaltag3'] : '',
		] ) );

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

		// Historia z Supabase (ostatnie 14 wiadomości) + bieżąca wiadomość
		$history = $char_id ? tw_ai_get_history( $char_id, 14 ) : [];
		$history[] = [
			'role'    => 'user',
			'content' => "[GAME STATE]\n{$system_b}\n---\n{$system_c}\n[PLAYER]\n{$message}",
		];

		$result = tw_ai_call_claude(
			$system_prompt,
			$history,
			NEOWEAVER_MODEL_GM,
			NEOWEAVER_TOKENS_GM,
			0.8
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Zapisz parę user+assistant do historii
		if ( $char_id ) {
			tw_ai_save_to_history( $char_id, $message, $result['content'], $world_id );
		}

		// Loguj tokeny GM
		tw_ai_log_tokens(
			$result['usage'],
			NEOWEAVER_MODEL_GM,
			$protocol,
			get_current_user_id(),
			$char_id,
			isset( $ids['session_id'] )  ? $ids['session_id']  : null,
			isset( $ids['campaign_id'] ) ? $ids['campaign_id'] : null,
			isset( $ids['channel_id'] )  ? $ids['channel_id']  : null
		);

		// Parser tagów systemowych
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

// ============================================================
// tw_ai_reset_history()
//
// Usuwa historię czatu postaci z cyber_chat_messages.
// Wywołaj przy nowej sesji gry lub śmierci postaci.
//
// @param string $char_id  UUID postaci
// ============================================================

if ( ! function_exists( 'tw_ai_reset_history' ) ) {
	function tw_ai_reset_history( string $char_id ): void {
		if ( ! function_exists( 'tw_supabase_request' ) ) {
			return;
		}
		tw_supabase_request(
			'DELETE',
			'/rest/v1/cyber_chat_messages?char_id=eq.' . urlencode( $char_id )
		);
	}
}
