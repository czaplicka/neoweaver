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
 * - server-side write uses tw_supabase_request() default service key
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

		if ( ! function_exists( 'tw_supabase_request' ) ) {
			wp_send_json_error( [ 'message' => 'Supabase helper missing' ], 500 );
			return;
		}

		$result = tw_supabase_request(
			'PATCH',
			'cyber_characters',
			[
				'id'         => 'eq.' . $char_id,
				'wp_user_id' => 'eq.' . $wp_user_id,
			],
			[
				'notes' => $notes,
			],
			[
				'headers' => [
					'Prefer' => 'return=minimal',
				],
				'timeout'   => 15,
				'sslverify' => true,
			]
		);

		if ( is_wp_error( $result ) ) {
			$status  = (int) ( $result->get_error_data()['status'] ?? 500 );
			$body    = $result->get_error_data()['body'] ?? '';
			$message = $result->get_error_message();

			error_log(
				'TW save_player_notes error: '
				. $message
				. ' | status=' . $status
				. ' | body=' . ( is_scalar( $body ) ? (string) $body : wp_json_encode( $body ) )
				. ' | char_id=' . $char_id
				. ' | wp_user_id=' . $wp_user_id
			);

			wp_send_json_error(
				[
					'message' => 'Supabase error',
					'status'  => $status,
					'error'   => $message,
				],
				$status > 0 ? $status : 500
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
