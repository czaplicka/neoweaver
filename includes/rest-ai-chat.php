<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NEOWEAVER — REST Endpoint: /wp-json/neoweaver/v1/ai-chat
 *
 * Przyjmuje wiadomość gracza, wywołuje NeoWeaver_Claude_Engine,
 * zapisuje odpowiedź do Supabase i rozgłasza przez Supabase Realtime.
 *
 * Flow:
 *   POST /wp-json/neoweaver/v1/ai-chat
 *     → walidacja + pobranie kontekstu z Supabase
 *     → NeoWeaver_Intent_Router::classify() — klasyfikacja intencji
 *     → NeoWeaver_Claude_Engine->process_with_context() — narracja + parser tagów
 *     → zapis wiadomości gracza + GM do cyber_chat_messages
 *     → tw_rest_ai_realtime_send() — push do JS przez Supabase Realtime
 *     → HTTP 202 Accepted (bez body, odpowiedź przez Realtime)
 *
 * Rejestracja: add_action( 'rest_api_init', 'tw_register_ai_chat_endpoint' );
 */

require_once __DIR__ . '/ai/class-neoweaver-claude-engine.php';

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
					'message' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'char_id' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'channel_id' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'session_id' => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'campaign_id' => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
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

		// --------------------------------------------------------
		// 1. Pobierz kontekst z Supabase
		// --------------------------------------------------------

		$context = tw_rest_ai_build_context( $char_id );
		if ( is_wp_error( $context ) ) {
			return tw_rest_ai_broadcast_error( $session_id, $context->get_error_message() );
		}

		// --------------------------------------------------------
		// 2. Pobierz historię czatu (ostatnie 14 wiadomości) per channel_id
		// --------------------------------------------------------

		$history = tw_rest_ai_get_history( $channel_id );

		// --------------------------------------------------------
		// 3. Router — klasyfikacja intencji (NeoWeaver_Intent_Router)
		// --------------------------------------------------------

		$protocol            = NeoWeaver_Intent_Router::classify( $message );
		$context['protocol'] = $protocol;

		// Dane dodatkowe per protokół (TRAVEL, COMBAT, TRADE itp.)
		$context['extra'] = tw_rest_ai_protocol_extra( $protocol, $char_id, $context );

		// --------------------------------------------------------
		// 4. GM Agent — NeoWeaver_Claude_Engine
		// --------------------------------------------------------

		$engine    = new NeoWeaver_Claude_Engine();
		$gm_result = $engine->process_with_context( $context, $history, $message );

		if ( isset( $gm_result['error'] ) ) {
			return tw_rest_ai_broadcast_error( $session_id, $gm_result['error'] );
		}

		$gm_text = $gm_result['text'];
		$gm_tags = $gm_result['tags'];

		// --------------------------------------------------------
		// 5. Zapisz wiadomości do cyber_chat_messages
		//    (Engine nie zapisuje przy process_with_context — robimy to tutaj)
		// --------------------------------------------------------

		if ( function_exists( 'tw_supabase_insert' ) ) {
			// Wiadomość gracza
			tw_supabase_insert( 'cyber_chat_messages', [
				'channel_id'   => $channel_id,
				'player_id'    => $char_id,
				'message_type' => 'player',
				'content'      => $message,
				'meta'         => wp_json_encode( [ 'protocol' => $protocol ] ),
			] );
			// Odpowiedź GM
			tw_supabase_insert( 'cyber_chat_messages', [
				'channel_id'   => $channel_id,
				'player_id'    => $char_id,
				'message_type' => 'gm',
				'content'      => $gm_text,
				'meta'         => wp_json_encode( [ 'protocol' => $protocol, 'tags' => $gm_tags ] ),
			] );
		}

		// --------------------------------------------------------
		// 6. Aplikuj tagi do Supabase (HP, Gold, Lokacja itp.)
		// --------------------------------------------------------

		if ( ! empty( $gm_tags ) ) {
			tw_rest_ai_apply_tags( $gm_tags, $char_id, $context );
		}

		// --------------------------------------------------------
		// 7. Realtime push — wysyła odpowiedź do JS przez WebSocket
		// --------------------------------------------------------

		$channel_topic = 'game:' . ( $session_id ?: $channel_id );

		tw_rest_ai_realtime_send(
			$channel_topic,
			'gm_response',
			[
				'text'     => $gm_text,
				'tags'     => $gm_tags,
				'protocol' => $protocol,
			]
		);

		// --------------------------------------------------------
		// 8. HTTP 202 Accepted — bez body (odpowiedź przez Realtime)
		// --------------------------------------------------------

		return new WP_REST_Response( null, 202 );
	}
}

// ============================================================
// HELPER: Buduj kontekst (char + location + world)
// ============================================================

if ( ! function_exists( 'tw_rest_ai_build_context' ) ) {
	function tw_rest_ai_build_context( $char_id ) {
		if ( ! function_exists( 'tw_supabase_select_one' ) ) {
			return new WP_Error( 'tw_ai_no_supabase', 'tw_supabase_select_one() niedostępne' );
		}

		$char = tw_supabase_select_one( 'cyber_characters', [ 'id' => $char_id ] );
		if ( is_wp_error( $char ) || empty( $char ) ) {
			return new WP_Error( 'tw_ai_no_char', 'Nie znaleziono postaci: ' . $char_id );
		}

		$location    = [];
		$world       = [];
		$location_id = null;
		$world_id    = null;

		$wp_user_id = get_current_user_id();
		if ( $wp_user_id && function_exists( 'get_user_game_data_from_supabase' ) ) {
			$game_data   = get_user_game_data_from_supabase( $wp_user_id );
			$location_id = $game_data['active_location_id'] ?? null;
			$world_id    = $game_data['active_world_id']    ?? null;
		}

		if ( ! empty( $location_id ) ) {
			$loc = tw_supabase_select_one( 'cyber_world_map', [ 'id' => $location_id ] );
			if ( ! is_wp_error( $loc ) && $loc ) {
				$location = $loc;
			}
		}

		if ( ! empty( $world_id ) ) {
			$w = tw_supabase_select_one( 'cyber_worlds', [ 'id' => $world_id ] );
			if ( ! is_wp_error( $w ) && $w ) {
				$world = $w;
			}
		}

		return [
			'char'     => $char,
			'location' => $location,
			'world'    => $world,
		];
	}
}

// ============================================================
// HELPER: Historia czatu z Supabase
// ============================================================

if ( ! function_exists( 'tw_rest_ai_get_history' ) ) {
	function tw_rest_ai_get_history( $channel_id ) {
		if ( ! function_exists( 'tw_supabase_request' ) ) {
			return [];
		}

		$rows = tw_supabase_request(
			'GET',
			'cyber_chat_messages?channel_id=eq.' . rawurlencode( $channel_id )
				. '&order=created_at.desc&limit=14'
				. '&select=message_type,content'
		);

		if ( is_wp_error( $rows ) || ! is_array( $rows ) ) {
			return [];
		}

		$rows    = array_reverse( $rows );
		$history = [];

		foreach ( $rows as $row ) {
			$role      = ( isset( $row['message_type'] ) && $row['message_type'] === 'player' ) ? 'user' : 'assistant';
			$history[] = [
				'role'    => $role,
				'content' => isset( $row['content'] ) ? $row['content'] : '',
			];
		}

		return $history;
	}
}

// ============================================================
// HELPER: Dane dodatkowe per protokół
// ============================================================

if ( ! function_exists( 'tw_rest_ai_protocol_extra' ) ) {
	function tw_rest_ai_protocol_extra( $protocol, $char_id, $context ) {
		$char     = isset( $context['char'] )     ? $context['char']     : [];
		$location = isset( $context['location'] ) ? $context['location'] : [];
		$extra    = '';

		switch ( $protocol ) {

			case 'TRAVEL':
				$exits = array_filter( [
					'north' => isset( $location['nid'] ) ? $location['nid'] : null,
					'south' => isset( $location['sid'] ) ? $location['sid'] : null,
					'east'  => isset( $location['eid'] ) ? $location['eid'] : null,
					'west'  => isset( $location['wid'] ) ? $location['wid'] : null,
				] );
				if ( $exits ) {
					$lines = [];
					foreach ( $exits as $dir => $loc_id ) {
						$lines[] = $dir . ':' . $loc_id;
					}
					$extra = 'AVAILABLE_EXITS: ' . implode( ', ', $lines );
				}
				$satiety = isset( $char['satiety'] ) ? (int) $char['satiety'] : 0;
				$extra  .= "\nSATIETY_CURRENT: {$satiety} (travel costs -1)";
				break;

			case 'COMBAT':
				$extra = 'COMBAT_ACTIVE: true';
				break;

			case 'TRADE':
				$gold  = isset( $char['gold'] ) ? (int) $char['gold'] : 0;
				$extra = "PLAYER_GOLD: {$gold}";
				break;

			case 'REST':
				$safe  = isset( $location['threatlevel'] )
					? ( (int) $location['threatlevel'] === 0 ? 'true' : 'false' )
					: 'unknown';
				$extra = "LOCATION_SAFE: {$safe}";
				break;

			default:
				break;
		}

		return $extra;
	}
}

// ============================================================
// HELPER: Aplikuj tagi do Supabase
// ============================================================

if ( ! function_exists( 'tw_rest_ai_apply_tags' ) ) {
	function tw_rest_ai_apply_tags( $tags, $char_id, $context ) {
		if ( ! function_exists( 'tw_supabase_rpc' ) ) {
			return;
		}

		foreach ( $tags as $item ) {
			$tag = isset( $item['tag'] ) ? $item['tag'] : '';
			$val = isset( $item['val'] ) ? $item['val'] : null;

			switch ( $tag ) {

				case 'HP_CHANGE':
					$delta = (int) $val;
					if ( $delta !== 0 ) {
						tw_supabase_rpc( 'fn_apply_hp_change', [
							'p_char_id' => $char_id,
							'p_delta'   => $delta,
						] );
					}
					break;

				case 'GOLD_CHANGE':
					$delta = (int) $val;
					if ( $delta !== 0 ) {
						tw_supabase_rpc( 'fn_apply_gold_change', [
							'p_char_id' => $char_id,
							'p_delta'   => $delta,
						] );
					}
					break;

				case 'LOC':
					if ( $val ) {
						tw_supabase_rpc( 'fn_move_character', [
							'p_char_id'     => $char_id,
							'p_location_id' => $val,
						] );

						$wp_user_id = get_current_user_id();
						if ( $wp_user_id && function_exists( 'tw_invalidate_game_data_cache' ) ) {
							tw_invalidate_game_data_cache( $wp_user_id );
						}
						do_action( 'tw_location_changed', $wp_user_id, [
							'char_id'     => $char_id,
							'location_id' => $val,
						] );
					}
					break;

				case 'ENTROPY_UP':
					$delta    = (int) $val;
					$world_id = isset( $context['world']['id'] ) ? $context['world']['id'] : null;
					if ( $delta !== 0 && $world_id ) {
						tw_supabase_rpc( 'fn_apply_entropy', [
							'p_world_id' => $world_id,
							'p_delta'    => $delta,
						] );
					}
					break;

				case 'STATUS_ADD':
					if ( $val ) {
						tw_supabase_rpc( 'fn_add_status', [
							'p_char_id' => $char_id,
							'p_status'  => $val,
						] );
					}
					break;

				default:
					error_log( 'NeoWeaver ai-chat: nieobsługiwany tag ' . $tag . ':' . $val );
			}
		}
	}
}

// ============================================================
// HELPER: Realtime send przez Supabase SQL
// ============================================================

if ( ! function_exists( 'tw_rest_ai_realtime_send' ) ) {
	function tw_rest_ai_realtime_send( $topic, $event, $payload ) {
		if ( ! function_exists( 'tw_supabase_rpc' ) ) {
			error_log( 'NeoWeaver rest-ai-chat: tw_supabase_rpc() niedostępne — Realtime send pominięty' );
			return;
		}

		$result = tw_supabase_rpc( 'fn_realtime_broadcast', [
			'p_topic'   => $topic,
			'p_event'   => $event,
			'p_payload' => wp_json_encode( $payload ),
		] );

		if ( is_wp_error( $result ) ) {
			error_log( 'NeoWeaver rest-ai-chat realtime_send error: ' . $result->get_error_message() );
		}
	}
}

// ============================================================
// HELPER: Broadcast error przez Realtime + return WP_REST_Response
// ============================================================

if ( ! function_exists( 'tw_rest_ai_broadcast_error' ) ) {
	function tw_rest_ai_broadcast_error( $session_id, $message ) {
		if ( $session_id ) {
			tw_rest_ai_realtime_send(
				'game:' . $session_id,
				'gm_error',
				[ 'message' => $message ]
			);
		}
		return new WP_REST_Response( [ 'message' => $message ], 500 );
	}
}
