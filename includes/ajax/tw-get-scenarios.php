<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_tw_get_scenarios_ajax', 'tw_get_scenarios_ajax_handler' );
add_action( 'wp_ajax_nopriv_tw_get_scenarios_ajax', 'tw_get_scenarios_ajax_handler' );

function tw_get_scenarios_ajax_handler() {
	if ( empty( $_POST['campaign_id'] ) ) {
		wp_send_json_error( [ 'message' => 'Missing campaign_id' ] );
	}

	$campaign_id = (int) $_POST['campaign_id'];

	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
		wp_send_json_error( [ 'message' => 'Supabase config missing' ] );
	}

	$supabase_base = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
	$anon_key      = tw_supabase_anon_key();

	$url = add_query_arg(
		[
			'campaign_id' => 'eq.' . $campaign_id,
			'select'      => 'id,name,goal,type,category,difficulty,tags,img_url,is_boss,is_key_arc',
			'order'       => 'created_at.asc',
			'limit'       => 3,
		],
		$supabase_base . 'cyber_scenarios'
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
	wp_send_json_success( $rows );
}
