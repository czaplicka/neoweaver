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

		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_service_key' ) ) {
			wp_send_json_error( array( 'message' => 'supabase_config_missing' ) );
			return;
		}

		$join_code = isset( $_POST['join_code'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_POST['join_code'] ) ) )
			: '';

		$character_id = isset( $_POST['character_id'] )
			? nw_sanitize_uuid( sanitize_text_field( wp_unslash( $_POST['character_id'] ) ) )
			: '';

		if ( '' === $join_code ) {
			wp_send_json_error( array( 'message' => 'missing_join_code' ) );
			return;
		}

		if ( '' === $character_id ) {
			wp_send_json_error( array( 'message' => 'missing_character_id' ) );
			return;
		}

		$base = trailingslashit( tw_supabase_url() ) . 'rest/v1/';

		$anon_key    = function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '';
		$service_key = tw_supabase_service_key();

		if ( ! $anon_key ) {
			wp_send_json_error( array( 'message' => 'supabase_config_missing' ) );
			return;
		}

		if ( empty( $service_key ) ) {
			wp_send_json_error( array( 'message' => 'supabase_service_key_missing' ) );
			return;
		}

		$read_headers = array(
			'apikey'        => $anon_key,
			'Authorization' => 'Bearer ' . $anon_key,
		);

		$write_headers = array(
			'apikey'        => $service_key,
			'Authorization' => 'Bearer ' . $service_key,
			'Content-Type'  => 'application/json',
			'Prefer'        => 'return=minimal',
		);

		$char_url = add_query_arg(
			array(
				'id'         => 'eq.' . $character_id,
				'wp_user_id' => 'eq.' . $user_id,
				'status'     => 'neq.STATUS_DEAD',
				'select'     => 'id,world_id',
				'limit'      => 1,
			),
			$base . 'cyber_characters'
		);

		$camp_url = add_query_arg(
			array(
				'join_code' => 'eq.' . $join_code,
				'select'    => 'id,world_id',
				'limit'     => 1,
			),
			$base . 'cyber_campaign'
		);

		$char_resp = wp_remote_get( $char_url, array( 'headers' => $read_headers, 'timeout' => 10 ) );
		$camp_resp = wp_remote_get( $camp_url, array( 'headers' => $read_headers, 'timeout' => 10 ) );

		// FIX: use 2xx range check instead of strict === 200.
		// PostgREST can return 206 Partial Content or other 2xx codes on valid reads.
		$char_code = (int) wp_remote_retrieve_response_code( $char_resp );
		if ( is_wp_error( $char_resp ) || $char_code < 200 || $char_code >= 300 ) {
			wp_send_json_error( array( 'message' => 'character_lookup_failed' ) );
			return;
		}

		$char_rows = json_decode( wp_remote_retrieve_body( $char_resp ), true );

		if ( empty( $char_rows[0] ) ) {
			wp_send_json_error( array( 'message' => 'character_not_owned_or_dead' ) );
			return;
		}

		$char_world_id = nw_sanitize_uuid( (string) ( $char_rows[0]['world_id'] ?? '' ) );

		$camp_code = (int) wp_remote_retrieve_response_code( $camp_resp );
		if ( is_wp_error( $camp_resp ) || $camp_code < 200 || $camp_code >= 300 ) {
			wp_send_json_error( array( 'message' => 'campaign_lookup_failed' ) );
			return;
		}

		$camp_rows = json_decode( wp_remote_retrieve_body( $camp_resp ), true );

		if ( empty( $camp_rows[0]['id'] ) ) {
			wp_send_json_error( array( 'message' => 'no_campaign_for_code' ) );
			return;
		}

		$campaign_id    = nw_sanitize_uuid( (string) $camp_rows[0]['id'] );
		$campaign_world = nw_sanitize_uuid( (string) ( $camp_rows[0]['world_id'] ?? '' ) );

		if ( empty( $campaign_id ) ) {
			wp_send_json_error( array( 'message' => 'invalid_campaign_id' ) );
			return;
		}

		if ( empty( $char_world_id ) || empty( $campaign_world ) || $char_world_id !== $campaign_world ) {
			wp_send_json_error( array( 'message' => 'world_mismatch' ) );
			return;
		}

		$existing_url = add_query_arg(
			array(
				'campaign_id' => 'eq.' . $campaign_id,
				'wp_user_id'  => 'eq.' . $user_id,
				'select'      => 'id',
				'limit'       => 1,
			),
			$base . 'cyber_campaign_signups'
		);

		$existing_resp = wp_remote_get( $existing_url, array( 'headers' => $read_headers, 'timeout' => 10 ) );
		$existing_code = (int) wp_remote_retrieve_response_code( $existing_resp );

		if ( ! is_wp_error( $existing_resp ) && $existing_code >= 200 && $existing_code < 300 ) {
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
				'headers' => $write_headers,
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
