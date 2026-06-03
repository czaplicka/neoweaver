<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_tw_toggle_char_public', 'tw_handle_toggle_char_public' );

if ( ! function_exists( 'tw_handle_toggle_char_public' ) ) {
	function tw_handle_toggle_char_public(): void {
		check_ajax_referer( 'tw_char_nonce', 'nonce' );

		// FIX: wp_is_uuid() does not exist in WordPress core — calling it causes
		// a fatal error on every request, making this toggle completely broken.
		// Replace with nw_sanitize_uuid() which is the project-wide UUID helper:
		// it strips all non-UUID characters and returns '' for invalid input,
		// so empty( $char_id ) below catches both missing and malformed values.
		$char_id    = isset( $_POST['char_id'] )
			? nw_sanitize_uuid( sanitize_text_field( wp_unslash( $_POST['char_id'] ) ) )
			: '';
		$is_public  = isset( $_POST['is_public'] )
			? filter_var( wp_unslash( $_POST['is_public'] ), FILTER_VALIDATE_BOOLEAN )
			: false;
		$wp_user_id = get_current_user_id();

		if ( ! $wp_user_id ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
			return;
		}

		if ( empty( $char_id ) ) {
			wp_send_json_error( [ 'message' => 'Invalid character ID' ], 400 );
			return;
		}

		if ( ! function_exists( 'tw_supabase_request' ) ) {
			wp_send_json_error( [ 'message' => 'Supabase request helper unavailable' ], 500 );
			return;
		}

		$result = tw_supabase_request(
			'PATCH',
			'cyber_characters',
			[
				'id'         => 'eq.' . $char_id,
				'wp_user_id' => 'eq.' . (int) $wp_user_id,
			],
			[
				'is_public' => $is_public,
			]
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'message' => 'Database error',
					'error'   => $result->get_error_message(),
				],
				500
			);
			return;
		}

		wp_send_json_success(
			[
				'char_id'   => $char_id,
				'is_public' => $is_public,
				'message'   => 'Character visibility updated',
			]
		);
	}
}
