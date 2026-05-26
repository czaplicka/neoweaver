<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NEOWEAVER – AJAX: get_char_state
 *
 * Fetches hp and mp for the currently active Field Agent from
 * cyber_state_of_the_campaign.
 *
 * Query by active character_id + campaign_id resolved from
 * get_user_game_data_from_supabase().
 */

add_action( 'wp_ajax_get_char_state', 'tw_get_char_state' );

function tw_get_char_state() {
	// 1. Core function guard.
	if ( ! function_exists( 'tw_supabase_get' ) ) {
		wp_send_json_error( 'Core functions missing' );
		return;
	}

	// 2. Nonce.
	if ( ! check_ajax_referer( 'tw_adventure_nonce', 'nonce', false ) ) {
		wp_send_json_error( 'Security check failed' );
		return;
	}

	// 3. Login check.
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		wp_send_json_error( 'Not logged in' );
		return;
	}

	// 4. Resolve active character_id + campaign_id.
	if ( ! function_exists( 'get_user_game_data_from_supabase' ) ) {
		wp_send_json_error( 'Game data helper missing' );
		return;
	}

	$game_data    = get_user_game_data_from_supabase( $user_id );
	$character_id = $game_data['active_character_id'] ?? '';
	$campaign_id  = $game_data['active_campaign_id'] ?? '';

	if ( empty( $character_id ) ) {
		wp_send_json_error( 'No active character found' );
		return;
	}

	if ( empty( $campaign_id ) ) {
		wp_send_json_error( 'No active campaign found' );
		return;
	}

	// 5. Query cyber_state_of_the_campaign by character_id + campaign_id.
	$data = tw_supabase_get(
		'cyber_state_of_the_campaign',
		[
			'character_id' => 'eq.' . $character_id,
			'campaign_id'  => 'eq.' . $campaign_id,
			'select'       => 'hp,mp',
			'order'        => 'created_at.desc',
			'limit'        => 1,
		]
	);

	if ( is_wp_error( $data ) ) {
		wp_send_json_error( 'Connection failed' );
		return;
	}

	if ( ! is_array( $data ) || empty( $data ) || ! isset( $data[0] ) ) {
		wp_send_json_error( 'No state found' );
		return;
	}

	wp_send_json_success( $data[0] );
}
