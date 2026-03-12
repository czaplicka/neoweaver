<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_tw_get_session_state', 'tw_get_session_state_handler' );
add_action( 'wp_ajax_nopriv_tw_get_session_state', 'tw_get_session_state_handler' );

function tw_get_session_state_handler() {
	if ( empty( $_POST['session_id'] ) ) {
		wp_send_json_error( [ 'message' => 'Missing session_id' ] );
	}

	$session_id = (int) $_POST['session_id'];

	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
		wp_send_json_error( [ 'message' => 'Supabase config missing' ] );
	}

	$supabase_base = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
	$anon_key      = tw_supabase_anon_key();

	$url = add_query_arg(
		[
			'id'     => 'eq.' . $session_id,
			'select' => 'id,campaign_id,character_id,wp_user_id,world_id,location_id,status,chat_channel_id',
			'limit'  => 1,
		],
		$supabase_base . 'cyber_game_sessions'
	);

	$resp = wp_remote_get( $url, [
		'headers' => [
			'apikey'        => $anon_key,
			'Authorization' => 'Bearer ' . $anon_key,
		],
		'timeout' => 10,
	] );

	if ( is_wp_error( $resp ) ) {
		wp_send_json_error( [ 'message' => 'Supabase error', 'error' => $resp->get_error_message() ] );
	}

	$code = wp_remote_retrieve_response_code( $resp );
	if ( $code < 200 || $code >= 300 ) {
		wp_send_json_error( [ 'message' => 'Supabase HTTP ' . $code, 'body' => wp_remote_retrieve_body( $resp ) ] );
	}

	$rows = json_decode( wp_remote_retrieve_body( $resp ), true ) ?: [];
	if ( empty( $rows[0] ) ) {
		wp_send_json_error( [ 'message' => 'Session not found' ] );
	}

	wp_send_json_success( $rows[0] );
}
