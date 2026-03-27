<?php
/**
 * api-endpoints-character-data.php
 *
 * REST routes that serve lookup data for the character creator wizard:
 *   GET /wp-json/neoweaver/v1/races    → rows from cyber_races
 *   GET /wp-json/neoweaver/v1/classes  → rows from cyber_classes
 *
 * The JS wizard fetches these directly from Supabase using the anon key,
 * but these endpoints are provided as a server-side fallback and for
 * environments where direct Supabase access is restricted (CORS / RLS).
 *
 * Both endpoints:
 *  - Require the user to be logged in (neoweaver_user_can_play).
 *  - Cache results for 5 minutes via a WordPress transient.
 *  - Return only safe, public columns (id, name, description, icon).
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Shared helper: fetch a lookup table from Supabase with 5 min transient cache.
// ---------------------------------------------------------------------------

/**
 * Fetch rows from a cyber_ lookup table with transient caching.
 *
 * @param string $table        Supabase table name, e.g. 'cyber_races'.
 * @param string $select_cols  Comma-separated column list for the ?select= param.
 * @param string $order        Order param, e.g. 'name.asc'.
 * @param int    $ttl          Cache lifetime in seconds (default 300 = 5 min).
 * @return array|WP_Error
 */
function nw_fetch_lookup_table( string $table, string $select_cols, string $order = 'name.asc', int $ttl = 300 ) {
	$cache_key = 'nw_lookup_' . md5( $table . $select_cols . $order );
	$cached    = get_transient( $cache_key );
	if ( $cached !== false ) {
		return $cached;
	}

	$base = nw_supabase_base();
	if ( ! $base ) {
		return new WP_Error( 'config_missing', 'Supabase config missing.', [ 'status' => 500 ] );
	}

	$url = add_query_arg(
		[
			'select' => $select_cols,
			'order'  => $order,
		],
		$base . $table
	);

	$response = wp_remote_get( $url, [
		'headers' => nw_supabase_headers(),
		'timeout' => 15,
	] );

	if ( is_wp_error( $response ) ) {
		error_log( 'TW Lookup fetch error [' . $table . ']: ' . $response->get_error_message() );
		return new WP_Error( 'supabase_error', 'Database error.', [ 'status' => 500 ] );
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		error_log( 'TW Lookup HTTP ' . $code . ' [' . $table . ']: ' . wp_remote_retrieve_body( $response ) );
		return new WP_Error( 'http_error', 'Supabase HTTP ' . $code, [ 'status' => $code ] );
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'parse_error', 'Invalid response from database.', [ 'status' => 500 ] );
	}

	set_transient( $cache_key, $data, $ttl );
	return $data;
}

// ---------------------------------------------------------------------------
// GET /wp-json/neoweaver/v1/races
// ---------------------------------------------------------------------------

function neoweaver_get_races( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$data = nw_fetch_lookup_table( 'cyber_races', 'id,name,description,icon' );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	return rest_ensure_response( $data );
}

// ---------------------------------------------------------------------------
// GET /wp-json/neoweaver/v1/classes
// ---------------------------------------------------------------------------

function neoweaver_get_classes( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$data = nw_fetch_lookup_table( 'cyber_classes', 'id,name,description,icon' );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	return rest_ensure_response( $data );
}

// ---------------------------------------------------------------------------
// Route registration — hooked inside the existing rest_api_init in api-endpoints.php.
// We add a second add_action here; WordPress merges them correctly.
// ---------------------------------------------------------------------------

add_action( 'rest_api_init', function () {

	register_rest_route( 'neoweaver/v1', '/races', [
		'methods'             => 'GET',
		'callback'            => 'neoweaver_get_races',
		'permission_callback' => 'neoweaver_user_can_play',
	] );

	register_rest_route( 'neoweaver/v1', '/classes', [
		'methods'             => 'GET',
		'callback'            => 'neoweaver_get_classes',
		'permission_callback' => 'neoweaver_user_can_play',
	] );

} );
