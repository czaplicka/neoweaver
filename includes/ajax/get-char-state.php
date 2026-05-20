<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TALE WEAVER – AJAX: get_char_state
 *
 * Fetches hp and mp for the currently active Field Agent from
 * cyber_state_of_the_campaign.
 *
 * BUG-FIX: The original query filtered cyber_state_of_the_campaign by
 * wp_user_id, but that table has no wp_user_id column — it is keyed by
 * character_id. The query therefore returned an empty set for every user,
 * so HP/MP never reached the HUD.
 *
 * Fix: resolve the active character_id via get_user_game_data_from_supabase()
 * and filter by character_id instead.
 *
 * Only registered for logged-in users (wp_ajax_ prefix, no nopriv variant).
 */
add_action( 'wp_ajax_get_char_state', 'tw_get_char_state' );

function tw_get_char_state() {
	// 1. Core function guard.
	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
		error_log( 'Tale Weaver Error: CORE functions not found in get_char_state' );
		wp_send_json_error( 'Core functions missing' );
		return;
	}

	// 2. Nonce.
	if ( ! check_ajax_referer( 'tw_ajax_nonce', 'nonce', false ) ) {
		wp_send_json_error( 'Security check failed' );
		return;
	}

	// 3. Login check.
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		wp_send_json_error( 'Not logged in' );
		return;
	}

	$anon_key     = tw_supabase_anon_key();
	$supabase_url = tw_supabase_url();

	if ( empty( $supabase_url ) || empty( $anon_key ) ) {
		wp_send_json_error( 'Supabase config missing' );
		return;
	}

	// 4. Resolve active character_id.
	// BUG-FIX: cyber_state_of_the_campaign has no wp_user_id column.
	// We must resolve the character first, then filter by character_id.
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

	$supabase_base = trailingslashit( $supabase_url ) . 'rest/v1/';

	// 5. Query cyber_state_of_the_campaign by character_id (correct column).
$url = add_query_arg(
	[
		'character_id' => 'eq.' . $character_id,
		'campaign_id'  => 'eq.' . $campaign_id,
		'select'       => 'hp,mp',
		'order'        => 'created_at.desc',
		'limit'        => 1,
	],
	$supabase_base . 'cyber_state_of_the_campaign'
);

	$response = wp_remote_get( $url, [
		'headers' => [
			'apikey'        => $anon_key,
			'Authorization' => 'Bearer ' . $anon_key,
			'Content-Type'  => 'application/json',
		],
		'timeout'   => 10,
		'sslverify' => true,
	] );

	if ( is_wp_error( $response ) ) {
		error_log( 'TW Supabase error: ' . $response->get_error_message() );
		wp_send_json_error( 'Connection failed' );
		return;
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	if ( $status_code !== 200 ) {
		error_log( 'TW Supabase HTTP ' . $status_code );
		wp_send_json_error( 'Server error: ' . $status_code );
		return;
	}

	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( json_last_error() !== JSON_ERROR_NONE ) {
		wp_send_json_error( 'Invalid JSON response' );
		return;
	}

	if ( ! is_array( $data ) || empty( $data ) || ! isset( $data[0] ) ) {
		wp_send_json_error( 'No state found' );
		return;
	}

	wp_send_json_success( $data[0] );
}
