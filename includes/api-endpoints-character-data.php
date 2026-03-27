<?php
/**
 * api-endpoints-character-data.php
 *
 * REST routes that serve lookup data for the character creator wizard:
 *   GET /wp-json/neoweaver/v1/races    → rows from cyber_races
 *   GET /wp-json/neoweaver/v1/classes  → rows from cyber_classes
 *
 * Why server-side proxy instead of direct browser → Supabase fetch?
 * Supabase RLS blocks anonymous browser requests to cyber_races / cyber_classes
 * because those tables have no public SELECT policy. PHP runs server-side
 * with the anon key and bypasses that restriction cleanly.
 *
 * Both endpoints:
 *  - Are PUBLIC (no login required — race/class lists are world-building
 *    reference data visible on the character creation page before login).
 *  - Cache results for 5 minutes via a WordPress transient to avoid
 *    hammering Supabase on every page load.
 *  - Return only safe, public columns: id, name, description
 *    (the `icon` column does NOT exist in cyber_races / cyber_classes).
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
 * Uses tw_supabase_get() (the shared server-side helper) instead of raw
 * wp_remote_get so we benefit from its error handling and header setup.
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

	if ( ! function_exists( 'tw_supabase_get' ) ) {
		return new WP_Error( 'config_missing', 'Supabase helpers not loaded.', [ 'status' => 500 ] );
	}

	$data = tw_supabase_get(
		$table,
		[
			'select' => $select_cols,
			'order'  => $order,
		]
	);

	// tw_supabase_get returns [] on error (it logs internally).
	// We can't distinguish an empty table from a fetch error here, but
	// we do NOT cache an empty result so a retry is possible.
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'supabase_error', 'Database error.', [ 'status' => 500 ] );
	}

	if ( ! empty( $data ) ) {
		set_transient( $cache_key, $data, $ttl );
	}

	return $data;
}

// ---------------------------------------------------------------------------
// GET /wp-json/neoweaver/v1/races
// ---------------------------------------------------------------------------

function neoweaver_get_races( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	// BUG-FIX: removed `icon` from select — that column does not exist in
	// cyber_races. Querying a non-existent column causes a Supabase 400
	// which tw_supabase_get logs as an error and returns [].
	$data = nw_fetch_lookup_table( 'cyber_races', 'id,name,description' );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	return rest_ensure_response( $data );
}

// ---------------------------------------------------------------------------
// GET /wp-json/neoweaver/v1/classes
// ---------------------------------------------------------------------------

function neoweaver_get_classes( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	// BUG-FIX: removed `icon` from select — same reason as races above.
	$data = nw_fetch_lookup_table( 'cyber_classes', 'id,name,description' );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	return rest_ensure_response( $data );
}

// ---------------------------------------------------------------------------
// Route registration
// ---------------------------------------------------------------------------

add_action( 'rest_api_init', function () {

	register_rest_route( 'neoweaver/v1', '/races', [
		'methods'             => 'GET',
		'callback'            => 'neoweaver_get_races',
		// BUG-FIX: was neoweaver_user_can_play (requires login).
		// Race/class data is public reference data shown BEFORE the user
		// logs in (character creator wizard step 1). Changed to __return_true.
		'permission_callback' => '__return_true',
	] );

	register_rest_route( 'neoweaver/v1', '/classes', [
		'methods'             => 'GET',
		'callback'            => 'neoweaver_get_classes',
		'permission_callback' => '__return_true',
	] );

} );
