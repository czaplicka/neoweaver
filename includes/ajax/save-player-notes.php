<?php
/**
 * TALE WEAVER – AJAX: Save Player Notes
 * Updates the `notes` field in cyber_characters via Supabase REST PATCH.
 *
 * Security:
 * - logged-in users only
 * - nonce required
 * - char_id sanitized as UUID-safe string
 * - PATCH limited by both id and wp_user_id
 * - server-side write uses service key
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_save_player_notes', 'tw_save_player_notes' );

if ( ! function_exists( 'tw_save_player_notes' ) ) {
	function tw_save_player_notes(): void {
		if ( 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_send_json_error( [ 'message' => 'Invalid method' ], 405 );
			return;
		}

		if ( ! check_ajax_referer( 'tw_ajax_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Security check failed' ], 403 );
			return;
		}

		$wp_user_id = get_current_user_id();
		if ( ! $wp_user_id ) {
			wp_send_json_error( [ 'message' => 'Not logged in' ], 401 );
			return;
		}

		$char_id = isset( $_POST['char_id'] )
			? strtolower( preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) $_POST['char_id'] ) )
			: '';

		if ( empty( $char_id ) ) {
			wp_send_json_error( [ 'message' => 'Missing char_id' ], 400 );
			return;
		}

		$notes = isset( $_POST['notes'] )
			? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) )
			: '';

		if ( ! function_exists( 'tw_supabase_url' ) ) {
			wp_send_json_error( [ 'message' => 'Supabase URL missing' ], 500 );
			return;
		}

		if ( defined( 'TW_SUPABASE_SERVICE_KEY' ) && TW_SUPABASE_SERVICE_KEY ) {
			$key = TW_SUPABASE_SERVICE_KEY;
		} elseif ( function_exists( 'tw_supabase_service_key' ) && tw_supabase_service_key() ) {
			$key = tw_supabase_service_key();
		} elseif ( function_exists( 'tw_supabase_anon_key' ) ) {
			error_log( 'TW save_player_notes: service key missing, falling back to anon key.' );
			$key = tw_supabase_anon_key();
		} else {
			wp_send_json_error( [ 'message' => 'Supabase key missing' ], 500 );
			return;
		}

		$supabase_url = trailingslashit( tw_supabase_url() ) . 'rest/v1/';

		$url = add_query_arg(
			[
				'id'         => 'eq.' . $char_id,
				'wp_user_id' => 'eq.' . $wp_user_id,
			],
			$supabase_url . 'cyber_characters'
		);

		$args = [
			'method'    => 'PATCH',
			'headers'   => [
				'apikey'        => $key,
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
				'Prefer'        => 'return=representation',
			],
			'body'      => wp_json_encode( [ 'notes' => $notes ] ),
			'timeout'   => 15,
			'sslverify' => true,
		];

		$resp = wp_remote_request( $url, $args );

		if ( is_wp_error( $resp ) ) {
			error_log( 'TW save_player_notes network error: ' . $resp->get_error_message() . ' char_id=' . $char_id . ' wp_user_id=' . $wp_user_id );
			wp_send_json_error(
				[
					'message' => 'HTTP error',
					'error'   => $resp->get_error_message(),
				],
				500
			);
			return;
		}

		$code = wp_remote_retrieve_response_code( $resp );
		$body = wp_remote_retrieve_body( $resp );

		if ( $code < 200 || $code >= 300 ) {
			error_log( 'TW save_player_notes HTTP ' . $code . ': ' . $body . ' char_id=' . $char_id . ' wp_user_id=' . $wp_user_id );
			wp_send_json_error(
				[
					'message' => 'Supabase error',
					'status'  => $code,
					'body'    => $body,
				],
				$code
			);
			return;
		}

		wp_send_json_success(
			[
				'message' => 'Notes updated',
				'char_id' => $char_id,
			]
		);
	}
}
