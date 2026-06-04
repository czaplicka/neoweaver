<?php
/**
 * NeoWeaver — Deployment (campaign ↔ world) REST endpoints
 *
 * GET  /wp-json/neoweaver/v1/campaigns/list-unlinked  – kampanie bez world_id
 * GET  /wp-json/neoweaver/v1/deployments/list         – kampanie z world_id
 * POST /wp-json/neoweaver/v1/deployments/create       – przypisz kampanię do świata
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/supabase-config.php';

add_action( 'rest_api_init', function () {

	// GET campaigns/list-unlinked
	register_rest_route( 'neoweaver/v1', '/campaigns/list-unlinked', [
		'methods'             => 'GET',
		'callback'            => 'nw_campaigns_list_unlinked',
		'permission_callback' => function () { return is_user_logged_in(); },
	] );

	// GET deployments/list
	register_rest_route( 'neoweaver/v1', '/deployments/list', [
		'methods'             => 'GET',
		'callback'            => 'nw_deployments_list',
		'permission_callback' => function () { return is_user_logged_in(); },
	] );

	// POST deployments/create
	register_rest_route( 'neoweaver/v1', '/deployments/create', [
		'methods'             => 'POST',
		'callback'            => 'nw_deployments_create',
		'permission_callback' => function () { return is_user_logged_in(); },
	] );

} );

// ---------------------------------------------------------------------------
// HELPER — headery Supabase (service key)
// ---------------------------------------------------------------------------
function nw_supa_headers( bool $with_content_type = false ): array {
	$key     = tw_supabase_service_key();
	$headers = [
		'apikey'        => $key,
		'Authorization' => 'Bearer ' . $key,
	];
	if ( $with_content_type ) {
		$headers['Content-Type'] = 'application/json';
		$headers['Prefer']       = 'return=minimal';
	}
	return $headers;
}

// HELPER — base URL tabeli
function nw_supa_table( string $table ): string {
	return trailingslashit( tw_supabase_url() ) . 'rest/v1/' . rawurlencode( $table );
}

// ===========================================================================
// GET /campaigns/list-unlinked
// ===========================================================================
function nw_campaigns_list_unlinked( WP_REST_Request $request ) {
	$user_id = get_current_user_id();

	$url = add_query_arg( [
		'wp_user_id' => 'eq.' . $user_id,
		'world_id'   => 'is.null',
		'select'     => 'id,name,world_type,game_mode,created_at',
		'order'      => 'created_at.desc',
	], nw_supa_table( 'cyber_campaign' ) );

	$response = wp_remote_get( $url, [
		'headers' => nw_supa_headers(),
		'timeout' => 15,
	] );

	if ( is_wp_error( $response ) ) {
		error_log( 'NW_CAMPS_UNLINKED: ' . $response->get_error_message() );
		return new WP_Error( 'supabase_error', 'Database connection error.', [ 'status' => 500 ] );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		error_log( 'NW_CAMPS_UNLINKED: HTTP ' . $code . ' — ' . wp_remote_retrieve_body( $response ) );
		return new WP_Error( 'http_error', 'Supabase HTTP ' . $code, [ 'status' => $code ] );
	}

	$camps = json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];
	return rest_ensure_response( [ 'success' => true, 'data' => $camps ] );
}

// ===========================================================================
// GET /deployments/list
// ===========================================================================
function nw_deployments_list( WP_REST_Request $request ) {
	$user_id = get_current_user_id();

	$url = add_query_arg( [
		'wp_user_id' => 'eq.' . $user_id,
		'world_id'   => 'not.is.null',
		'select'     => 'id,name,world_id,world_type,is_active,created_at',
		'order'      => 'created_at.desc',
	], nw_supa_table( 'cyber_campaign' ) );

	$response = wp_remote_get( $url, [
		'headers' => nw_supa_headers(),
		'timeout' => 15,
	] );

	if ( is_wp_error( $response ) ) {
		error_log( 'NW_DEPLOYMENTS_LIST: ' . $response->get_error_message() );
		return new WP_Error( 'supabase_error', 'Database connection error.', [ 'status' => 500 ] );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		error_log( 'NW_DEPLOYMENTS_LIST: HTTP ' . $code . ' — ' . wp_remote_retrieve_body( $response ) );
		return new WP_Error( 'http_error', 'Supabase HTTP ' . $code, [ 'status' => $code ] );
	}

	$rows = json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];
	$rows = array_map( function ( $r ) {
		$r['campaign_id'] = $r['id'];
		return $r;
	}, $rows );

	return rest_ensure_response( [ 'success' => true, 'data' => $rows ] );
}

// ===========================================================================
// POST /deployments/create
// Body: { campaign_id, world_id }
// ===========================================================================
function nw_deployments_create( WP_REST_Request $request ) {
	$user_id = get_current_user_id();

	$campaign_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) ( $request->get_param( 'campaign_id' ) ?? '' ) );
	$world_id    = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) ( $request->get_param( 'world_id' ) ?? '' ) );

	if ( ! $campaign_id || ! $world_id ) {
		return new WP_Error( 'missing_params', 'campaign_id and world_id are required.', [ 'status' => 400 ] );
	}

	// Sprawdź czy kampania należy do usera i nie ma jeszcze świata
	$check_url = add_query_arg( [
		'id'         => 'eq.' . $campaign_id,
		'wp_user_id' => 'eq.' . $user_id,
		'world_id'   => 'is.null',
		'select'     => 'id',
		'limit'      => 1,
	], nw_supa_table( 'cyber_campaign' ) );

	$check = wp_remote_get( $check_url, [ 'headers' => nw_supa_headers(), 'timeout' => 10 ] );
	if ( is_wp_error( $check ) ) {
		return new WP_Error( 'supabase_error', 'Database error.', [ 'status' => 500 ] );
	}
	if ( empty( json_decode( wp_remote_retrieve_body( $check ), true ) ) ) {
		return new WP_Error( 'not_found', 'Campaign not found, not yours, or already linked to a world.', [ 'status' => 404 ] );
	}

	// Sprawdź czy świat należy do usera
	$world_check_url = add_query_arg( [
		'id'         => 'eq.' . $world_id,
		'wp_user_id' => 'eq.' . $user_id,
		'select'     => 'id',
		'limit'      => 1,
	], nw_supa_table( 'cyber_worlds' ) );

	$world_check = wp_remote_get( $world_check_url, [ 'headers' => nw_supa_headers(), 'timeout' => 10 ] );
	if ( is_wp_error( $world_check ) ) {
		return new WP_Error( 'supabase_error', 'Database error.', [ 'status' => 500 ] );
	}
	if ( empty( json_decode( wp_remote_retrieve_body( $world_check ), true ) ) ) {
		return new WP_Error( 'world_not_found', 'World not found or not yours.', [ 'status' => 404 ] );
	}

	// PATCH cyber_campaign SET world_id = $world_id
	$patch_url = add_query_arg( [ 'id' => 'eq.' . $campaign_id ], nw_supa_table( 'cyber_campaign' ) );

	$patch = wp_remote_request( $patch_url, [
		'method'  => 'PATCH',
		'headers' => nw_supa_headers( true ),
		'body'    => wp_json_encode( [ 'world_id' => $world_id ] ),
		'timeout' => 15,
	] );

	if ( is_wp_error( $patch ) ) {
		error_log( 'NW_DEPLOYMENTS_CREATE: PATCH error — ' . $patch->get_error_message() );
		return new WP_Error( 'supabase_error', 'Database error.', [ 'status' => 500 ] );
	}

	$code = (int) wp_remote_retrieve_response_code( $patch );
	if ( $code < 200 || $code >= 300 ) {
		error_log( 'NW_DEPLOYMENTS_CREATE: PATCH HTTP ' . $code . ' — ' . wp_remote_retrieve_body( $patch ) );
		return new WP_Error( 'http_error', 'Supabase HTTP ' . $code, [ 'status' => $code ] );
	}

	error_log( 'NW_DEPLOYMENTS_CREATE: SUCCESS campaign=' . $campaign_id . ' world=' . $world_id );

	return rest_ensure_response( [
		'success' => true,
		'data'    => [
			'campaign_id' => $campaign_id,
			'world_id'    => $world_id,
			'message'     => 'Campaign anchored to world.',
		],
	] );
}
