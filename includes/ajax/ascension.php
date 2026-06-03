<?php
/**
 * ascension.php
 * AJAX handler for Card Ascension.
 * Merges N duplicate cards into 1 upgraded (ascension_level++) card.
 *
 * Fixes:
 *  - deck_id is a UUID string, not an integer — never cast with absint()
 *  - required copies per tier follow the asc_cost table (2/3/4/5/6), not a hardcoded 2
 *  - add_action guarded with function_exists to prevent double-include fatal
 *  - tw_supabase_get_admin return checked with is_wp_error() before iterating
 *  - guard nw_sanitize_uuid / tw_supabase_get_admin / tw_supabase_request existence
 *    before registering the handler — prevents fatal when include order is wrong
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Bail early (with an error log) if supabase-helpers.php was not loaded yet.
// This prevents a fatal call-to-undefined-function instead of silently failing.
foreach ( [ 'nw_sanitize_uuid', 'tw_supabase_get_admin', 'tw_supabase_request' ] as $_nw_fn ) {
	if ( ! function_exists( $_nw_fn ) ) {
		error_log( 'NeoWeaver ascension.php: required helper ' . $_nw_fn . '() not found — skipping handler registration. Check include order.' );
		return;
	}
}
unset( $_nw_fn );

if ( ! function_exists( 'nw_ajax_ascend_card' ) ) {

	add_action( 'wp_ajax_nw_ascend_card', 'nw_ajax_ascend_card' );

	function nw_ajax_ascend_card(): void {
		check_ajax_referer( 'nw_ascension_nonce', 'nonce' );

		$character_id = nw_sanitize_uuid( (string) ( $_POST['character_id'] ?? '' ) );
		$deck_id      = nw_sanitize_uuid( (string) ( $_POST['deck_id']      ?? '' ) ); // UUID, not int

		if ( ! $character_id || ! $deck_id ) {
			wp_send_json_error( [ 'message' => 'Missing parameters.' ] );
			return;
		}

		// Validate user owns this character (service key)
		$user_id = get_current_user_id();
		if ( ! function_exists( 'tw_user_owns_character' ) || ! tw_user_owns_character( $character_id, $user_id ) ) {
			wp_send_json_error( [ 'message' => 'Character not found or not yours.' ] );
			return;
		}

		// Cost table: next_ascension_level => required base copies
		$asc_cost = [ 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6 ];

		// Fetch current ascension level for this card (look at ascended copies)
		$ascended = tw_supabase_get_admin( 'cyber_character_deck', [
			'character_id'    => 'eq.' . $character_id,
			'deck_id'         => 'eq.' . $deck_id,
			'is_locked'       => 'eq.false',
			'select'          => 'id,ascension_level',
			'order'           => 'ascension_level.desc',
			'limit'           => 1,
		] );

		// BUG 2 FIX: check is_wp_error first — a WP_Error satisfies is_array() === false
		// but we must be explicit to distinguish a real error from an empty result set.
		if ( is_wp_error( $ascended ) ) {
			wp_send_json_error( [ 'message' => 'Could not fetch ascension state: ' . $ascended->get_error_message() ] );
			return;
		}

		$cur_asc  = 0;
		$asc_row  = null;
		if ( is_array( $ascended ) && ! empty( $ascended ) ) {
			$top = $ascended[0];
			if ( (int) ( $top['ascension_level'] ?? 0 ) > 0 ) {
				$cur_asc = (int) $top['ascension_level'];
				$asc_row = $top;
			}
		}

		$next_asc = $cur_asc + 1;

		if ( $next_asc > 5 ) {
			wp_send_json_error( [ 'message' => 'Card is already at maximum Ascension (5).' ] );
			return;
		}

		$required = $asc_cost[ $next_asc ] ?? 999;

		// Fetch all base (non-ascended) copies, best first
		$copies = tw_supabase_get_admin( 'cyber_character_deck', [
			'character_id'    => 'eq.' . $character_id,
			'deck_id'         => 'eq.' . $deck_id,
			'ascension_level' => 'eq.0',
			'is_locked'       => 'eq.false',
			'select'          => 'id,current_level,current_xp',
			'order'           => 'current_level.desc,current_xp.desc',
		] );

		if ( is_wp_error( $copies ) || ! is_array( $copies ) ) {
			wp_send_json_error( [ 'message' => 'Could not fetch cards.' ] );
			return;
		}

		$count = count( $copies );

		if ( $count < $required ) {
			wp_send_json_error( [
				'message' => sprintf(
					'Need %d base copies for Ascension %d. You have %d.',
					$required, $next_asc, $count
				),
			] );
			return;
		}

		// Keep the best card, consume the rest up to $required
		$keeper     = $copies[0];
		$to_delete  = array_slice( $copies, 1, $required - 1 );
		$delete_ids = array_column( $to_delete, 'id' );

		// Delete consumed copies via tw_supabase_request (service key)
		foreach ( $delete_ids as $del_id ) {
			$del_id = nw_sanitize_uuid( (string) $del_id );
			if ( $del_id ) {
				tw_supabase_request( 'DELETE', 'cyber_character_deck', [ 'id' => 'eq.' . $del_id ] );
			}
		}

		// Upgrade keeper: set ascension_level = next_asc
		$keeper_id = nw_sanitize_uuid( (string) ( $keeper['id'] ?? '' ) );
		if ( ! $keeper_id ) {
			wp_send_json_error( [ 'message' => 'Invalid keeper card ID.' ] );
			return;
		}

		$updated = tw_supabase_request(
			'PATCH',
			'cyber_character_deck',
			[ 'id' => 'eq.' . $keeper_id ],
			[
				'ascension_level' => $next_asc,
				'updated_at'      => gmdate( 'c' ),
			],
			[ 'headers' => [ 'Prefer' => 'return=minimal' ] ]
		);

		if ( is_wp_error( $updated ) ) {
			wp_send_json_error( [ 'message' => 'Failed to upgrade card: ' . $updated->get_error_message() ] );
			return;
		}

		wp_send_json_success( [
			'message'         => sprintf( 'Ascension %d complete.', $next_asc ),
			'ascension_level' => $next_asc,
			'card_id'         => $keeper_id,
		] );
	}

}
