<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NEOWEAVER — REST Endpoint: /wp-json/neoweaver/v1/ai-chat
 */

require_once __DIR__ . '/ai/class-neoweaver-claude-engine.php';
require_once __DIR__ . '/supabase-config.php';

if ( ! function_exists( 'tw_register_ai_chat_endpoint' ) ) {
	function tw_register_ai_chat_endpoint() {
		register_rest_route(
			'neoweaver/v1',
			'/ai-chat',
			[
				'methods'             => 'POST',
				'callback'            => 'tw_rest_ai_chat_handler',
				'permission_callback' => function () {
					return is_user_logged_in();
				},
				'args' => [
					'message'     => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
					'char_id'     => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'channel_id'  => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'session_id'  => [ 'required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
					'campaign_id' => [ 'required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				],
			]
		);
	}
	add_action( 'rest_api_init', 'tw_register_ai_chat_endpoint' );
}

if ( ! function_exists( 'tw_rest_ai_chat_handler' ) ) {
	function tw_rest_ai_chat_handler( WP_REST_Request $request ) {

		$message     = $request->get_param( 'message' );
		$char_id     = $request->get_param( 'char_id' );
		$channel_id  = $request->get_param( 'channel_id' );
		$session_id  = $request->get_param( 'session_id' )  ?: null;
		$campaign_id = $request->get_param( 'campaign_id' ) ?: null;

		// 1. Kontekst
		$context = tw_rest_ai_build_context( $char_id );
		if ( is_wp_error( $context ) ) {
			return tw_rest_ai_broadcast_error( $session_id, $context->get_error_message(), $context->get_error_code() );
		}

		// 2. Historia
		$history = tw_rest_ai_get_history( $channel_id );

		// 3. Router
		$protocol            = NeoWeaver_Intent_Router::classify( $message );
		$context['protocol'] = $protocol;
		$context['extra']    = tw_rest_ai_protocol_extra( $protocol, $char_id, $context );

		// 4. Claude Engine
		$engine    = new NeoWeaver_Claude_Engine();
		$gm_result = $engine->process_with_context( $context, $history, $message );

		if ( isset( $gm_result['error'] ) ) {
			return tw_rest_ai_broadcast_error( $session_id, $gm_result['error'] );
		}

		$gm_text = $gm_result['text'];
		$gm_tags = $gm_result['tags'];

		// Zapisuj oba komunikaty tylko gdy GM zwrócił niepustą odpowiedź.
		// Zapobiega sytuacji "wiadomość gracza bez odpowiedzi GM" w historii,
		// co łamałoby alternację user/assistant wymaganą przez Claude API.
		if ( ! empty( $gm_text ) ) {
			tw_rest_ai_supa_post( 'cyber_chat_messages', [
				'channel_id'   => $channel_id,
				'char_id'      => $char_id,
				'message_type' => 'player',
				'content'      => $message,
				'meta'         => wp_json_encode( [ 'protocol' => $protocol ] ),
			] );
			tw_rest_ai_supa_post( 'cyber_chat_messages', [
				'channel_id'   => $channel_id,
				'char_id'      => $char_id,
				'message_type' => 'gm',
				'content'      => $gm_text,
				'meta'         => wp_json_encode( [ 'protocol' => $protocol, 'tags' => $gm_tags ] ),
			] );
		}

		// 6. Tagi
		if ( ! empty( $gm_tags ) ) {
			tw_rest_ai_apply_tags( $gm_tags, $char_id, $context );
		}

		// 7. Realtime push
		tw_rest_ai_realtime_send(
			'game:' . ( $session_id ?: $channel_id ),
			'gm_response',
			[ 'text' => $gm_text, 'tags' => $gm_tags, 'protocol' => $protocol ]
		);

		return new WP_REST_Response( null, 202 );
	}
}

// ============================================================
// HELPER: Buduj kontekst
// ============================================================

if ( ! function_exists( 'tw_rest_ai_build_context' ) ) {
	function tw_rest_ai_build_context( string $char_id ) {

		$safe_id = preg_replace( '/[^a-f0-9\-]/', '', strtolower( $char_id ) );

		$char = tw_rest_ai_supa_get(
			'cyber_characters',
			[
				'id'     => 'eq.' . $safe_id,
				'select' => 'id,name,wp_user_id,current_hp,max_hp,mp,satiety,hydration,gold,echo_tags,location_id,world_id,archetype,level,class_id,race_id',
				'limit'  => '1',
			]
		)[0] ?? null;

		if ( empty( $char ) ) {
			return new WP_Error( 'tw_ai_no_char', 'Nie znaleziono postaci: ' . $char_id, [ 'status' => 404 ] );
		}

		// BUG FIX — weryfikacja właściciela postaci.
		// Rozróżniamy dwa przypadki: wp_user_id === null (problem z bazą/RLS)
		// vs wp_user_id !== zalogowany użytkownik (próba dostępu do cudzej postaci).
		// Oba skutkują 403, ale logujemy null osobno żeby można było debugować.
		$owner_id = isset( $char['wp_user_id'] ) ? (int) $char['wp_user_id'] : null;

		if ( $owner_id === null ) {
			error_log( sprintf(
				'[NeoWeaver rest-ai-chat] build_context: wp_user_id is NULL for char %s (missing column or RLS projection). Returning 403.',
				$char_id
			) );
			return new WP_Error( 'tw_ai_forbidden', 'Brak dostępu do tej postaci.', [ 'status' => 403 ] );
		}

		if ( $owner_id !== get_current_user_id() ) {
			return new WP_Error( 'tw_ai_forbidden', 'Brak dostępu do tej postaci.', [ 'status' => 403 ] );
		}

		$location = [];
		if ( ! empty( $char['location_id'] ) ) {
			$safe_loc = preg_replace( '/[^a-f0-9\-]/', '', strtolower( $char['location_id'] ) );
			$location = tw_rest_ai_supa_get(
				'cyber_world_map',
				[
					'id'     => 'eq.' . $safe_loc,
					'select' => 'id,locationname,instancetags,aiprompt,threatlevel,nid,eid,sid,wid,location_type',
					'limit'  => '1',
				]
			)[0] ?? [];
		}

		$world = [];
		if ( ! empty( $char['world_id'] ) ) {
			$safe_world = preg_replace( '/[^a-f0-9\-]/', '', strtolower( $char['world_id'] ) );
			$world = tw_rest_ai_supa_get(
				'cyber_worlds',
				[
					'id'     => 'eq.' . $safe_world,
					'select' => 'id,worldname,entropy,globaltag1,globaltag2,globaltag3,difficulty,archetype,tech_vs_nature,chaos_vs_order,gold_vs_thief',
					'limit'  => '1',
				]
			)[0] ?? [];
		}

		return compact( 'char', 'location', 'world' );
	}
}

// ============================================================
// HELPER: Historia czatu
// ============================================================

if ( ! function_exists( 'tw_rest_ai_get_history' ) ) {
	function tw_rest_ai_get_history( string $channel_id ): array {

		$rows = tw_rest_ai_supa_get(
			'cyber_chat_messages',
			[
				'channel_id'   => 'eq.' . $channel_id,
				'message_type' => 'in.(player,gm)',
				'order'        => 'created_at.desc',
				'limit'        => 20, // FIX: integer, nie string
				'select'       => 'message_type,content',
			]
		);

		if ( empty( $rows ) ) {
			return [];
		}

		// Supabase zwraca order=created_at.desc → $rows ma najnowsze na początku.
		// Iterujemy od najnowszych (deduplicacja zachowuje OSTATNIE wystąpienie
		// w grupie wiadomości tej samej roli), potem odwracamy do chronologicznego
		// porządku dla Claude API. Pierwsze odwracanie jest zbędne — usunięte.
		$rows_reversed = $rows; // najnowsze na początku — gotowe do deduplicacji
		$deduped       = [];
		$prev_role     = null;

		foreach ( $rows_reversed as $row ) {
			$content = trim( $row['content'] ?? '' );
			if ( $content === '' ) {
				continue;
			}
			$role = ( ( $row['message_type'] ?? '' ) === 'player' ) ? 'user' : 'assistant';

			if ( $role === $prev_role ) {
				continue;
			}

			$deduped[] = [ 'role' => $role, 'content' => $content ];
			$prev_role  = $role;

			if ( count( $deduped ) >= 14 ) {
				break;
			}
		}

		if ( count( $deduped ) > 14 ) {
			$deduped = array_slice( $deduped, 0, 14 );
		}

		$history = array_reverse( $deduped );

		while ( ! empty( $history ) && $history[0]['role'] === 'assistant' ) {
			array_shift( $history );
		}

		return $history;
	}
}

// ============================================================
// HELPER: Dane per protokół
// ============================================================

if ( ! function_exists( 'tw_rest_ai_protocol_extra' ) ) {
	function tw_rest_ai_protocol_extra( string $protocol, string $char_id, array $context ): string {
		$char     = $context['char']     ?? [];
		$location = $context['location'] ?? [];
		$extra    = '';

		switch ( $protocol ) {
			case 'TRAVEL':
				$exits = array_filter( [
					'north' => $location['nid'] ?? null,
					'south' => $location['sid'] ?? null,
					'east'  => $location['eid'] ?? null,
					'west'  => $location['wid'] ?? null,
				] );
				if ( $exits ) {
					$lines = [];
					foreach ( $exits as $dir => $loc_id ) {
						$lines[] = $dir . ':' . $loc_id;
					}
					$extra = 'AVAILABLE_EXITS: ' . implode( ', ', $lines );
				}
				$extra .= "\nSATIETY_CURRENT: " . (int) ( $char['satiety'] ?? 0 ) . " (travel costs -1)";
				break;
			case 'COMBAT':
				$extra = 'COMBAT_ACTIVE: true';
				break;
			case 'TRADE':
				$extra = 'PLAYER_GOLD: ' . (int) ( $char['gold'] ?? 0 );
				break;
			case 'REST':
				$safe  = isset( $location['threatlevel'] )
					? ( (int) $location['threatlevel'] === 0 ? 'true' : 'false' )
					: 'unknown';
				$extra = 'LOCATION_SAFE: ' . $safe;
				break;
		}
		return $extra;
	}
}

// ============================================================
// HELPER: Aplikuj tagi
// ============================================================

if ( ! function_exists( 'tw_rest_ai_apply_tags' ) ) {
	function tw_rest_ai_apply_tags( array $tags, string $char_id, array $context ): void {

		foreach ( $tags as $item ) {
			$tag = $item['tag'] ?? '';
			$val = $item['val'] ?? null;

			switch ( $tag ) {
				case 'HP_CHANGE':
					$delta = (int) $val;
					if ( $delta !== 0 ) {
						tw_rest_ai_supa_rpc( 'fn_apply_hp_change', [ 'p_char_id' => $char_id, 'p_delta' => $delta ] );
					}
					break;

				case 'MP_CHANGE':
					$delta = (int) $val;
					if ( $delta !== 0 ) {
						tw_rest_ai_supa_rpc( 'fn_apply_mp_change', [ 'p_char_id' => $char_id, 'p_delta' => $delta ] );
					}
					break;

				case 'XP_CHANGE':
					$delta = (int) $val;
					if ( $delta !== 0 ) {
						tw_rest_ai_supa_rpc( 'fn_apply_xp_change', [ 'p_char_id' => $char_id, 'p_delta' => $delta ] );
					}
					break;

				case 'GOLD_CHANGE':
					$delta = (int) $val;
					if ( $delta !== 0 ) {
						tw_rest_ai_supa_rpc( 'fn_apply_gold_change', [ 'p_char_id' => $char_id, 'p_delta' => $delta ] );
					}
					break;

				case 'LOC':
					if ( $val ) {
						tw_rest_ai_supa_rpc( 'fn_move_character', [ 'p_char_id' => $char_id, 'p_location_id' => $val ] );

						$wp_user_id = (int) ( $context['char']['wp_user_id'] ?? 0 );
						if ( $wp_user_id > 0 ) {
							do_action( 'tw_location_changed', $wp_user_id, [ 'char_id' => $char_id, 'location_id' => $val ] );
						} else {
							error_log( '[NeoWeaver ai-chat] LOC: brak wp_user_id dla char ' . $char_id );
						}
					}
					break;

				case 'ENTROPY_UP':
					// BUG 7 FIX — ENTROPY_UP zapisuje zmianę do Supabase przez RPC.
					$delta    = (int) $val;
					$world_id = $context['world']['id'] ?? null;
					if ( $delta !== 0 && $world_id ) {
						tw_rest_ai_supa_rpc( 'fn_apply_entropy_change', [
							'p_world_id' => $world_id,
							'p_delta'    => $delta,
						] );
						do_action( 'tw_entropy_change', $world_id, $delta, $context );
					}
					break;

				case 'ENTROPY_DOWN':
					// FIX — ENTROPY_DOWN był w KNOWN_TAGS silnika, ale nie miał handlera.
					// Efekt: GM emitował tag obniżający entropię, który był cicho gubiony
					// w default: — entropia świata nie mogła nigdy spaść przez czat.
					$delta    = (int) $val;
					$world_id = $context['world']['id'] ?? null;
					if ( $delta !== 0 && $world_id ) {
						tw_rest_ai_supa_rpc( 'fn_apply_entropy_change', [
							'p_world_id' => $world_id,
							'p_delta'    => -abs( $delta ), // zawsze ujemna delta (obniżenie)
						] );
						do_action( 'tw_entropy_change', $world_id, -abs( $delta ), $context );
					}
					break;

				case 'STATUS_POISONED':
				case 'STATUS_STUNNED':
				case 'STATUS_BURNING':
				case 'STATUS_FROZEN':
				case 'STATUS_BLEEDING':
				case 'STATUS_CURSED':
					// FIX — silnik emituje konkretne named status tagi, nie generyczny STATUS_ADD.
					// Używamy nazwy tagu jako wartości statusu (np. "STATUS_POISONED").
					// Jeśli fn_add_status oczekuje krótkiej formy, zmień na: str_replace('STATUS_', '', $tag)
					tw_rest_ai_supa_rpc( 'fn_add_status', [ 'p_char_id' => $char_id, 'p_status' => $tag ] );
					break;

				case 'STATUS_ADD':
					// Fallback: generyczny tag ze starszych buildów silnika
					if ( $val ) {
						tw_rest_ai_supa_rpc( 'fn_add_status', [ 'p_char_id' => $char_id, 'p_status' => $val ] );
					}
					break;

				case 'ITEM_GAINED':
					if ( $val ) {
						tw_rest_ai_supa_rpc( 'fn_add_item', [ 'p_char_id' => $char_id, 'p_item_id' => $val ] );
					}
					break;

				case 'ITEM_LOST':
					if ( $val ) {
						tw_rest_ai_supa_rpc( 'fn_remove_item', [ 'p_char_id' => $char_id, 'p_item_id' => $val ] );
					}
					break;

				case 'COMBAT_START':
					do_action( 'tw_combat_start', $char_id, $val, $context );
					break;

				case 'COMBAT_END':
					do_action( 'tw_combat_end', $char_id, $val, $context );
					break;

				case 'SCENE_CHANGE':
					do_action( 'tw_scene_change', $char_id, $val, $context );
					break;

				case 'CARD_DRAW':
					$count = max( 1, (int) $val );
					tw_rest_ai_supa_rpc( 'fn_draw_cards', [ 'p_char_id' => $char_id, 'p_count' => $count ] );
					break;

				default:
					error_log( 'NeoWeaver ai-chat: nieobsługiwany tag ' . $tag . ':' . $val );
			}
		}
	}
}

// ============================================================
// HELPER: Realtime push
// ============================================================

if ( ! function_exists( 'tw_rest_ai_realtime_send' ) ) {
	function tw_rest_ai_realtime_send( string $topic, string $event, array $payload ): void {
		$result = tw_rest_ai_supa_rpc( 'fn_realtime_broadcast', [
			'p_topic'   => $topic,
			'p_event'   => $event,
			'p_payload' => wp_json_encode( $payload ),
		] );
		if ( is_wp_error( $result ) ) {
			error_log( 'NeoWeaver realtime_send error: ' . $result->get_error_message() );
		}
	}
}

// ============================================================
// HELPER: Broadcast error
// ============================================================

if ( ! function_exists( 'tw_rest_ai_broadcast_error' ) ) {
	/**
	 * Zwraca błąd REST z kodem HTTP dopasowanym do typu błędu.
	 *
	 * @param string|null $session_id
	 * @param string      $message
	 * @param string      $error_code  WP_Error code (np. 'tw_ai_no_char')
	 * @param int         $http_status Opcjonalne nadpisanie kodu HTTP.
	 */
	function tw_rest_ai_broadcast_error(
		?string $session_id,
		string $message,
		string $error_code = '',
		int $http_status = 0
	): WP_REST_Response {

		if ( $http_status === 0 ) {
			$map = [
				'tw_ai_no_char'      => 404,
				'tw_ai_unauthorized' => 401,
				'tw_ai_forbidden'    => 403,
				'tw_ai_bad_request'  => 400,
			];
			$http_status = $map[ $error_code ] ?? 500;
		}

		if ( $session_id ) {
			tw_rest_ai_realtime_send( 'game:' . $session_id, 'gm_error', [ 'message' => $message, 'code' => $error_code ] );
		}

		return new WP_REST_Response( [ 'message' => $message, 'code' => $error_code ], $http_status );
	}
}

// ============================================================
// PRYWATNE HELPERY SUPABASE — service key, tylko wewnętrzne
// ============================================================

/**
 * GET z Supabase.
 *
 * FIX — URL construction: nie używamy rawurlencode() na nazwie tabeli.
 * Nazwy tabel cyber_* zawierają tylko litery, cyfry i podkreślenia —
 * są URL-safe bez kodowania. rawurlencode() jest zachowane dla RPC (nazwy
 * funkcji), ale tutaj ujednolicamy z tw_rest_ai_supa_post (brak encode).
 * Dzięki temu obie prywatne funkcje budują URL identycznie i każda
 * przyszła tabela z myślnikiem nie złamie tylko jednej z nich.
 *
 * @param string $table
 * @param array  $params
 * @return array
 */
function tw_rest_ai_supa_get( string $table, array $params ): array {
	$query    = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	$url      = trailingslashit( tw_supabase_url() ) . 'rest/v1/' . $table . '?' . $query;
	$response = wp_remote_get( $url, [
		'headers' => [
			'apikey'        => tw_supabase_service_key(),
			'Authorization' => 'Bearer ' . tw_supabase_service_key(),
			'Content-Type'  => 'application/json',
		],
		'timeout' => 8,
	] );
	if ( is_wp_error( $response ) ) {
		error_log( '[NeoWeaver rest-ai-chat] GET error: ' . $response->get_error_message() );
		return [];
	}
	$http_code = (int) wp_remote_retrieve_response_code( $response );
	if ( $http_code >= 300 ) {
		error_log( sprintf(
			'[NeoWeaver rest-ai-chat] GET %s returned HTTP %d: %s',
			$table,
			$http_code,
			wp_remote_retrieve_body( $response )
		) );
		return [];
	}
	return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
}

/**
 * POST do Supabase.
 *
 * @param string $table
 * @param array  $body
 */
function tw_rest_ai_supa_post( string $table, array $body ): void {
	$url      = trailingslashit( tw_supabase_url() ) . 'rest/v1/' . $table;
	$response = wp_remote_post( $url, [
		'headers' => [
			'apikey'        => tw_supabase_service_key(),
			'Authorization' => 'Bearer ' . tw_supabase_service_key(),
			'Content-Type'  => 'application/json',
			'Prefer'        => 'return=minimal',
		],
		'body'    => wp_json_encode( $body ),
		'timeout' => 8,
	] );
	if ( is_wp_error( $response ) ) {
		error_log( '[NeoWeaver rest-ai-chat] POST network error: ' . $response->get_error_message() );
		return;
	}
	$http_code = (int) wp_remote_retrieve_response_code( $response );
	if ( $http_code < 200 || $http_code >= 300 ) {
		error_log( sprintf(
			'[NeoWeaver rest-ai-chat] POST %s returned HTTP %d: %s',
			$table,
			$http_code,
			wp_remote_retrieve_body( $response )
		) );
	}
}

/**
 * RPC call do Supabase (POST na /rest/v1/rpc/{fn}).
 *
 * @param string $fn
 * @param array  $params
 * @return array|WP_Error
 */
function tw_rest_ai_supa_rpc( string $fn, array $params ) {
	$url      = trailingslashit( tw_supabase_url() ) . 'rest/v1/rpc/' . rawurlencode( $fn );
	$response = wp_remote_post( $url, [
		'headers' => [
			'apikey'        => tw_supabase_service_key(),
			'Authorization' => 'Bearer ' . tw_supabase_service_key(),
			'Content-Type'  => 'application/json',
		],
		'body'    => wp_json_encode( $params ),
		'timeout' => 8,
	] );
	if ( is_wp_error( $response ) ) {
		error_log( '[NeoWeaver rpc] network error ' . $fn . ': ' . $response->get_error_message() );
		return $response;
	}
	$http_code = (int) wp_remote_retrieve_response_code( $response );
	if ( $http_code < 200 || $http_code >= 300 ) {
		error_log( sprintf(
			'[NeoWeaver rpc] %s returned HTTP %d: %s',
			$fn,
			$http_code,
			wp_remote_retrieve_body( $response )
		) );
		return new WP_Error( 'tw_rpc_error', 'RPC ' . $fn . ' failed with HTTP ' . $http_code );
	}
	return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
}
