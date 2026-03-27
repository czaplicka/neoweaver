<?php
/**
 * REST proxy endpoints for public game data (races, classes).
 *
 * These endpoints run server-side so they bypass Supabase RLS restrictions
 * that block anonymous browser requests. They are public (no auth required)
 * because race/class data is world-building reference data.
 *
 * Routes:
 *   GET /wp-json/neoweaver/v1/races
 *   GET /wp-json/neoweaver/v1/classes
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {

	// ── Races ─────────────────────────────────────────────────────────────
	register_rest_route( 'neoweaver/v1', '/races', [
		'methods'             => 'GET',
		'callback'            => 'neoweaver_rest_get_races',
		'permission_callback' => '__return_true', // public read
	] );

	// ── Classes ───────────────────────────────────────────────────────────
	register_rest_route( 'neoweaver/v1', '/classes', [
		'methods'             => 'GET',
		'callback'            => 'neoweaver_rest_get_classes',
		'permission_callback' => '__return_true',
	] );

} );

/**
 * GET /wp-json/neoweaver/v1/races
 * Returns id, name, description from cyber_races, ordered by name.
 */
function neoweaver_rest_get_races( WP_REST_Request $request ): WP_REST_Response {
	if ( ! function_exists( 'tw_supabase_get' ) ) {
		return new WP_REST_Response( [ 'error' => 'Supabase helpers not loaded.' ], 500 );
	}

	$rows = tw_supabase_get(
		'cyber_races',
		[
			'select' => 'id,name,description',
			'order'  => 'name.asc',
		]
	);

	// tw_supabase_get returns [] on error; distinguish from genuinely empty table.
	if ( ! is_array( $rows ) ) {
		return new WP_REST_Response( [ 'error' => 'Failed to fetch races from Supabase.' ], 502 );
	}

	return new WP_REST_Response( $rows, 200 );
}

/**
 * GET /wp-json/neoweaver/v1/classes
 * Returns id, name, description from cyber_classes, ordered by name.
 */
function neoweaver_rest_get_classes( WP_REST_Request $request ): WP_REST_Response {
	if ( ! function_exists( 'tw_supabase_get' ) ) {
		return new WP_REST_Response( [ 'error' => 'Supabase helpers not loaded.' ], 500 );
	}

	$rows = tw_supabase_get(
		'cyber_classes',
		[
			'select' => 'id,name,description',
			'order'  => 'name.asc',
		]
	);

	if ( ! is_array( $rows ) ) {
		return new WP_REST_Response( [ 'error' => 'Failed to fetch classes from Supabase.' ], 502 );
	}

	return new WP_REST_Response( $rows, 200 );
}
