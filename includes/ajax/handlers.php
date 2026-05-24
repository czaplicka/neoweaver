<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TALE WEAVER – CORE AJAX HANDLERS
 *
 * Supabase credentials are read from tw_supabase_url() / tw_supabase_anon_key()
 * and TW_SUPABASE_SERVICE_KEY (wp-config).
 */

// ============================================================
// INTERNAL HELPERS
// ============================================================

if ( ! function_exists( 'tw_supa_url' ) ) {
	function tw_supa_url( string $table, array $args = array() ): string {
		if ( ! function_exists( 'tw_supabase_url' ) ) {
			return '';
		}

		$base = trailingslashit( tw_supabase_url() ) . 'rest/v1/' . rawurlencode( $table );

		if ( empty( $args ) ) {
			return $base;
		}

		return add_query_arg( $args, $base );
	}
}

if ( ! function_exists( 'tw_supa_headers' ) ) {
	function tw_supa_headers( string $mode = 'service' ): array {
		$key = '';

		if ( 'anon' === $mode ) {
			if ( ! function_exists( 'tw_supabase_anon_key' ) ) {
				return array(
					'Content-Type' => 'application/json',
				);
			}

			$key = tw_supabase_anon_key();
		} else {
			if ( defined( 'TW_SUPABASE_SERVICE_KEY' ) && TW_SUPABASE_SERVICE_KEY ) {
				$key = TW_SUPABASE_SERVICE_KEY;
			} elseif ( function_exists( 'tw_supabase_anon_key' ) ) {
				error_log( 'TW tw_supa_headers: TW_SUPABASE_SERVICE_KEY not defined, falling back to anon key.' );
				$key = tw_supabase_anon_key();
			}
		}

		$headers = array(
			'Content-Type' => 'application/json',
		);

		if ( '' !== $key ) {
			$headers['apikey']        = $key;
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		return $headers;
	}
}

if ( ! function_exists( 'tw_sanitize_supabase_id' ) ) {
	function tw_sanitize_supabase_id( $raw_id ): string {
		$sanitized = preg_replace( '/[^a-fA-F0-9\\-]/', '', (string) $raw_id );
		return strtolower( $sanitized );
	}
}

if ( ! function_exists( 'tw_update_game_session_status' ) ) {
	function tw_update_game_session_status( $campaign_id, string $status, $scenario_id = 0 ): bool {
		$url = tw_supa_url(
			'cyber_game_sessions',
			array(
				'campaign_id' => 'eq.' . tw_sanitize_supabase_id( $campaign_id ),
			)
		);

		if ( '' === $url ) {
			error_log( 'tw_update_game_session_status: Supabase URL missing.' );
			return false;
		}

		$safe_campaign_id = tw_sanitize_supabase_id( $campaign_id );

		if ( empty( $safe_campaign_id ) ) {
			error_log( 'tw_update_game_session_status: invalid campaign_id: ' . (string) $campaign_id );
			return false;
		}

		$allowed_statuses = array( 'idle', 'generating', 'ready', 'error', 'active', 'completed' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			error_log( 'tw_update_game_session_status: invalid status: ' . $status );
			return false;
		}

		$body = array(
			'scenario_status' => $status,
			'updated_at'      => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
		);

		if ( $scenario_id > 0 ) {
			$body['active_scenario_id'] = (int) $scenario_id;
		}

		$response = wp_remote_request(
			tw_supa_url(
				'cyber_game_sessions',
				array(
					'campaign_id' => 'eq.' . $safe_campaign_id,
				)
			),
			array(
				'method'    => 'PATCH',
				'headers'   => tw_supa_headers( 'service' ),
				'body'      => wp_json_encode( $body ),
				'timeout'   => 10,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'tw_update_game_session_status network error: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			error_log( 'tw_update_game_session_status HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
			return false;
		}

		return true;
	}
}

if ( ! function_exists( 'tw_get_game_session_status' ) ) {
	function tw_get_game_session_status( $campaign_id ): string {
		$url = tw_supa_url(
			'cyber_game_sessions',
			array(
				'campaign_id' => 'eq.' . tw_sanitize_supabase_id( $campaign_id ),
				'select'      => 'scenario_status',
				'limit'       => 1,
			)
		);

		if ( '' === $url ) {
			return '';
		}

		$safe_campaign_id = tw_sanitize_supabase_id( $campaign_id );

		if ( empty( $safe_campaign_id ) ) {
			return '';
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
			return '';
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return (string) ( $body[0]['scenario_status'] ?? '' );
	}
}

if ( ! function_exists( 'tw_get_chat_channel_id_by_session' ) ) {
	function tw_get_chat_channel_id_by_session( $session_id ): ?int {
		$url = tw_supa_url(
			'cyber_game_sessions',
			array(
				'id'     => 'eq.' . tw_sanitize_supabase_id( $session_id ),
				'select' => 'chat_channel_id',
				'limit'  => 1,
			)
		);

		if ( '' === $url ) {
			return null;
		}

		$safe_session_id = tw_sanitize_supabase_id( $session_id );

		if ( empty( $safe_session_id ) ) {
			return null;
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
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$raw  = $data[0]['chat_channel_id'] ?? null;

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
			wp_send_json_error( array( 'message' => 'Security check failed' ), 403 );
			return;
		}

		$wp_user_id = get_current_user_id();

		if ( ! $wp_user_id ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
			return;
		}

		$scenario_id = (int) ( $_POST['scenario_id'] ?? 0 );
		$campaign_id = tw_sanitize_supabase_id( $_POST['campaign_id'] ?? '' );

		if ( ! $scenario_id || ! $campaign_id ) {
			wp_send_json_error( array( 'message' => 'Missing IDs' ), 400 );
			return;
		}

		if ( ! function_exists( 'get_user_game_data_from_supabase' ) ) {
			wp_send_json_error( array( 'message' => 'Game data helper missing' ), 500 );
			return;
		}

		if ( ! function_exists( 'tw_invalidate_game_data_cache' ) ) {
			wp_send_json_error( array( 'message' => 'Cache helper missing' ), 500 );
			return;
		}

		$game_data  = get_user_game_data_from_supabase( $wp_user_id );
		$session_id = tw_sanitize_supabase_id( $game_data['active_session_id'] ?? '' );

		if ( ! $session_id ) {
			wp_send_json_error( array( 'message' => 'No active session found' ), 404 );
			return;
		}

		$updated = tw_update_game_session_status( $campaign_id, 'generating', $scenario_id );

		if ( ! $updated ) {
			wp_send_json_error( array( 'message' => 'Could not update session status' ), 500 );
			return;
		}

		do_action(
			'tw_session_state_changed',
			$wp_user_id,
			array(
				'campaign_id' => $campaign_id,
				'session_id'  => $session_id,
				'scenario_id' => $scenario_id,
				'status'      => 'generating',
			)
		);

		tw_invalidate_game_data_cache( $wp_user_id );

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
// 3. LORE TIPS
// Tylko zalogowani użytkownicy — nie rejestrujemy nopriv.
// ============================================================

if ( ! function_exists( 'tw_get_lore_tips' ) ) {

	add_action( 'wp_ajax_tw_get_lore_tips', 'tw_get_lore_tips' );

	function tw_get_lore_tips(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
			return;
		}

		$url = tw_supa_url(
			'cyber_tips',
			array(
				'select' => 'tip',
				'limit'  => 100,
			)
		);

		if ( '' === $url ) {
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

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			wp_send_json_error( array( 'message' => 'Supabase returned error' ), 502 );
			return;
		}

		$tips = json_decode( wp_remote_retrieve_body( $response ), true );
		$tips = is_array( $tips ) ? $tips : array();

		$tips = array_values(
			array_filter(
				array_map(
					static function( $row ) {
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

		$tips = array_slice( $tips, 0, 20 );

		wp_send_json_success( $tips );
	}
}
