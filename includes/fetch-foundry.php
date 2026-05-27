<?php
/**
 * fetch-foundry.php
 * Helper functions for fetching Foundry (item/gear catalog) data from Supabase.
 * Pure PHP — no HTML, no echo. Returns arrays only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetch a single item from cyber_items by UUID.
 *
 * @param string $item_id UUID of the item.
 * @return array Item row or empty array on failure.
 */
function nw_foundry_get_item( string $item_id ): array {
	if ( ! $item_id || ! function_exists( 'tw_supabase_first' ) ) {
		return [];
	}

	return tw_supabase_first(
		'cyber_items',
		[
			'id'     => 'eq.' . nw_sanitize_uuid( $item_id ),
			'select' => 'id,name,description,type,tags,slot,power_value,img_url,rarity,size,mass,stack_limit,is_container',
			'limit'  => 1,
		]
	);
}

/**
 * Fetch all items for a given type (e.g. 'weapon', 'armor', 'consumable').
 *
 * @param string $type  Item type slug.
 * @param int    $limit Max rows to return.
 * @return array List of item rows.
 */
function nw_foundry_get_items_by_type( string $type, int $limit = 50 ): array {
	if ( ! $type || ! function_exists( 'tw_supabase_get' ) ) {
		return [];
	}

	$rows = tw_supabase_get(
		'cyber_items',
		[
			'type'   => 'eq.' . sanitize_text_field( $type ),
			'select' => 'id,name,description,type,tags,slot,power_value,img_url,rarity,size,mass,stack_limit,is_container',
			'order'  => 'name.asc',
			'limit'  => max( 1, min( 200, $limit ) ),
		]
	);

	return is_array( $rows ) ? $rows : [];
}

/**
 * Fetch items matching one or more tags (PostgREST array contains @>).
 *
 * @param array $tags  Array of tag strings.
 * @param int   $limit Max rows.
 * @return array List of item rows.
 */
function nw_foundry_get_items_by_tags( array $tags, int $limit = 50 ): array {
	if ( empty( $tags ) || ! function_exists( 'tw_supabase_get' ) ) {
		return [];
	}

	$encoded = '{' . implode( ',', array_map( 'sanitize_text_field', $tags ) ) . '}';

	$rows = tw_supabase_get(
		'cyber_items',
		[
			'tags'   => 'cs.' . $encoded,
			'select' => 'id,name,description,type,tags,slot,power_value,img_url,rarity,size,mass,stack_limit,is_container',
			'order'  => 'name.asc',
			'limit'  => max( 1, min( 200, $limit ) ),
		]
	);

	return is_array( $rows ) ? $rows : [];
}

/**
 * Fetch spells from cyber_spells.
 *
 * @param int $limit Max rows.
 * @return array List of spell rows.
 */
function nw_foundry_get_spells( int $limit = 50 ): array {
	if ( ! function_exists( 'tw_supabase_get' ) ) {
		return [];
	}

	$rows = tw_supabase_get(
		'cyber_spells',
		[
			'select' => 'id,name,description,tags,cost,effect,spell_type',
			'order'  => 'name.asc',
			'limit'  => max( 1, min( 200, $limit ) ),
		]
	);

	return is_array( $rows ) ? $rows : [];
}
