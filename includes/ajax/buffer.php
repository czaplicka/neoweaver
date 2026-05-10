<?php
/**
 * NeoWeave - AJAX & RPC Buffer Handlers
 *
 * BUG-FIX: The entire file previously used undefined SUPABASE_URL / SUPABASE_KEY
 * constants (these are never defined in wp-config.php — only the tw_supabase_url()
 * / tw_supabase_anon_key() helpers exist). Every request therefore fatalled with
 * "Use of undefined constant SUPABASE_URL".
 *
 * Additional fixes applied:
 *   - cyber_call_rpc() and cyber_update_supabase_location() now use wp_remote_*
 *     instead of raw curl, matching the project-wide HTTP pattern.
 *   - Missing ABSPATH guard added.
 *   - handle_use_buffer_card() had no nonce check and no ownership validation;
 *     any logged-in player could discard another character's card by supplying
 *     a known instance_id. Nonce + character ownership check added.
 *   - Missing return after wp_send_json_error() calls added.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Helper: resolve character_id from WP user ───────────────────────────────

if ( ! function_exists( 'get_cyber_character_id_by_wp_id' ) ) {
	function get_cyber_character_id_by_wp_id( int $wp_user_id ): string {
		if ( ! function_exists( 'get_user_game_data_from_supabase' ) ) {
			return '';
		}
		$game_data = get_user_game_data_from_supabase( $wp_user_id );
		return (string) ( $game_data['active_character_id'] ?? '' );
	}
}

// ─── Helper: call a Supabase RPC function ────────────────────────────────────

if ( ! function_exists( 'cyber_call_rpc' ) ) {
	/**
	 * Call a Supabase RPC function via wp_remote_post().
	 *
	 * BUG-FIX: was using curl with undefined SUPABASE_URL / SUPABASE_KEY.
	 * Now uses tw_supabase_url() / tw_supabase_anon_key() and wp_remote_post().
	 *
	 * @param string $function_name  RPC function name (no prefix).
	 * @param array  $params         JSON-encodable parameters.
	 * @return array|null            Decoded response array, or null on error.
	 */
	function cyber_call_rpc( string $function_name, array $params = [] ): ?array {
		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
			error_log( 'TW cyber_call_rpc: Supabase helpers not available.' );
			return null;
		}

		$key      = tw_supabase_anon_key();
		$endpoint = trailingslashit( tw_supabase_url() ) . 'rest/v1/rpc/' . $function_name;

		$response = wp_remote_post( $endpoint, [
			'headers' => [
				'Content-Type'  => 'application/json',
				'apikey'        => $key,
				'Authorization' => 'Bearer ' . $key,
			],
			'body'    => wp_json_encode( $params ),
			'timeout' => 20,
		] );

		if ( is_wp_error( $response ) ) {
			error_log( 'TW cyber_call_rpc error [' . $function_name . ']: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			error_log( 'TW cyber_call_rpc HTTP ' . $code . ' [' . $function_name . ']: ' . wp_remote_retrieve_body( $response ) );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : null;
	}
}

// ─── Helper: PATCH a card's location in cyber_character_buffer ───────────────

if ( ! function_exists( 'cyber_update_supabase_location' ) ) {
	/**
	 * Update the location field of a buffer card row.
	 *
	 * BUG-FIX: was using curl with undefined SUPABASE_URL / SUPABASE_KEY.
	 * Now uses tw_supabase_url() / tw_supabase_anon_key() and wp_remote_request().
	 *
	 * @param string $instance_id  UUID of the cyber_character_buffer row.
	 * @param string $location     New location value (e.g. 'discard', 'hand').
	 * @return bool  true on success, false on failure.
	 */
	function cyber_update_supabase_location( string $instance_id, string $location ): bool {
		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
			error_log( 'TW cyber_update_supabase_location: Supabase helpers not available.' );
			return false;
		}

		$key      = tw_supabase_anon_key();
		$endpoint = trailingslashit( tw_supabase_url() )
			. 'rest/v1/cyber_character_buffer?id=eq.'
			. preg_replace( '/[^a-zA-Z0-9\-]/', '', $instance_id );

		$response = wp_remote_request( $endpoint, [
			'method'  => 'PATCH',
			'headers' => [
				'Content-Type'  => 'application/json',
				'apikey'        => $key,
				'Authorization' => 'Bearer ' . $key,
				'Prefer'        => 'return=minimal',
			],
			'body'    => wp_json_encode( [ 'location' => sanitize_text_field( $location ) ] ),
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) ) {
			error_log( 'TW cyber_update_supabase_location error: ' . $response->get_error_message() );
			return false;
		}

		return wp_remote_retrieve_response_code( $response ) < 300;
	}
}

// ─── 1. DECK BUILDER SYNC ────────────────────────────────────────────────────

add_action( 'wp_ajax_save_cyber_deck_rpc', 'handle_save_cyber_deck_rpc' );

function handle_save_cyber_deck_rpc(): void {
	check_ajax_referer( 'cyber_deck_nonce', 'nonce' );

	$user_id      = get_current_user_id();
	$character_id = get_cyber_character_id_by_wp_id( $user_id );
	$active_ids   = json_decode( stripslashes( $_POST['active_ids'] ?? '[]' ), true );

	if ( ! $character_id || ! is_array( $active_ids ) ) {
		wp_send_json_error( 'Invalid character or data.' );
		return;
	}

	$result = cyber_call_rpc( 'cyber_sync_deck', [
		'p_character_id' => $character_id,
		'p_active_ids'   => $active_ids,
	] );

	wp_send_json_success( $result );
}

// ─── 2. USE CARD & DRAW NEW ──────────────────────────────────────────────────

add_action( 'wp_ajax_use_buffer_card', 'handle_use_buffer_card' );

function handle_use_buffer_card(): void {
	// BUG-FIX: no nonce check in original — any logged-in user could discard
	// any card by sending a known instance_id. Nonce enforced here.
	check_ajax_referer( 'use_card_nonce', 'nonce' );

	$user_id      = get_current_user_id();
	$character_id = get_cyber_character_id_by_wp_id( $user_id );

	if ( ! $character_id ) {
		wp_send_json_error( 'No active character found.' );
		return;
	}

	$instance_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) ( $_POST['instance_id'] ?? '' ) );

	if ( ! $instance_id ) {
		wp_send_json_error( 'Invalid instance_id.' );
		return;
	}

	// Ownership check: confirm the buffer card belongs to this character.
	$ownership = tw_supabase_get(
		'cyber_character_buffer',
		[
			'id'           => 'eq.' . $instance_id,
			'character_id' => 'eq.' . $character_id,
			'select'       => 'id',
			'limit'        => 1,
		]
	);

	if ( empty( $ownership ) ) {
		wp_send_json_error( 'Card not found or not owned by current character.' );
		return;
	}

	// Mark card as discarded, then draw a new one via RPC.
	cyber_update_supabase_location( $instance_id, 'discard' );

	$new_card_data = cyber_call_rpc( 'cyber_sync_draw', [ 'p_character_id' => $character_id ] );

	if ( ! empty( $new_card_data ) ) {
		wp_send_json_success( $new_card_data[0] );
	} else {
		wp_send_json_error( 'No cards left to draw even after reshuffle.' );
	}
}

// ─── 3. FOUNDRY UPGRADE ──────────────────────────────────────────────────────

add_action( 'wp_ajax_foundry_upgrade', 'handle_foundry_upgrade' );

function handle_foundry_upgrade(): void {
	check_ajax_referer( 'foundry_nonce', 'nonce' );

	$user_id      = get_current_user_id();
	$character_id = get_cyber_character_id_by_wp_id( $user_id );

	if ( ! $character_id ) {
		wp_send_json_error( 'Character not found.' );
		return;
	}

	$instance_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) ( $_POST['instance_id'] ?? '' ) );

	if ( ! $instance_id ) {
		wp_send_json_error( 'Invalid instance_id.' );
		return;
	}

	$data = cyber_call_rpc( 'cyber_upgrade_buffer_card', [
		'p_character_id' => $character_id,
		'p_instance_id'  => $instance_id,
	] );

	if ( isset( $data['status'] ) && $data['status'] === 'success' ) {
		wp_send_json_success( [
			'message'   => $data['message'],
			'new_level' => $data['new_level'],
		] );
	} else {
		wp_send_json_error( $data['message'] ?? 'Upgrade failed.' );
	}
}
