<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_ajax_join_campaign' ) ) {
	function tw_ajax_join_campaign(): void {
		check_ajax_referer( 'tw_join_nonce', 'nonce' );

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => 'not_logged_in' ) );
			return;
		}

		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
			wp_send_json_error( array( 'message' => 'supabase_config_missing' ) );
			return;
		}

		$join_code    = isset( $_POST['join_code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['join_code'] ) ) ) : '';
		$character_id = isset( $_POST['character_id'] ) ? sanitize_text_field( wp_unslash( $_POST['character_id'] ) ) : '';

		if ( '' === $join_code ) {
			wp_send_json_error( array( 'message' => 'missing_join_code' ) );
			return;
		}

		if ( '' === $character_id ) {
			wp_send_json_error( array( 'message' => 'missing_character_id' ) );
			return;
		}

		$base = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
		$anon = tw_supabase_anon_key();

		$headers = array(
			'apikey'        => $anon,
			'Authorization' => 'Bearer ' . $anon,
		);

		$safe_char_id = preg_replace( '/[^a-zA-Z0-9\\-]/', '', $character_id );

		$char_url = add_query_arg(
			array(
				'id'         => 'eq.' . $safe_char_id,
				'wp_user_id' => 'eq.' . $user_id,
				'status'     => 'neq.STATUS_DEAD',
				'select'     => 'id',
				'limit'      => 1,
			),
			$base . 'cyber_characters'
		);

		$camp_url = add_query_arg(
			array(
				'join_code' => 'eq.' . $join_code,
				'select'    => 'id',
				'limit'     => 1,
			),
			$base . 'cyber_campaign'
		);

		$char_resp = wp_remote_get( $char_url, array( 'headers' => $headers, 'timeout' => 10 ) );
		$camp_resp = wp_remote_get( $camp_url, array( 'headers' => $headers, 'timeout' => 10 ) );

		if ( is_wp_error( $char_resp ) || 200 !== (int) wp_remote_retrieve_response_code( $char_resp ) ) {
			wp_send_json_error( array( 'message' => 'character_lookup_failed' ) );
			return;
		}

		$char_rows = json_decode( wp_remote_retrieve_body( $char_resp ), true );

		if ( empty( $char_rows ) ) {
			wp_send_json_error( array( 'message' => 'character_not_owned_or_dead' ) );
			return;
		}

		$character_id = $safe_char_id;

		if ( is_wp_error( $camp_resp ) || 200 !== (int) wp_remote_retrieve_response_code( $camp_resp ) ) {
			wp_send_json_error( array( 'message' => 'campaign_lookup_failed' ) );
			return;
		}

		$camp_rows = json_decode( wp_remote_retrieve_body( $camp_resp ), true );

		if ( empty( $camp_rows[0]['id'] ) ) {
			wp_send_json_error( array( 'message' => 'no_campaign_for_code' ) );
			return;
		}

		$campaign_id = sanitize_text_field( (string) $camp_rows[0]['id'] );

		$existing_url = add_query_arg(
			array(
				'campaign_id' => 'eq.' . $campaign_id,
				'wp_user_id'  => 'eq.' . $user_id,
				'select'      => 'id',
				'limit'       => 1,
			),
			$base . 'cyber_campaign_signups'
		);

		$existing_resp = wp_remote_get( $existing_url, array( 'headers' => $headers, 'timeout' => 10 ) );

		if ( ! is_wp_error( $existing_resp ) && 200 === (int) wp_remote_retrieve_response_code( $existing_resp ) ) {
			$existing = json_decode( wp_remote_retrieve_body( $existing_resp ), true );

			if ( ! empty( $existing ) ) {
				wp_send_json_success(
					array(
						'campaign_id' => $campaign_id,
						'status'      => 'already_joined',
					)
				);
				return;
			}
		}

		$insert_resp = wp_remote_post(
			$base . 'cyber_campaign_signups',
			array(
				'headers' => array_merge(
					$headers,
					array(
						'Content-Type' => 'application/json',
						'Prefer'       => 'return=minimal',
					)
				),
				'body'    => wp_json_encode(
					array(
						'campaign_id'  => $campaign_id,
						'wp_user_id'   => $user_id,
						'character_id' => $character_id,
					)
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $insert_resp ) ) {
			wp_send_json_error( array( 'message' => 'signup_insert_failed' ) );
			return;
		}

		$insert_code = (int) wp_remote_retrieve_response_code( $insert_resp );

		if ( $insert_code < 200 || $insert_code >= 300 ) {
			wp_send_json_error(
				array(
					'message' => 'signup_insert_failed',
					'http'    => $insert_code,
				)
			);
			return;
		}

		wp_send_json_success(
			array(
				'campaign_id' => $campaign_id,
				'status'      => 'joined',
			)
		);
	}

	add_action( 'wp_ajax_tw_join_campaign', 'tw_ajax_join_campaign' );
}
