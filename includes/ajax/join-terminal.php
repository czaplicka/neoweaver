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

		// Read from POST into a dedicated $raw_char_id, then sanitize immediately
		// into $character_id. The raw value is never used again after this point.
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

		// Reads use the anon key (respects RLS). Service key is used only for the
		// signup INSERT below, where elevated privileges are required.
		$anon_key    = function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '';

		// FIX: Check the RETURN VALUE of tw_supabase_service_key(), not just whether
		// the function exists. The function_exists gate above only ensures the helper
		// is callable; it says nothing about whether wp-config defines a non-empty key.
		// A blank define('NW_SUPABASE_SERVICE_KEY', '') passes function_exists but
		// produces an empty string here, leading to a silent Supabase 401 that is
		// indistinguishable from a real insert error without this explicit guard.
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

		// NOTE on BUG 11: 'neq.STATUS_DEAD' is intentional and correct.
		// STATUS_DEAD is the canonical value stored in cyber_characters.status
		// across the entire codebase (campaign-creator.js, class-agents-list.php,
		// class-agents-repository.php, public/shortcodes/join-terminal.php).
		// No leading '#' or lowercase variant is used anywhere — not a bug.
		//
		// FIX: also fetch world_id so we can verify world compatibility with the
		// campaign before allowing the signup (see world_id check below).
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

		// FIX: also fetch world_id from the campaign so we can compare with the
		// character's world_id before inserting the signup row.
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

		if ( is_wp_error( $char_resp ) || 200 !== (int) wp_remote_retrieve_response_code( $char_resp ) ) {
			wp_send_json_error( array( 'message' => 'character_lookup_failed' ) );
			return;
		}

		$char_rows = json_decode( wp_remote_retrieve_body( $char_resp ), true );

		if ( empty( $char_rows[0] ) ) {
			wp_send_json_error( array( 'message' => 'character_not_owned_or_dead' ) );
			return;
		}

		// $character_id is already sanitized — no reassignment needed.
		$char_world_id = nw_sanitize_uuid( (string) ( $char_rows[0]['world_id'] ?? '' ) );

		if ( is_wp_error( $camp_resp ) || 200 !== (int) wp_remote_retrieve_response_code( $camp_resp ) ) {
			wp_send_json_error( array( 'message' => 'campaign_lookup_failed' ) );
			return;
		}

		$camp_rows = json_decode( wp_remote_retrieve_body( $camp_resp ), true );

		if ( empty( $camp_rows[0]['id'] ) ) {
			wp_send_json_error( array( 'message' => 'no_campaign_for_code' ) );
			return;
		}

		// nw_sanitize_uuid() strips everything that isn't a valid UUID character.
		// sanitize_text_field() only strips tags/whitespace — not safe for UUID values
		// that go directly into a Supabase query string.
		$campaign_id    = nw_sanitize_uuid( (string) $camp_rows[0]['id'] );
		$campaign_world = nw_sanitize_uuid( (string) ( $camp_rows[0]['world_id'] ?? '' ) );

		if ( empty( $campaign_id ) ) {
			wp_send_json_error( array( 'message' => 'invalid_campaign_id' ) );
			return;
		}

		// FIX: Verify the character belongs to the same world as the campaign.
		// Without this check a character bound to World A can join a campaign
		// running in World B — world rules, tags, and lore would be mismatched.
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
