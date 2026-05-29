<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NEOWEAVER – SHORTCODES & TAGS
 *
 * All shared Supabase helpers (tw_supa_url, tw_supa_headers,
 * tw_sanitize_supabase_id, tw_update_game_session_status, etc.)
 * are defined authoritatively in includes/ajax/handlers.php,
 * which is always loaded before this file.
 *
 * DO NOT re-define those helpers here — the if(!function_exists)
 * guard would silently win on non-AJAX paths and use the anon key
 * for writes that require the service key.
 */

// ============================================================
// 1. START SCENARIO GENERATION
// Registered here only — handlers.php is ajax_only.
// ============================================================

if ( ! function_exists( 'tw_start_scenario_generation' ) ) {

	add_action( 'wp_ajax_tw_start_scenario_generation', 'tw_start_scenario_generation' );

	function tw_start_scenario_generation(): void {
		if ( ! check_ajax_referer( 'tw_adventure_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed' ), 403 );
			return;
		}

		$wp_user_id = get_current_user_id();

		if ( ! $wp_user_id ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
			return;
		}

		$scenario_id = tw_sanitize_supabase_id( $_POST['scenario_id'] ?? '' );
		$campaign_id = tw_sanitize_supabase_id( $_POST['campaign_id'] ?? '' );

		if ( '' === $scenario_id || '' === $campaign_id ) {
			wp_send_json_error( array( 'message' => 'Missing IDs' ), 400 );
			return;
		}

		if ( ! function_exists( 'get_user_game_data_from_supabase' ) ) {
			wp_send_json_error( array( 'message' => 'Game data helper missing' ), 500 );
			return;
		}

		$game_data  = get_user_game_data_from_supabase( $wp_user_id );
		$session_id = tw_sanitize_supabase_id( $game_data['active_session_id'] ?? '' );

		if ( ! $session_id ) {
			wp_send_json_error( array( 'message' => 'No active session found' ), 404 );
			return;
		}

		// tw_update_game_session_status uses service key via handlers.php version.
		$updated = tw_update_game_session_status( $campaign_id, 'generating', $scenario_id );

		if ( ! $updated ) {
			wp_send_json_error( array( 'message' => 'Could not update session status' ), 500 );
			return;
		}

		if ( function_exists( 'tw_invalidate_game_data_cache' ) ) {
			tw_invalidate_game_data_cache( $wp_user_id );
		}

		do_action(
			'tw_session_state_changed',
			$wp_user_id,
			array(
				'session_id'  => $session_id,
				'campaign_id' => $campaign_id,
				'scenario_id' => $scenario_id,
				'status'      => 'generating',
			)
		);

		wp_send_json_success(
			array(
				'message'     => 'Scenario generation marked as started',
				'status'      => 'generating',
				'scenario_id' => $scenario_id,
				'campaign_id' => $campaign_id,
				'session_id'  => $session_id,
			)
		);
	}
}

// ============================================================
// 2. CHECK SCENARIO STATUS
// Requires login — session data must not leak to guests.
// ============================================================

if ( ! function_exists( 'tw_check_scenario_status' ) ) {

	add_action( 'wp_ajax_tw_check_scenario_status', 'tw_check_scenario_status' );
	// Intentionally NOT registered for wp_ajax_nopriv_ — guests have no session.

	function tw_check_scenario_status(): void {
		if ( ! check_ajax_referer( 'tw_adventure_nonce', 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => 'Security check failed',
					'status'  => 'error',
				),
				403
			);
			return;
		}

		if ( ! get_current_user_id() ) {
			wp_send_json_error(
				array(
					'message' => 'Unauthorized',
					'status'  => 'error',
				),
				401
			);
			return;
		}

		$campaign_id = tw_sanitize_supabase_id( $_POST['campaign_id'] ?? $_GET['campaign_id'] ?? '' );

		if ( empty( $campaign_id ) ) {
			wp_send_json_error(
				array(
					'message' => 'Missing campaign_id',
					'status'  => 'error',
				),
				400
			);
			return;
		}

		$status = tw_get_game_session_status( $campaign_id );

		wp_send_json_success(
			array(
				'status' => $status ? $status : 'generating',
			)
		);
	}
}

// ============================================================
// 3. GET AI / GM MESSAGE
// Queries by message_type column (values: 'gm', 'player').
// ============================================================

if ( ! function_exists( 'tw_get_ai_message' ) ) {

	add_action( 'wp_ajax_tw_get_ai_message', 'tw_get_ai_message' );

	function tw_get_ai_message(): void {
		if ( ! check_ajax_referer( 'tw_adventure_nonce', 'nonce', false ) ) {
			wp_send_json_error( 'Security check failed', 403 );
			return;
		}

		$wp_user_id = get_current_user_id();

		if ( ! $wp_user_id ) {
			wp_send_json_error( 'No user logged in', 401 );
			return;
		}

		if ( ! function_exists( 'get_user_game_data_from_supabase' ) ) {
			wp_send_json_error( 'Game data helper missing', 500 );
			return;
		}

		$game_data  = get_user_game_data_from_supabase( $wp_user_id );
		$session_id = tw_sanitize_supabase_id( $game_data['active_session_id'] ?? '' );

		if ( ! $session_id ) {
			wp_send_json_error( 'No active session', 404 );
			return;
		}

		$chat_channel_id = tw_get_chat_channel_id_by_session( $session_id );

		if ( ! $chat_channel_id ) {
			wp_send_json_error( 'Chat channel not found', 404 );
			return;
		}

		// Filter by message_type column (schema: 'gm' | 'player'), not sender_type.
		$url = tw_supa_url(
			'cyber_chat_messages',
			array(
				'chat_channel_id' => 'eq.' . tw_sanitize_supabase_id( (string) $chat_channel_id ),
				'message_type'    => 'eq.gm',
				'order'           => 'created_at.desc',
				'limit'           => 1,
			)
		);

		if ( empty( $url ) ) {
			wp_send_json_error( 'Invalid Supabase URL', 500 );
			return;
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers'   => tw_supa_headers( 'anon' ),
				'timeout'   => 10,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'Supabase connection error', 502 );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			wp_send_json_error( 'Supabase returned error ' . $code, 502 );
			return;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			wp_send_json_error( 'Invalid Supabase response', 502 );
			return;
		}

		if ( ! empty( $data ) && isset( $data[0]['message'] ) ) {
			wp_send_json_success(
				array(
					'message' => wp_kses_post( $data[0]['message'] ),
				)
			);
			return;
		}

		wp_send_json_error( 'No message yet', 404 );
	}
}

// ============================================================
// 4. LORE TIPS
// Available to logged-out users (loading screen, etc.).
// nopriv hook registered here; handlers.php only registers priv.
// ============================================================

if ( ! function_exists( 'tw_get_lore_tips' ) ) {

	add_action( 'wp_ajax_tw_get_lore_tips', 'tw_get_lore_tips' );
	add_action( 'wp_ajax_nopriv_tw_get_lore_tips', 'tw_get_lore_tips' );

	function tw_get_lore_tips(): void {
		$url = tw_supa_url(
			'cyber_tips',
			array(
				'select' => 'tip',
				'limit'  => 100,
			)
		);

		if ( empty( $url ) ) {
			wp_send_json_error( array( 'message' => 'Config missing' ), 500 );
			return;
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers'   => tw_supa_headers( 'anon' ),
				'timeout'   => 10,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => 'Supabase connection error' ), 502 );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			wp_send_json_error( array( 'message' => 'Supabase returned error' ), 502 );
			return;
		}

		$tips = json_decode( wp_remote_retrieve_body( $response ), true );
		$tips = is_array( $tips ) ? $tips : array();

		$tips = array_values(
			array_filter(
				array_map(
					static function ( $row ) {
						$tip = isset( $row['tip'] ) ? trim( (string) $row['tip'] ) : '';
						return '' !== $tip ? $tip : null;
					},
					$tips
				)
			)
		);

		if ( ! empty( $tips ) ) {
			shuffle( $tips );
		}

		wp_send_json_success( array_slice( $tips, 0, 20 ) );
	}
}
