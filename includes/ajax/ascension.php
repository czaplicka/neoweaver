<?php
/**
 * ascension.php
 * AJAX handler for Card Ascension.
 * Merges N duplicate cards into 1 upgraded (ascension_level++) card.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'wp_ajax_nw_ascend_card', 'nw_ajax_ascend_card' );

function nw_ajax_ascend_card(): void {
	check_ajax_referer( 'nw_ascension_nonce', 'nonce' );

	$character_id = nw_sanitize_uuid( (string) ( $_POST['character_id'] ?? '' ) );
	$deck_id      = absint( $_POST['deck_id'] ?? 0 );

	if ( ! $character_id || ! $deck_id ) {
		wp_send_json_error( [ 'message' => 'Missing parameters.' ] );
	}

	// Validate user owns this character
	$user_id    = get_current_user_id();
	$characters = tw_supabase_get( 'cyber_characters', [
		'id'        => 'eq.' . nw_sanitize_uuid( $character_id ),
		'wp_user_id' => 'eq.' . $user_id,
		'select'    => 'id',
		'limit'     => 1,
	] );
	if ( empty( $characters ) ) {
		wp_send_json_error( [ 'message' => 'Character not found or not yours.' ] );
	}

	// Fetch all copies of this card for this character (only non-ascended = ascension_level = 0)
	$copies = tw_supabase_get( 'cyber_character_deck', [
		'character_id'   => 'eq.' . nw_sanitize_uuid( $character_id ),
		'deck_id'        => 'eq.' . $deck_id,
		'ascension_level' => 'eq.0',
		'is_locked'      => 'eq.false',
		'select'         => 'id,current_level,current_xp,ascension_level',
		'order'          => 'current_level.desc,current_xp.desc',
	] );

	if ( ! is_array( $copies ) ) {
		wp_send_json_error( [ 'message' => 'Could not fetch cards.' ] );
	}

	$count = count( $copies );

	// Determine required copies for Ascension I (2 cards needed)
	$required = 2;

	if ( $count < $required ) {
		wp_send_json_error( [
			'message' => sprintf( 'Need %d copies for Ascension I. You have %d.', $required, $count ),
		] );
	}

	// Keep the best card (highest level/xp — first in sorted result), consume the rest
	$keeper     = $copies[0];
	$to_delete  = array_slice( $copies, 1, $required - 1 );
	$delete_ids = array_column( $to_delete, 'id' );

	// Delete consumed copies
	foreach ( $delete_ids as $del_id ) {
		nw_supabase_delete( 'cyber_character_deck', (int) $del_id );
	}

	// Upgrade the keeper: ascension_level = 1
	$updated = nw_supabase_patch( 'cyber_character_deck', (int) $keeper['id'], [
		'ascension_level' => 1,
		'updated_at'      => gmdate( 'c' ),
	] );

	if ( ! $updated ) {
		wp_send_json_error( [ 'message' => 'Failed to upgrade card.' ] );
	}

	wp_send_json_success( [
		'message'         => 'Ascension I complete.',
		'ascension_level' => 1,
		'card_id'         => (int) $keeper['id'],
	] );
}

/**
 * Generic Supabase DELETE by row id.
 */
function nw_supabase_delete( string $table, int $row_id ): bool {
	if ( ! function_exists( 'nw_supabase_request' ) ) { return false; }
	$url = nw_supabase_url( $table ) . '?id=eq.' . $row_id;
	$res = nw_supabase_request( 'DELETE', $url, null, [ 'Prefer: return=minimal' ] );
	return $res !== false;
}

/**
 * Generic Supabase PATCH by row id.
 */
function nw_supabase_patch( string $table, int $row_id, array $data ): bool {
	if ( ! function_exists( 'nw_supabase_request' ) ) { return false; }
	$url = nw_supabase_url( $table ) . '?id=eq.' . $row_id;
	$res = nw_supabase_request( 'PATCH', $url, $data, [ 'Prefer: return=minimal' ] );
	return $res !== false;
}

function nw_supabase_url( string $table ): string {
	return rtrim( defined( 'NW_SUPABASE_URL' ) ? NW_SUPABASE_URL : get_option( 'nw_supabase_url', '' ), '/' ) . '/rest/v1/' . $table;
}

/**
 * Low-level Supabase HTTP request.
 */
function nw_supabase_request( string $method, string $url, ?array $body, array $extra_headers = [] ) {
	$service_key = defined( 'NW_SUPABASE_SERVICE_KEY' ) ? NW_SUPABASE_SERVICE_KEY : get_option( 'nw_supabase_service_key', '' );

	$headers = array_merge( [
		'apikey'        => $service_key,
		'Authorization' => 'Bearer ' . $service_key,
		'Content-Type'  => 'application/json',
	], $extra_headers );

	$args = [
		'method'  => $method,
		'headers' => $headers,
		'timeout' => 10,
	];

	if ( $body !== null ) {
		$args['body'] = wp_json_encode( $body );
	}

	$response = wp_remote_request( $url, $args );

	if ( is_wp_error( $response ) ) { return false; }
	$code = wp_remote_retrieve_response_code( $response );
	return ( $code >= 200 && $code < 300 );
}
