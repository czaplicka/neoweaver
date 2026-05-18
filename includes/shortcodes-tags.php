<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TALE WEAVER – CORE AJAX HANDLERS
 *
 * Supabase credentials are read from tw_supabase_url() / tw_supabase_anon_key()
 * (defined in wp-config via the Hostinger Supabase integration).
 */

// ============================================================
// INTERNAL HELPERS
// ============================================================

if ( ! function_exists( 'tw_sanitize_supabase_table' ) ) {
	function tw_sanitize_supabase_table( $table ): string {
		return preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $table );
	}
}

if ( ! function_exists( 'tw_supa_url' ) ) {
	function tw_supa_url( string $table, array $args = array() ): string {
		$safe_table = tw_sanitize_supabase_table( $table );
		$base       = trailingslashit( tw_supabase_url() ) . 'rest/v1/' . $safe_table;

		if ( empty( $safe_table ) ) {
			return '';
		}

		if ( empty( $args ) ) {
			return $base;
		}

		return add_query_arg( $args, $base );
	}
}

if ( ! function_exists( 'tw_supa_headers' ) ) {
	function tw_supa_headers(): array {
		$key = tw_supabase_anon_key();

		return array(
			'apikey'        => $key,
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'application/json',
		);
	}
}

if ( ! function_exists( 'tw_sanitize_supabase_id' ) ) {
	function tw_sanitize_supabase_id( $raw_id ): string {
		return preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $raw_id );
	}
}

if ( ! function_exists( 'tw_supa_response_ok' ) ) {
	function tw_supa_response_ok( $response ): bool {
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return $code >= 200 && $code < 300;
	}
}

if ( ! function_exists( 'tw_update_game_session_status' ) ) {
	function tw_update_game_session_status( $campaign_id, string $status, $scenario_id = 0 ): bool {
		if ( ! function_exists( 'tw_supabase_url' ) ) {
			return false;
		}

		$safe_campaign_id = tw_sanitize_supabase_id( $campaign_id );

		if ( empty( $safe_campaign_id ) ) {
			error_log( 'tw_update_game_session_status: invalid campaign_id: ' . (string) $campaign_id );
			return false;
		}

		$url = tw_supa_url(
			'cyber_game_sessions',
			array(
				'campaign_id' => 'eq.' . $safe_campaign_id,
			)
		);

		if ( empty( $url ) ) {
			return false;
		}

		$body = array(
			'scenario_status' => sanitize_text_field( $status ),
			'updated_at'      => current_time( 'mysql' ),
		);

		if ( $scenario_id ) {
			$body['active_scenario_id'] = (int) $scenario_id;
		}

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'PATCH',
				'headers' => tw_supa_headers(),
				'body'    => wp_json_encode( $body ),
				'timeout' => 10,
			)
		);

		return tw_supa_response_ok( $response );
	}
}

if ( ! function_exists( 'tw_get_game_session_status' ) ) {
	function tw_get_game_session_status( $campaign_id ): string {
		if ( ! function_exists( 'tw_supabase_url' ) ) {
			return '';
		}

		$safe_campaign_id = tw_sanitize_supabase_id( $campaign_id );

		if ( empty( $safe_campaign_id ) ) {
			return '';
		}

		$url = tw_supa_url(
			'cyber_game_sessions',
			array(
				'campaign_id' => 'eq.' . $safe_campaign_id,
				'select'      => 'scenario_status',
				'limit'       => 1,
			)
		);

		if ( empty( $url ) ) {
			return '';
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => tw_supa_headers(),
				'timeout' => 10,
			)
		);

		if ( ! tw_supa_response_ok( $response ) ) {
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return '';
		}

		return (string) ( $body[0]['scenario_status'] ?? '' );
	}
}

if ( ! function_exists( 'tw_get_chat_channel_id_by_session' ) ) {
	function tw_get_chat_channel_id_by_session( $session_id ): ?int {
		if ( ! function_exists( 'tw_supabase_url' ) ) {
			return null;
		}

		$safe_session_id = tw_sanitize_supabase_id( $session_id );

		if ( empty( $safe_session_id ) ) {
			return null;
		}

		$url = tw_supa_url(
			'cyber_game_sessions',
			array(
				'id'     => 'eq.' . $safe_session_id,
				'select' => 'chat_channel_id',
				'limit'  => 1,
			)
		);

		if ( empty( $url ) ) {
			return null;
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => tw_supa_headers(),
				'timeout' => 10,
			)
		);

		if ( ! tw_supa_response_ok( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		$raw = $data[0]['chat_channel_id'] ?? null;

		return null !== $raw ? (int) $raw : null;
	}
}

// ============================================================
// 1. START SCENARIO GENERATION
// ============================================================

if ( ! function_exists( 'tw_start_scenario_generation' ) ) {

	add_action( 'wp_ajax_tw_start_scenario_generation', 'tw_start_scenario_generation' );

	function tw_start_scenario_generation(): void {
		if ( ! check_ajax_referer( 'tw_ajax_nonce', 'nonce', false ) ) {
			wp_send_json_error( 'Security check failed', 403 );
			return;
		}

		$wp_user_id = get_current_user_id();

		if ( ! $wp_user_id ) {
			wp_send_json_error( 'No WP user', 401 );
			return;
		}

		$scenario_id = isset( $_POST['scenario_id'] ) ? (int) $_POST['scenario_id'] : 0;
		$campaign_id = tw_sanitize_supabase_id( $_POST['campaign_id'] ?? '' );

		if ( ! $scenario_id || ! $campaign_id ) {
			wp_send_json_error( 'Missing IDs', 400 );
			return;
		}

		if ( ! function_exists( 'get_user_game_data_from_supabase' ) ) {
			wp_send_json_error( 'Game data helper missing', 500 );
			return;
		}

		$game_data  = get_user_game_data_from_supabase( $wp_user_id );
		$session_id = tw_sanitize_supabase_id( $game_data['active_session_id'] ?? '' );

		if ( ! $session_id ) {
			wp_send_json_error( 'No active session found', 404 );
			return;
		}

		$updated = tw_update_game_session_status( $campaign_id, 'generating', $scenario_id );

		if ( ! $updated ) {
			wp_send_json_error( 'Could not update session status', 500 );
			return;
		}

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
// ============================================================

if ( ! function_exists( 'tw_check_scenario_status' ) ) {

	add_action( 'wp_ajax_tw_check_scenario_status', 'tw_check_scenario_status' );

	function tw_check_scenario_status(): void {
		if ( ! check_ajax_referer( 'tw_ajax_nonce', 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => 'Security check failed',
					'status'  => 'error',
				),
				403
			);
			return;
		}

		$campaign_id = tw_sanitize_supabase_id( $_POST['campaign_id'] ?? '' );

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
// ============================================================

if ( ! function_exists( 'tw_get_ai_message' ) ) {

	add_action( 'wp_ajax_tw_get_ai_message', 'tw_get_ai_message' );

	function tw_get_ai_message(): void {
		if ( ! check_ajax_referer( 'tw_ajax_nonce', 'nonce', false ) ) {
			wp_send_json_error( 'Security check failed', 403 );
			return;
		}

		if ( ! function_exists( 'tw_supabase_url' ) ) {
			wp_send_json_error( 'Config missing', 500 );
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

		$url = tw_supa_url(
			'cyber_chat_messages',
			array(
				'chat_channel_id' => 'eq.' . $chat_channel_id,
				'sender_type'     => 'in.(AI,GM)',
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
				'headers' => tw_supa_headers(),
				'timeout' => 10,
			)
		);

		if ( ! tw_supa_response_ok( $response ) ) {
			wp_send_json_error( 'Supabase conn error', 502 );
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
// ============================================================

if ( ! function_exists( 'tw_get_lore_tips' ) ) {

	add_action( 'wp_ajax_tw_get_lore_tips', 'tw_get_lore_tips' );
	add_action( 'wp_ajax_nopriv_tw_get_lore_tips', 'tw_get_lore_tips' );

	function tw_get_lore_tips(): void {
		if ( ! function_exists( 'tw_supabase_url' ) ) {
			wp_send_json_error( 'Config missing', 500 );
			return;
		}

		$url = tw_supa_url(
			'cyber_tips',
			array(
				'select' => 'tip',
				'order'  => 'random()',
				'limit'  => 20,
			)
		);

		if ( empty( $url ) ) {
			wp_send_json_error( 'Invalid Supabase URL', 500 );
			return;
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => tw_supa_headers(),
				'timeout' => 10,
			)
		);

		if ( ! tw_supa_response_ok( $response ) ) {
			wp_send_json_error( 'Supabase conn error', 502 );
			return;
		}

		$tips = json_decode( wp_remote_retrieve_body( $response ), true );
		$tips = is_array( $tips ) ? $tips : array();

		$tips = array_map(
			static function ( $tip ) {
				return sanitize_text_field( $tip );
			},
			array_column( $tips, 'tip' )
		);

		wp_send_json_success( $tips );
	}
}
