<?php
/**
 * AJAX handler: tw_save_deck
 * Saves the player's active deck selection via cyber_sync_deck RPC.
 *
 * POST params:
 *   nonce        — wp nonce 'tw_deck_nonce'
 *   character_id — UUID of the character
 *   active       — comma-separated cyber_character_deck.id values (UUIDs)
 *
 * Deck size limits are configurable via filters:
 *   nw_deck_min_size  (default: 20)
 *   nw_deck_max_size  (default: 50)
 */

if ( ! function_exists( 'tw_ajax_save_deck' ) ) {

	add_action( 'wp_ajax_tw_save_deck', 'tw_ajax_save_deck' );

	function tw_ajax_save_deck(): void {

		// ── Auth & nonce ────────────────────────────────────────────
		if ( ! check_ajax_referer( 'tw_deck_nonce', 'nonce', false ) ) {
			wp_send_json_error( 'Invalid nonce', 403 );
			return;
		}

		$current_user_id = get_current_user_id();
		if ( ! $current_user_id ) {
			wp_send_json_error( 'Not logged in', 401 );
			return;
		}

		// ── Character ownership check ─────────────────────────────
		$char_id = sanitize_text_field( wp_unslash( $_POST['character_id'] ?? '' ) );
		if ( empty( $char_id ) ) {
			wp_send_json_error( 'Missing character_id', 400 );
			return;
		}

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

		// ── Parse active ids ──────────────────────────────────────────
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

		// ── BUG 16 FIX: configurable deck size limits ──────────────────
		// Hardcoded 20/50 silently rejects valid decks when game design changes.
		// Use apply_filters() so any plugin/theme can override without touching core.
		$deck_min = (int) apply_filters( 'nw_deck_min_size', 20 );
		$deck_max = (int) apply_filters( 'nw_deck_max_size', 50 );

		$total = count( $active_ids );
		if ( $total < $deck_min || $total > $deck_max ) {
			wp_send_json_error(
				sprintf(
					'Active deck must have %d–%d cards. You sent %d.',
					$deck_min,
					$deck_max,
					$total
				),
				400
			);
			return;
		}

		// ── BUG 15 FIX: verify cards belong to this character AND are in slot=active ─
		// The previous query fetched ALL deck rows for the character including
		// discard, sideboard, and locked slots. A player could submit a locked
		// or discarded card UUID and it would pass the intersection check.
		// Adding slot=eq.active restricts the ownership pool to only cards
		// that are legally playable in an active deck configuration.
		$all_assigned = function_exists( 'tw_supabase_get' )
			? tw_supabase_get( 'cyber_character_deck', [
				'character_id' => 'eq.' . $char_id,
				'slot'         => 'eq.active',
				'select'       => 'id',
			] )
			: [];

		if ( is_wp_error( $all_assigned ) ) {
			wp_send_json_error( 'Could not load character deck: ' . $all_assigned->get_error_message(), 500 );
			return;
		}

		if ( ! is_array( $all_assigned ) ) {
			wp_send_json_error( 'Could not load character deck', 500 );
			return;
		}

		$all_deck_ids = array_map( static fn( $r ) => (string) $r['id'], $all_assigned );
		$valid_active = array_values( array_intersect( $active_ids, $all_deck_ids ) );

		if ( count( $valid_active ) < $deck_min ) {
			wp_send_json_error(
				sprintf( 'Not enough valid active-slot cards after ownership check (%d).', count( $valid_active ) ),
				400
			);
			return;
		}

		// ── Call RPC cyber_sync_deck ───────────────────────────────────
		if ( ! function_exists( 'tw_supabase_rpc' ) ) {
			wp_send_json_error( 'tw_supabase_rpc() not available', 500 );
			return;
		}

		$result = tw_supabase_rpc( 'cyber_sync_deck', [
			'p_character_id' => $char_id,
			'p_active_ids'   => $valid_active,
		] );

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
