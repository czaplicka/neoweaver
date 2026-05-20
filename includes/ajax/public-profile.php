<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_tw_toggle_char_public', 'tw_handle_toggle_char_public' );

if ( ! function_exists( 'tw_handle_toggle_char_public' ) ) {
	function tw_handle_toggle_char_public(): void {
		check_ajax_referer( 'tw_char_nonce', 'nonce' );

		$char_id_raw = isset( $_POST['char_id'] ) ? wp_unslash( $_POST['char_id'] ) : '';
		$char_id     = sanitize_text_field( $char_id_raw );
		$is_public   = isset( $_POST['is_public'] )
			? filter_var( wp_unslash( $_POST['is_public'] ), FILTER_VALIDATE_BOOLEAN )
			: false;
		$wp_user_id  = get_current_user_id();

		if ( ! $wp_user_id ) {
			wp_send_json_error(
				[
					'message' => 'Unauthorized',
				],
				401
			);
		}

		if ( empty( $char_id ) || ! wp_is_uuid( $char_id ) ) {
			wp_send_json_error(
				[
					'message' => 'Invalid character ID',
				],
				400
			);
		}

		if ( ! function_exists( 'tw_supabase_request' ) ) {
			wp_send_json_error(
				[
					'message' => 'Supabase request helper unavailable',
				],
				500
			);
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
