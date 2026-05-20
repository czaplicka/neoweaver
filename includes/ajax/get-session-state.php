<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_tw_get_session_state', 'tw_get_session_state_handler' );
add_action( 'wp_ajax_nopriv_tw_get_session_state', 'tw_get_session_state_handler' );

function tw_get_session_state_handler() {
	// BUG-FIX: missing return after wp_send_json_error() calls — execution
	// fell through to subsequent blocks.
	if ( empty( $_POST['session_id'] ) ) {
		wp_send_json_error( [ 'message' => 'Missing session_id' ] );
		return;
	}

	// BUG-FIX: session_id is a UUID. intval() collapses any UUID to 0.
	$session_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $_POST['session_id'] );

	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
		wp_send_json_error( [ 'message' => 'Supabase config missing' ] );
		return;
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
		return;
	}
$current_user_id = get_current_user_id();

if ( ! $current_user_id ) {
	wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
	return;
}

if ( (int) $rows[0]['wp_user_id'] !== $current_user_id ) {
	wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
	return;
}
	$code = wp_remote_retrieve_response_code( $resp );
	if ( $code < 200 || $code >= 300 ) {
		wp_send_json_error( [ 'message' => 'Supabase HTTP ' . $code, 'body' => wp_remote_retrieve_body( $resp ) ] );
		return;
	}

	$rows = json_decode( wp_remote_retrieve_body( $resp ), true ) ?: [];
	if ( empty( $rows[0] ) ) {
		wp_send_json_error( [ 'message' => 'Session not found' ] );
		return;
	}

	wp_send_json_success( $rows[0] );
}
