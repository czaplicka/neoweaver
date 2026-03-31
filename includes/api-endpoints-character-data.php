<?php
/**
 * api-endpoints-character-data.php
 *
 * REST routes that serve lookup data for the character creator wizard:
 *   GET /wp-json/neoweaver/v1/races            → base races (parent_race IS NULL)
 *   GET /wp-json/neoweaver/v1/subraces?parent= → subraces for a given parent name
 *   GET /wp-json/neoweaver/v1/classes          → rows from cyber_classes
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NW_UPLOADS_BASE', 'https://neoweaver.nieodparady.pl/wp-content/uploads/' );

/**
 * Prepends uploads base URL to img_url if it's a relative filename.
 */
function nw_resolve_img_urls( array $rows ): array {
	return array_map( function ( $row ) {
		if ( ! empty( $row['img_url'] ) && strpos( $row['img_url'], 'http' ) !== 0 ) {
			$row['img_url'] = NW_UPLOADS_BASE . $row['img_url'];
		}
		return $row;
	}, $rows );
}

/**
 * Fetch rows from a cyber_ lookup table with transient caching.
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

	if ( ! is_array( $data ) ) {
		return new WP_Error( 'supabase_error', 'Database error.', [ 'status' => 500 ] );
	}

	if ( ! empty( $data ) ) {
		set_transient( $cache_key, $data, $ttl );
	}

	return $data;
}

// ---------------------------------------------------------------------------
// GET /wp-json/neoweaver/v1/races  — only base races (parent_race IS NULL)
// ---------------------------------------------------------------------------

function neoweaver_get_races( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	if ( ! function_exists( 'tw_supabase_get' ) ) {
		return new WP_Error( 'config_missing', 'Supabase helpers not loaded.', [ 'status' => 500 ] );
	}

	$cache_key = 'nw_base_races';
	$cached    = get_transient( $cache_key );
	if ( $cached !== false ) {
		return rest_ensure_response( $cached );
	}

	$data = tw_supabase_get(
		'cyber_races',
		[
			'select'      => 'id,name,description,tags,img_url',
			'parent_race' => 'is.null',
			'order'       => 'name.asc',
		]
	);

	if ( ! is_array( $data ) ) {
		return new WP_Error( 'supabase_error', 'Database error.', [ 'status' => 500 ] );
	}

	$data = nw_resolve_img_urls( $data );

	if ( ! empty( $data ) ) {
		set_transient( $cache_key, $data, 300 );
	}

	return rest_ensure_response( $data );
}

// ---------------------------------------------------------------------------
// GET /wp-json/neoweaver/v1/subraces?parent=<name>
// ---------------------------------------------------------------------------

function neoweaver_get_subraces( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$parent = sanitize_text_field( $request->get_param( 'parent' ) );

	if ( empty( $parent ) ) {
		return new WP_Error( 'missing_param', 'parent parameter required.', [ 'status' => 400 ] );
	}

	if ( ! function_exists( 'tw_supabase_get' ) ) {
		return new WP_Error( 'config_missing', 'Supabase helpers not loaded.', [ 'status' => 500 ] );
	}

	$cache_key = 'nw_subraces_' . md5( $parent );
	$cached    = get_transient( $cache_key );
	if ( $cached !== false ) {
		return rest_ensure_response( $cached );
	}

	$data = tw_supabase_get(
		'cyber_races',
		[
			'select'      => 'id,name,description,tags,img_url',
			'parent_race' => 'eq.' . $parent,
			'order'       => 'name.asc',
		]
	);

	if ( ! is_array( $data ) ) {
		return new WP_Error( 'supabase_error', 'Database error.', [ 'status' => 500 ] );
	}

	$data = nw_resolve_img_urls( $data );

	if ( ! empty( $data ) ) {
		set_transient( $cache_key, $data, 300 );
	}

	return rest_ensure_response( $data );
}

// ---------------------------------------------------------------------------
// GET /wp-json/neoweaver/v1/classes
// ---------------------------------------------------------------------------

function neoweaver_get_classes( WP_REST_Request $request ): WP_REST_Response|WP_Error {
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
		'permission_callback' => '__return_true',
	] );

	register_rest_route( 'neoweaver/v1', '/subraces', [
		'methods'             => 'GET',
		'callback'            => 'neoweaver_get_subraces',
		'permission_callback' => '__return_true',
		'args'                => [
			'parent' => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
		],
	] );

	register_rest_route( 'neoweaver/v1', '/classes', [
		'methods'             => 'GET',
		'callback'            => 'neoweaver_get_classes',
		'permission_callback' => '__return_true',
	] );

} );
