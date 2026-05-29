<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NEOWEAVER – AJAX: get_char_state
 *
 * Two actions:
 *
 * 1. tw_get_char_state_active
 *    For the GAME HUD — fetches hp/mp from cyber_state_of_the_campaign
 *    for the character that is currently in an ACTIVE (not paused) session.
 *    Fails fast if there is no active session.
 *
 * 2. tw_get_char_state_profile
 *    For CHARACTER SELECT lists — fetches base stats from cyber_characters.
 *    Does NOT require an active game session.
 */

// ──────────────────────────────────────────────────────────────────────────────
// 1. ACTIVE GAME HUD — hp/mp from cyber_state_of_the_campaign
// ──────────────────────────────────────────────────────────────────────────────
add_action( 'wp_ajax_tw_get_char_state_active', 'tw_get_char_state_active_handler' );

function tw_get_char_state_active_handler(): void {
	if ( ! function_exists( 'tw_supabase_get' ) ) {
		wp_send_json_error( 'Core functions missing', 500 );
		return;
	}

	check_ajax_referer( 'tw_adventure_nonce', 'nonce' );

	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		wp_send_json_error( 'Not logged in', 401 );
		return;
	}

	// Resolve active session (status = active only, NOT paused).
	$sessions = tw_supabase_get(
		'cyber_game_sessions',
		[
			'wp_user_id' => 'eq.' . $user_id,
			'status'     => 'eq.active',
			'select'     => 'character_id,campaign_id',
			'order'      => 'updated_at.desc',
			'limit'      => 1,
		]
	);

	if ( is_wp_error( $sessions ) || empty( $sessions[0] ) ) {
		wp_send_json_error( 'No active session', 404 );
		return;
	}

	$character_id = $sessions[0]['character_id'] ?? '';
	$campaign_id  = $sessions[0]['campaign_id']  ?? '';

	if ( empty( $character_id ) || empty( $campaign_id ) ) {
		wp_send_json_error( 'Session missing character or campaign', 422 );
		return;
	}

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

	if ( is_wp_error( $data ) || empty( $data[0] ) ) {
		wp_send_json_error( 'No state found', 404 );
		return;
	}

	wp_send_json_success( $data[0] );
}

// ──────────────────────────────────────────────────────────────────────────────
// 2. CHARACTER SELECT / PROFILE — base data from cyber_characters
// ──────────────────────────────────────────────────────────────────────────────
add_action( 'wp_ajax_tw_get_char_state_profile', 'tw_get_char_state_profile_handler' );

function tw_get_char_state_profile_handler(): void {
	if ( ! function_exists( 'tw_supabase_get' ) ) {
		wp_send_json_error( 'Core functions missing', 500 );
		return;
	}

	check_ajax_referer( 'tw_adventure_nonce', 'nonce' );

	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		wp_send_json_error( 'Not logged in', 401 );
		return;
	}

	// Optional: filter by specific character_id passed from JS.
	$character_id = isset( $_POST['character_id'] )
		? preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $_POST['character_id'] )
		: '';

	$params = [
		'wp_user_id' => 'eq.' . $user_id,
		'select'     => 'id,name,avatar_url,class,level,hp,mp,world_id',
		'order'      => 'name.asc',
	];

	if ( ! empty( $character_id ) ) {
		$params['id'] = 'eq.' . $character_id;
		$params['limit'] = 1;
	}

	// Optional: filter by world_id.
	$world_id = isset( $_POST['world_id'] )
		? preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $_POST['world_id'] )
		: '';

	if ( ! empty( $world_id ) ) {
		$params['world_id'] = 'eq.' . $world_id;
	}

	$data = tw_supabase_get( 'cyber_characters', $params );

	if ( is_wp_error( $data ) ) {
		wp_send_json_error( 'Supabase error: ' . $data->get_error_message(), 502 );
		return;
	}

	if ( empty( $data ) ) {
		wp_send_json_error( 'No characters found', 404 );
		return;
	}

	// If single character requested, return object; otherwise return array.
	wp_send_json_success( ! empty( $character_id ) ? $data[0] : $data );
}
