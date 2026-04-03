<?php
/**
 * api-endpoints-character-data.php
 *
 * REST routes + wp_ajax handlers that serve lookup data for the character creator wizard.
 *
 * REST endpoints (public):
 *   GET /wp-json/neoweaver/v1/races            → base races (parent_race IS NULL)
 *   GET /wp-json/neoweaver/v1/subraces?parent= → subraces for a given parent name
 *   GET /wp-json/neoweaver/v1/classes          → rows from cyber_classes
 *   GET /wp-json/neoweaver/v1/nodes            → rows from cyber_nodes (worlds)
 *
 * wp_ajax actions (used by character creator JS via fetch + FormData):
 *   neoweaver_get_races
 *   neoweaver_get_subraces
 *   neoweaver_get_classes
 *   neoweaver_get_nodes
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NW_UPLOADS_BASE', 'https://neoweaver.nieodparady.pl/wp-content/uploads/' );

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Prepends uploads base URL to img_url if it’s a relative filename.
 *
 * @param array $rows
 * @return array
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
 *
 * @param string $table
 * @param string $select_cols
 * @param string $order
 * @param int    $ttl  seconds
 * @param array  $extra_filters  additional Supabase filter params
 * @return array|WP_Error
 */
function nw_fetch_lookup_table(
	string $table,
	string $select_cols,
	string $order = 'name.asc',
	int $ttl = 300,
	array $extra_filters = []
) {
	$cache_key = 'nw_lookup_' . md5( $table . $select_cols . $order . serialize( $extra_filters ) );
	$cached    = get_transient( $cache_key );
	if ( $cached !== false ) {
		return $cached;
	}

	if ( ! function_exists( 'tw_supabase_get' ) ) {
		return new WP_Error( 'config_missing', 'Supabase helpers not loaded.', [ 'status' => 500 ] );
	}

	$params = array_merge(
		[
			'select' => $select_cols,
			'order'  => $order,
		],
		$extra_filters
	);

	$data = tw_supabase_get( $table, $params );

	if ( ! is_array( $data ) ) {
		return new WP_Error( 'supabase_error', 'Database error.', [ 'status' => 500 ] );
	}

	if ( ! empty( $data ) ) {
		set_transient( $cache_key, $data, $ttl );
	}

	return $data;
}

/**
 * Map Supabase row keys to the shape expected by JS card renderers.
 * JS expects: { key, label, desc, img, bonus? }
 *
 * @param array  $rows
 * @param string $key_field   Supabase column that becomes ‘key’
 * @param array  $extra_map   additional key => column mappings
 * @return array
 */
function nw_map_card_shape( array $rows, string $key_field = 'name', array $extra_map = [] ): array {
	return array_map( function ( $row ) use ( $key_field, $extra_map ) {
		$item = [
			'id'    => $row['id']    ?? null,
			'key'   => $row[ $key_field ] ?? $row['id'],
			'label' => $row['name']  ?? '',
			'desc'  => $row['description'] ?? '',
			'img'   => $row['img_url']     ?? '',
		];
		foreach ( $extra_map as $js_key => $db_col ) {
			$item[ $js_key ] = $row[ $db_col ] ?? '';
		}
		return $item;
	}, $rows );
}

// ---------------------------------------------------------------------------
// REST: GET /wp-json/neoweaver/v1/races
// ---------------------------------------------------------------------------

function neoweaver_get_races( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	if ( ! function_exists( 'tw_supabase_get' ) ) {
		return new WP_Error( 'config_missing', 'Supabase helpers not loaded.', [ 'status' => 500 ] );
	}

	$cache_key = 'nw_base_races_v2';
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
// REST: GET /wp-json/neoweaver/v1/subraces?parent=<name>
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
			'select'      => 'id,name,description,tags,img_url,bonus',
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
// REST: GET /wp-json/neoweaver/v1/classes
// ---------------------------------------------------------------------------

function neoweaver_get_classes_rest( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$data = nw_fetch_lookup_table( 'cyber_classes', 'id,name,description,img_url' );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	return rest_ensure_response( $data );
}

// ---------------------------------------------------------------------------
// REST: GET /wp-json/neoweaver/v1/nodes  (NEW)
// ---------------------------------------------------------------------------

function neoweaver_get_nodes_rest( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$data = nw_fetch_lookup_table( 'cyber_nodes', 'id,name,description,img_url', 'name.asc', 300 );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$data = nw_resolve_img_urls( $data );
	return rest_ensure_response( $data );
}

// ---------------------------------------------------------------------------
// wp_ajax: neoweaver_get_races  (used by character creator JS)
// ---------------------------------------------------------------------------

function neoweaver_ajax_get_races(): void {
	check_ajax_referer( 'neoweaver_nonce', 'nonce', false );

	$cache_key = 'nw_base_races_v2';
	$cached    = get_transient( $cache_key );

	if ( $cached !== false ) {
		$rows = $cached;
	} else {
		if ( ! function_exists( 'tw_supabase_get' ) ) {
			wp_send_json_error( [ 'message' => 'Supabase helpers not loaded.' ] );
			return;
		}
		$rows = tw_supabase_get(
			'cyber_races',
			[
				'select'      => 'id,name,description,tags,img_url,bonus',
				'parent_race' => 'is.null',
				'order'       => 'name.asc',
			]
		);
		if ( ! is_array( $rows ) ) {
			wp_send_json_error( [ 'message' => 'Database error fetching races.' ] );
			return;
		}
		$rows = nw_resolve_img_urls( $rows );
		if ( ! empty( $rows ) ) {
			set_transient( $cache_key, $rows, 300 );
		}
	}

	// Map to JS card shape: key, label, desc, img, bonus
	wp_send_json_success( nw_map_card_shape( $rows, 'name', [ 'bonus' => 'bonus', 'tags' => 'tags' ] ) );
}
add_action( 'wp_ajax_neoweaver_get_races',        'neoweaver_ajax_get_races' );
add_action( 'wp_ajax_nopriv_neoweaver_get_races', 'neoweaver_ajax_get_races' );

// ---------------------------------------------------------------------------
// wp_ajax: neoweaver_get_subraces
// ---------------------------------------------------------------------------

function neoweaver_ajax_get_subraces(): void {
	check_ajax_referer( 'neoweaver_nonce', 'nonce', false );

	$parent = sanitize_text_field( $_POST['parent'] ?? '' );
	if ( empty( $parent ) ) {
		wp_send_json_error( [ 'message' => 'parent parameter required.' ] );
		return;
	}

	$cache_key = 'nw_subraces_' . md5( $parent );
	$cached    = get_transient( $cache_key );

	if ( $cached !== false ) {
		$rows = $cached;
	} else {
		if ( ! function_exists( 'tw_supabase_get' ) ) {
			wp_send_json_error( [ 'message' => 'Supabase helpers not loaded.' ] );
			return;
		}
		$rows = tw_supabase_get(
			'cyber_races',
			[
				'select'      => 'id,name,description,tags,img_url,bonus',
				'parent_race' => 'eq.' . $parent,
				'order'       => 'name.asc',
			]
		);
		if ( ! is_array( $rows ) ) {
			wp_send_json_error( [ 'message' => 'Database error fetching subraces.' ] );
			return;
		}
		$rows = nw_resolve_img_urls( $rows );
		if ( ! empty( $rows ) ) {
			set_transient( $cache_key, $rows, 300 );
		}
	}

	wp_send_json_success( nw_map_card_shape( $rows, 'name', [ 'bonus' => 'bonus' ] ) );
}
add_action( 'wp_ajax_neoweaver_get_subraces',        'neoweaver_ajax_get_subraces' );
add_action( 'wp_ajax_nopriv_neoweaver_get_subraces', 'neoweaver_ajax_get_subraces' );

// ---------------------------------------------------------------------------
// wp_ajax: neoweaver_get_classes
// ---------------------------------------------------------------------------

function neoweaver_ajax_get_classes(): void {
	check_ajax_referer( 'neoweaver_nonce', 'nonce', false );

	$rows = nw_fetch_lookup_table( 'cyber_classes', 'id,name,description,img_url' );
	if ( is_wp_error( $rows ) ) {
		wp_send_json_error( [ 'message' => $rows->get_error_message() ] );
		return;
	}
	$rows = nw_resolve_img_urls( $rows );
	wp_send_json_success( nw_map_card_shape( $rows ) );
}
add_action( 'wp_ajax_neoweaver_get_classes',        'neoweaver_ajax_get_classes' );
add_action( 'wp_ajax_nopriv_neoweaver_get_classes', 'neoweaver_ajax_get_classes' );

// ---------------------------------------------------------------------------
// wp_ajax: neoweaver_get_nodes  (NEW)
// ---------------------------------------------------------------------------

function neoweaver_ajax_get_nodes(): void {
	check_ajax_referer( 'neoweaver_nonce', 'nonce', false );

	$rows = nw_fetch_lookup_table( 'cyber_nodes', 'id,name,description,img_url', 'name.asc', 300 );
	if ( is_wp_error( $rows ) ) {
		wp_send_json_error( [ 'message' => $rows->get_error_message() ] );
		return;
	}
	$rows = nw_resolve_img_urls( $rows );
	// Nodes need ‘id’ as key (not name) because node_id is stored as integer FK
	wp_send_json_success( nw_map_card_shape( $rows, 'id' ) );
}
add_action( 'wp_ajax_neoweaver_get_nodes',        'neoweaver_ajax_get_nodes' );
add_action( 'wp_ajax_nopriv_neoweaver_get_nodes', 'neoweaver_ajax_get_nodes' );

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
		'callback'            => 'neoweaver_get_classes_rest',
		'permission_callback' => '__return_true',
	] );

	register_rest_route( 'neoweaver/v1', '/nodes', [
		'methods'             => 'GET',
		'callback'            => 'neoweaver_get_nodes_rest',
		'permission_callback' => '__return_true',
	] );

} );
