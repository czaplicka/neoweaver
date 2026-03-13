<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TALE WEAVER – AJAX: get_char_state
 * Pobiera hp i mp postaci z Supabase (cyber_state_of_the_campaign).
 * Dostępny tylko dla zalogowanych użytkowników (wp_ajax_).
 */
add_action( 'wp_ajax_get_char_state', 'tw_get_char_state' );

function tw_get_char_state() {
	// 1. Sprawdź czy funkcje CORE istnieją.
	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
		error_log( 'Tale Weaver Error: CORE functions not found in get_char_state' );
		wp_send_json_error( 'Core functions missing' );
	}

	// 2. Nonce.
	if ( ! check_ajax_referer( 'tw_ajax_nonce', 'nonce', false ) ) {
		wp_send_json_error( 'Security check failed' );
	}

	// 3. Zalogowany użytkownik.
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		wp_send_json_error( 'Not logged in' );
	}

	$anon_key     = tw_supabase_anon_key();
	$supabase_url = tw_supabase_url();

	if ( empty( $supabase_url ) || empty( $anon_key ) ) {
		wp_send_json_error( 'Supabase config missing' );
	}

	$supabase_base = trailingslashit( $supabase_url ) . 'rest/v1/';

	// 4. Zapytanie do Supabase — najnowszy stan postaci.
	$url = add_query_arg(
		array(
			'wp_user_id' => 'eq.' . (int) $user_id,
			'select'     => 'hp,mp',
			'order'      => 'created_at.desc',
			'limit'      => 1,
		),
		$supabase_base . 'cyber_state_of_the_campaign'
	);

	$response = wp_remote_get( $url, array(
		'headers' => array(
			'apikey'        => $anon_key,
			'Authorization' => 'Bearer ' . $anon_key,
			'Content-Type'  => 'application/json',
		),
		'timeout'   => 10,
		'sslverify' => true,
	) );

	if ( is_wp_error( $response ) ) {
		error_log( 'TW Supabase error: ' . $response->get_error_message() );
		wp_send_json_error( 'Connection failed' );
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	if ( $status_code !== 200 ) {
		error_log( 'TW Supabase HTTP ' . $status_code );
		wp_send_json_error( 'Server error: ' . $status_code );
	}

	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( json_last_error() !== JSON_ERROR_NONE ) {
		wp_send_json_error( 'Invalid JSON response' );
	}

	if ( ! is_array( $data ) || empty( $data ) || ! isset( $data[0] ) ) {
		wp_send_json_error( 'No state found' );
	}

	wp_send_json_success( $data[0] );
}
