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
			return tw_rest_ai_broadcast_error( $session_id, $context->get_error_message() );
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

		// 5. Zapis do cyber_chat_messages (service key przez tw_rest_ai_supa_post)
		tw_rest_ai_supa_post( 'cyber_chat_messages', [
			'channel_id'   => $channel_id,
			'char_id'    => $char_id,
			'message_type' => 'player',
			'content'      => $message,
			'meta'         => wp_json_encode( [ 'protocol' => $protocol ] ),
		] );
		tw_rest_ai_supa_post( 'cyber_chat_messages', [
			'channel_id'   => $channel_id,
			'char_id'    => $char_id,
			'message_type' => 'gm',
			'content'      => $gm_text,
			'meta'         => wp_json_encode( [ 'protocol' => $protocol, 'tags' => $gm_tags ] ),
		] );

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
// HELPER: Buduj kontekst — service key, dane z cyber_characters
// ============================================================

if ( ! function_exists( 'tw_rest_ai_build_context' ) ) {
	function tw_rest_ai_build_context( string $char_id ): array|WP_Error {

		$safe_id = preg_replace( '/[^a-f0-9\-]/', '', strtolower( $char_id ) );

		// Pobierz postać (service key — omija RLS)
		$char = tw_rest_ai_supa_get(
			'cyber_characters',
			'id=eq.' . $safe_id . '&select=id,name,currenthp,maxhp,mp,satiety,hydration,gold,echo_tags,locationid,worldid,archetype,level&limit=1'
		)[0] ?? null;

		if ( empty( $char ) ) {
			return new WP_Error( 'tw_ai_no_char', 'Nie znaleziono postaci: ' . $char_id );
		}

		// Pobierz lokację z FK w postaci
		$location = [];
		if ( ! empty( $char['locationid'] ) ) {
			$safe_loc = preg_replace( '/[^a-f0-9\-]/', '', strtolower( $char['locationid'] ) );
			$location = tw_rest_ai_supa_get(
				'cyber_world_map',
				'id=eq.' . $safe_loc . '&select=id,locationname,instancetags,aiprompt,threatlevel,nid,eid,sid,wid,location_type&limit=1'
			)[0] ?? [];
		}

		// Pobierz świat z FK w postaci
		$world = [];
		if ( ! empty( $char['worldid'] ) ) {
			$safe_world = preg_replace( '/[^a-f0-9\-]/', '', strtolower( $char['worldid'] ) );
			$world = tw_rest_ai_supa_get(
				'cyber_worlds',
				'id=eq.' . $safe_world . '&select=id,worldname,entropy,globaltag1,globaltag2,globaltag3,difficulty,archetype,tech_vs_nature,chaos_vs_order,gold_vs_thief&limit=1'
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
			'channel_id=eq.' . rawurlencode( $channel_id )
				. '&order=created_at.desc&limit=14&select=message_type,content'
		);

		if ( empty( $rows ) ) return [];

		$rows    = array_reverse( $rows );
		$history = [];
		foreach ( $rows as $row ) {
			$history[] = [
				'role'    => ( ( $row['message_type'] ?? '' ) === 'player' ) ? 'user' : 'assistant',
				'content' => $row['content'] ?? '',
			];
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
					foreach ( $exits as $dir => $loc_id ) $lines[] = $dir . ':' . $loc_id;
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
					if ( $delta !== 0 ) tw_rest_ai_supa_rpc( 'fn_apply_hp_change', [ 'p_char_id' => $char_id, 'p_delta' => $delta ] );
					break;
				case 'GOLD_CHANGE':
					$delta = (int) $val;
					if ( $delta !== 0 ) tw_rest_ai_supa_rpc( 'fn_apply_gold_change', [ 'p_char_id' => $char_id, 'p_delta' => $delta ] );
					break;
				case 'LOC':
					if ( $val ) {
						tw_rest_ai_supa_rpc( 'fn_move_character', [ 'p_char_id' => $char_id, 'p_location_id' => $val ] );
						do_action( 'tw_location_changed', get_current_user_id(), [ 'char_id' => $char_id, 'location_id' => $val ] );
					}
					break;
				case 'ENTROPY_UP':
					$delta    = (int) $val;
					$world_id = $context['world']['id'] ?? null;
					if ( $delta !== 0 && $world_id ) tw_rest_ai_supa_rpc( 'fn_apply_entropy', [ 'p_world_id' => $world_id, 'p_delta' => $delta ] );
					break;
				case 'STATUS_ADD':
					if ( $val ) tw_rest_ai_supa_rpc( 'fn_add_status', [ 'p_char_id' => $char_id, 'p_status' => $val ] );
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
	function tw_rest_ai_broadcast_error( ?string $session_id, string $message ): WP_REST_Response {
		if ( $session_id ) {
			tw_rest_ai_realtime_send( 'game:' . $session_id, 'gm_error', [ 'message' => $message ] );
		}
		return new WP_REST_Response( [ 'message' => $message ], 500 );
	}
}

// ============================================================
// PRYWATNE HELPERY SUPABASE — service key, tylko wewnętrzne
// ============================================================

function tw_rest_ai_supa_get( string $table, string $params ): array {
	$url      = trailingslashit( tw_supabase_url() ) . 'rest/v1/' . $table . '?' . $params;
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
	return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
}

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
		error_log( '[NeoWeaver rest-ai-chat] POST error: ' . $response->get_error_message() );
	}
}

function tw_rest_ai_supa_rpc( string $fn, array $params ): mixed {
	$url      = trailingslashit( tw_supabase_url() ) . 'rest/v1/rpc/' . $fn;
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
		return $response;
	}
	return json_decode( wp_remote_retrieve_body( $response ), true );
}
