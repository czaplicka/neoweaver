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

add_action( 'rest_api_init', function () {

	// GET campaigns/list-unlinked
	register_rest_route( 'neoweaver/v1', '/campaigns/list-unlinked', [
		'methods'             => 'GET',
		'callback'            => 'nw_campaigns_list_unlinked',
		'permission_callback' => 'neoweaver_user_can_play',
	] );

	// GET deployments/list
	register_rest_route( 'neoweaver/v1', '/deployments/list', [
		'methods'             => 'GET',
		'callback'            => 'nw_deployments_list',
		'permission_callback' => 'neoweaver_user_can_play',
	] );

	// POST deployments/create
	register_rest_route( 'neoweaver/v1', '/deployments/create', [
		'methods'             => 'POST',
		'callback'            => 'nw_deployments_create',
		'permission_callback' => 'neoweaver_user_can_play',
	] );

} );

// ===========================================================================
// GET /campaigns/list-unlinked
// Zwraca kampanie usera, które NIE mają world_id (is null)
// ===========================================================================
function nw_campaigns_list_unlinked( WP_REST_Request $request ) {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return new WP_Error( 'unauthorized', 'Unauthorized.', [ 'status' => 401 ] );
	}

	$base = nw_supabase_base();
	if ( ! $base ) {
		return new WP_Error( 'config_missing', 'Supabase config missing.', [ 'status' => 500 ] );
	}

	$url = add_query_arg( [
		'wp_user_id' => 'eq.' . $user_id,
		'world_id'   => 'is.null',
		'is_active'  => 'eq.true',
		'select'     => 'id,name,world_type,game_mode,created_at',
		'order'      => 'created_at.desc',
	], $base . 'cyber_campaign' );

	$response = wp_remote_get( $url, [
		'headers' => nw_supabase_service_headers(),
		'timeout' => 15,
	] );

	if ( is_wp_error( $response ) ) {
		error_log( 'NW_CAMPS_UNLINKED: Supabase error — ' . $response->get_error_message() );
		return new WP_Error( 'supabase_error', 'Database connection error.', [ 'status' => 500 ] );
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		error_log( 'NW_CAMPS_UNLINKED: HTTP ' . $code . ' — ' . wp_remote_retrieve_body( $response ) );
		return new WP_Error( 'http_error', 'Supabase HTTP ' . $code, [ 'status' => $code ] );
	}

	$camps = json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];

	return rest_ensure_response( [ 'success' => true, 'data' => $camps ] );
}

// ===========================================================================
// GET /deployments/list
// Zwraca kampanie usera, które MAją world_id (są już powiązane)
// JS używa tego żeby odfiltrować je z listy do łączenia
// ===========================================================================
function nw_deployments_list( WP_REST_Request $request ) {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return new WP_Error( 'unauthorized', 'Unauthorized.', [ 'status' => 401 ] );
	}

	$base = nw_supabase_base();
	if ( ! $base ) {
		return new WP_Error( 'config_missing', 'Supabase config missing.', [ 'status' => 500 ] );
	}

	$url = add_query_arg( [
		'wp_user_id' => 'eq.' . $user_id,
		'world_id'   => 'not.is.null',
		'select'     => 'id,name,world_id,world_type,is_active,created_at',
		'order'      => 'created_at.desc',
	], $base . 'cyber_campaign' );

	$response = wp_remote_get( $url, [
		'headers' => nw_supabase_service_headers(),
		'timeout' => 15,
	] );

	if ( is_wp_error( $response ) ) {
		error_log( 'NW_DEPLOYMENTS_LIST: Supabase error — ' . $response->get_error_message() );
		return new WP_Error( 'supabase_error', 'Database connection error.', [ 'status' => 500 ] );
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		error_log( 'NW_DEPLOYMENTS_LIST: HTTP ' . $code . ' — ' . wp_remote_retrieve_body( $response ) );
		return new WP_Error( 'http_error', 'Supabase HTTP ' . $code, [ 'status' => $code ] );
	}

	// JS oczekuje campaign_id w wynikach — mapujemy id → campaign_id
	$rows = json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];
	$rows = array_map( function( $r ) {
		$r['campaign_id'] = $r['id'];
		return $r;
	}, $rows );

	return rest_ensure_response( [ 'success' => true, 'data' => $rows ] );
}

// ===========================================================================
// POST /deployments/create
// Przypisuje world_id do kampanii (UPDATE cyber_campaign SET world_id = ?)
// Body: { campaign_id, world_id }
// ===========================================================================
function nw_deployments_create( WP_REST_Request $request ) {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return new WP_Error( 'unauthorized', 'Unauthorized.', [ 'status' => 401 ] );
	}

	$campaign_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) ( $request->get_param( 'campaign_id' ) ?? '' ) );
	$world_id    = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) ( $request->get_param( 'world_id' ) ?? '' ) );

	if ( ! $campaign_id || ! $world_id ) {
		return new WP_Error( 'missing_params', 'campaign_id and world_id are required.', [ 'status' => 400 ] );
	}

	$base = nw_supabase_base();
	if ( ! $base ) {
		return new WP_Error( 'config_missing', 'Supabase config missing.', [ 'status' => 500 ] );
	}

	// Sprawdź czy kampania należy do usera i jeszcze nie ma świata
	$check_url = add_query_arg( [
		'id'         => 'eq.' . $campaign_id,
		'wp_user_id' => 'eq.' . $user_id,
		'world_id'   => 'is.null',
		'select'     => 'id',
		'limit'      => 1,
	], $base . 'cyber_campaign' );

	$check = wp_remote_get( $check_url, [
		'headers' => nw_supabase_service_headers(),
		'timeout' => 10,
	] );

	if ( is_wp_error( $check ) ) {
		return new WP_Error( 'supabase_error', 'Database error.', [ 'status' => 500 ] );
	}

	$check_rows = json_decode( wp_remote_retrieve_body( $check ), true ) ?: [];
	if ( empty( $check_rows ) ) {
		return new WP_Error( 'not_found', 'Campaign not found, not yours, or already linked to a world.', [ 'status' => 404 ] );
	}

	// Sprawdź czy świat istnieje i należy do usera
	$world_check_url = add_query_arg( [
		'id'         => 'eq.' . $world_id,
		'wp_user_id' => 'eq.' . $user_id,
		'select'     => 'id',
		'limit'      => 1,
	], $base . 'cyber_worlds' );

	$world_check = wp_remote_get( $world_check_url, [
		'headers' => nw_supabase_service_headers(),
		'timeout' => 10,
	] );

	if ( is_wp_error( $world_check ) ) {
		return new WP_Error( 'supabase_error', 'Database error.', [ 'status' => 500 ] );
	}

	$world_rows = json_decode( wp_remote_retrieve_body( $world_check ), true ) ?: [];
	if ( empty( $world_rows ) ) {
		return new WP_Error( 'world_not_found', 'World not found or not yours.', [ 'status' => 404 ] );
	}

	// PATCH cyber_campaign SET world_id = $world_id WHERE id = $campaign_id
	$patch_url = add_query_arg( [
		'id' => 'eq.' . $campaign_id,
	], $base . 'cyber_campaign' );

	$patch = wp_remote_request( $patch_url, [
		'method'  => 'PATCH',
		'headers' => nw_supabase_service_headers( true ),
		'body'    => wp_json_encode( [ 'world_id' => $world_id ] ),
		'timeout' => 15,
	] );

	if ( is_wp_error( $patch ) ) {
		error_log( 'NW_DEPLOYMENTS_CREATE: PATCH error — ' . $patch->get_error_message() );
		return new WP_Error( 'supabase_error', 'Database error.', [ 'status' => 500 ] );
	}

	$code = wp_remote_retrieve_response_code( $patch );
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
