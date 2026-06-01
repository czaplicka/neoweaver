<?php
/**
 * AJAX handler: tw_save_deck
 * Saves the player's active deck selection via cyber_sync_deck RPC.
 *
 * POST params:
 *   nonce        — wp nonce 'tw_deck_nonce' (BUG-10 FIX: was 'cyber_deck_builder')
 *   character_id — UUID of the character
 *   active       — comma-separated cyber_character_deck.id values (UUIDs)
 */

if ( ! function_exists( 'tw_ajax_save_deck' ) ) {

	add_action( 'wp_ajax_tw_save_deck', 'tw_ajax_save_deck' );

	function tw_ajax_save_deck(): void {

		// ── Auth & nonce ──────────────────────────────────────────────────
		// BUG-10 FIX: unified to 'tw_deck_nonce' (matches twGameConfig.nonce)
		if ( ! check_ajax_referer( 'tw_deck_nonce', 'nonce', false ) ) {
			wp_send_json_error( 'Invalid nonce', 403 );
			return;
		}

		$current_user_id = get_current_user_id();
		if ( ! $current_user_id ) {
			wp_send_json_error( 'Not logged in', 401 );
			return;
		}

		// ── Character ownership check ──────────────────────────────────
		$char_id = sanitize_text_field( wp_unslash( $_POST['character_id'] ?? '' ) );
		if ( empty( $char_id ) ) {
			wp_send_json_error( 'Missing character_id', 400 );
			return;
		}

		// Verify character belongs to current user via direct Supabase query.
		// tw_get_user_characters() does not exist in this codebase.
		$base     = function_exists( 'tw_supabase_url' ) ? trailingslashit( tw_supabase_url() ) . 'rest/v1/' : '';
		$anon_key = function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '';

		if ( ! $base || ! $anon_key ) {
			wp_send_json_error( 'Supabase config missing', 500 );
			return;
		}

		$char_check_url = add_query_arg( [
			'id'         => 'eq.' . $char_id,
			'wp_user_id' => 'eq.' . $current_user_id,
			'select'     => 'id',
			'limit'      => 1,
		], $base . 'cyber_characters' );

		$char_resp = wp_remote_get( $char_check_url, [
			'headers' => [
				'apikey'        => $anon_key,
				'Authorization' => 'Bearer ' . $anon_key,
				'Content-Type'  => 'application/json',
			],
			'timeout' => 10,
		] );

		if ( is_wp_error( $char_resp ) || wp_remote_retrieve_response_code( $char_resp ) >= 300 ) {
			wp_send_json_error( 'Character lookup failed', 500 );
			return;
		}

		$char_rows = json_decode( wp_remote_retrieve_body( $char_resp ), true ) ?: [];
		if ( empty( $char_rows[0]['id'] ) ) {
			wp_send_json_error( 'Access denied', 403 );
			return;
		}

		// ── Parse active ids ───────────────────────────────────────────────
		// cyber_character_deck.id is UUID — sanitize each value with nw_sanitize_uuid(),
		// then discard any empty strings (= invalid UUIDs rejected by sanitizer).
		$raw_active = (string) ( $_POST['active'] ?? '' );
		$active_ids = array_values(
			array_filter(
				array_map(
					static fn( string $v ): string => function_exists( 'nw_sanitize_uuid' )
						? nw_sanitize_uuid( trim( $v ) )
						: trim( $v ),
					explode( ',', $raw_active )
				)
			)
		);

		// ── Validate limit 20–50 ─────────────────────────────────────────────
		$total = count( $active_ids );
		if ( $total < 20 || $total > 50 ) {
			wp_send_json_error(
				sprintf( 'Active deck must have 20–50 cards. You sent %d.', $total ),
				400
			);
			return;
		}

		// ── Verify cards belong to this character ─────────────────────────
		$all_assigned = function_exists( 'tw_supabase_get' )
			? tw_supabase_get( 'cyber_character_deck', [
				'character_id' => 'eq.' . $char_id,
				'select'       => 'id',
			] )
			: [];

		if ( ! is_array( $all_assigned ) ) {
			wp_send_json_error( 'Could not load character deck', 500 );
			return;
		}

		$all_deck_ids = array_map( static fn( $r ) => (string) $r['id'], $all_assigned );
		$valid_active = array_values( array_intersect( $active_ids, $all_deck_ids ) );

		if ( count( $valid_active ) < 20 ) {
			wp_send_json_error(
				sprintf( 'Not enough valid cards after ownership check (%d).', count( $valid_active ) ),
				400
			);
			return;
		}

		// ── Call RPC cyber_sync_deck ───────────────────────────────────────
		if ( ! function_exists( 'tw_supabase_rpc' ) ) {
			wp_send_json_error( 'tw_supabase_rpc() not available', 500 );
			return;
		}

		$result = tw_supabase_rpc( 'cyber_sync_deck', [
			'p_character_id' => $char_id,
			'p_active_ids'   => $valid_active,
		] );

		// tw_supabase_rpc() returns WP_Error on failure, never boolean false.
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				'Sync failed — ' . $result->get_error_message(),
				500
			);
			return;
		}

		wp_send_json_success( [
			'msg'   => 'Deck synced.',
			'total' => count( $valid_active ),
		] );
	}

}
