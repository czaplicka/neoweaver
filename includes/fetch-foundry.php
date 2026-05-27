<?php
/**
 * fetch-foundry.php
 *
 * Thin wrapper — all actual Foundry/Supabase fetching lives in supabase-helpers.php.
 * This file exists so the $always loader in neoweaver-wp-core.php doesn't silently skip it,
 * and as the canonical place for any future Foundry-specific fetch helpers.
 *
 * If you need a new fetch function specific to the Foundry (world nodes, node data, etc.),
 * add it here rather than polluting supabase-helpers.php.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetch a single node (world location) from Supabase by its UUID.
 *
 * @param string $node_id  UUID of the node.
 * @return array|null      Row array or null on failure.
 */
function nw_fetch_node( string $node_id ): ?array {
	$base = function_exists( 'nw_supabase_base' ) ? nw_supabase_base() : '';
	if ( ! $base || ! $node_id ) {
		return null;
	}

	$safe = preg_replace( '/[^a-zA-Z0-9\-]/', '', $node_id );
	$url  = add_query_arg( [
		'id'     => 'eq.' . $safe,
		'select' => '*',
		'limit'  => 1,
	], $base . 'cyber_nodes' );

	$res  = wp_remote_get( $url, [
		'headers' => function_exists( 'nw_supabase_service_headers' ) ? nw_supabase_service_headers() : [],
		'timeout' => 10,
	] );

	if ( is_wp_error( $res ) ) {
		error_log( 'nw_fetch_node — error: ' . $res->get_error_message() );
		return null;
	}

	$rows = json_decode( wp_remote_retrieve_body( $res ), true );
	return ! empty( $rows[0] ) ? $rows[0] : null;
}

/**
 * Fetch all nodes belonging to a world.
 *
 * @param string $world_id  UUID of the world.
 * @return array            Array of node rows (may be empty).
 */
function nw_fetch_nodes_by_world( string $world_id ): array {
	$base = function_exists( 'nw_supabase_base' ) ? nw_supabase_base() : '';
	if ( ! $base || ! $world_id ) {
		return [];
	}

	$safe = preg_replace( '/[^a-zA-Z0-9\-]/', '', $world_id );
	$url  = add_query_arg( [
		'world_id' => 'eq.' . $safe,
		'select'   => 'id,name,description,node_type,created_at',
		'order'    => 'created_at.asc',
	], $base . 'cyber_nodes' );

	$res  = wp_remote_get( $url, [
		'headers' => function_exists( 'nw_supabase_service_headers' ) ? nw_supabase_service_headers() : [],
		'timeout' => 10,
	] );

	if ( is_wp_error( $res ) ) {
		error_log( 'nw_fetch_nodes_by_world — error: ' . $res->get_error_message() );
		return [];
	}

	return json_decode( wp_remote_retrieve_body( $res ), true ) ?: [];
}
