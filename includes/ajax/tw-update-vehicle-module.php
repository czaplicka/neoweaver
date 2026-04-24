<?php
/**
 * Vehicle module slot updater.
 *
 * BUG-FIX 1: Used undefined SUPABASE_URL / SUPABASE_KEY constants — fatal error
 * on every request. Replaced with tw_supabase_url() / tw_supabase_anon_key()
 * helpers throughout.
 *
 * BUG-FIX 2: No nonce check — any logged-in user could PATCH any vehicle row
 * by supplying a known vehicle_id. Added check_ajax_referer() and an ownership
 * check that verifies character_id belongs to the current WP user before writing.
 *
 * BUG-FIX 3: Missing return after wp_send_json_error() in neoweave_update_vehicle_module()
 * — execution continued past the error response. Returns added.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_update_vehicle_module', 'neoweave_update_vehicle_module' );

function neoweave_update_vehicle_module(): void {
	// Security: nonce + login check.
	if ( ! check_ajax_referer( 'tw_ajax_nonce', 'nonce', false ) ) {
		wp_send_json_error( 'Security check failed.' );
		return;
	}

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( 'Unauthorized.' );
		return;
	}

	$wp_user_id = get_current_user_id();

	$vehicle_id   = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) ( $_POST['vehicle_id']   ?? '' ) );
	$module_id    = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) ( $_POST['module_id']    ?? '' ) );
	$target_slot  = sanitize_text_field( $_POST['target_slot']  ?? '' );
	$character_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) ( $_POST['character_id'] ?? '' ) );

	if ( ! $vehicle_id || ! $module_id || ! $target_slot || ! $character_id ) {
		wp_send_json_error( 'Missing required fields.' );
		return;
	}

	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
		wp_send_json_error( 'Supabase config missing.' );
		return;
	}

	$key  = tw_supabase_anon_key();
	$base = trailingslashit( tw_supabase_url() ) . 'rest/v1/';

	// Ownership check: confirm character belongs to the current WP user,
	// and that the vehicle belongs to that character.
	$char_rows = tw_supabase_get(
		'cyber_characters',
		[
			'id'         => 'eq.' . $character_id,
			'wp_user_id' => 'eq.' . $wp_user_id,
			'select'     => 'id',
			'limit'      => 1,
		]
	);

	if ( empty( $char_rows ) ) {
		wp_send_json_error( 'Character not found or not owned by current user.' );
		return;
	}

	$vehicle_rows = tw_supabase_get(
		'cyber_vehicles',
		[
			'id'           => 'eq.' . $vehicle_id,
			'character_id' => 'eq.' . $character_id,
			'select'       => 'id',
			'limit'        => 1,
		]
	);

	if ( empty( $vehicle_rows ) ) {
		wp_send_json_error( 'Vehicle not found or not owned by current character.' );
		return;
	}

	$allowed_slots = [ 'slot_core', 'slot_lateral_l', 'slot_lateral_r', 'slot_utility' ];
	if ( ! in_array( $target_slot, $allowed_slots, true ) ) {
		wp_send_json_error( 'Invalid target slot.' );
		return;
	}

	$update_data = [ $target_slot => $module_id ];

	$response = wp_remote_request(
		$base . 'cyber_vehicles?id=eq.' . $vehicle_id,
		[
			'method'  => 'PATCH',
			'headers' => [
				'apikey'        => $key,
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
				'Prefer'        => 'return=minimal',
			],
			'body'    => wp_json_encode( $update_data ),
			'timeout' => 10,
		]
	);

	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 300 ) {
		wp_send_json_error( 'Database error.' );
		return;
	}

	wp_send_json_success( [ 'message' => 'Vehicle Calibrated.' ] );
}

/**
 * Calculate total cargo mass for a vehicle.
 *
 * BUG-FIX: was using undefined SUPABASE_URL / SUPABASE_KEY.
 */
function neoweave_get_vehicle_cargo_weight( string $vehicle_id ): int {
	if ( ! function_exists( 'tw_supabase_get' ) ) {
		return 0;
	}

	$vehicle_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', $vehicle_id );
	$items = tw_supabase_get(
		'cyber_items',
		[
			'container_id' => 'eq.' . $vehicle_id,
			'select'       => 'mass',
		]
	);

	$total = 0;
	foreach ( (array) $items as $item ) {
		$total += (int) ( $item['mass'] ?? 0 );
	}
	return $total;
}

/**
 * Get vehicle storage capacity and current usage.
 *
 * BUG-FIX: was using undefined SUPABASE_URL / SUPABASE_KEY.
 */
function neoweave_get_vehicle_storage_info( string $vehicle_id ): array {
	if ( ! function_exists( 'tw_supabase_get' ) ) {
		return [ 'current' => 0, 'max' => 5, 'is_overloaded' => false ];
	}

	$vehicle_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', $vehicle_id );

	$vehicles = tw_supabase_get(
		'cyber_vehicles',
		[
			'id'     => 'eq.' . $vehicle_id,
			'select' => '*,slot_utility(*)',
			'limit'  => 1,
		]
	);

	$vehicle      = $vehicles[0] ?? [];
	$max_capacity = 5; // Base storage.

	if ( isset( $vehicle['slot_utility']['effect_tags'] ) && is_array( $vehicle['slot_utility']['effect_tags'] ) ) {
		foreach ( $vehicle['slot_utility']['effect_tags'] as $tag ) {
			if ( strpos( (string) $tag, 'storage_' ) === 0 ) {
				$max_capacity = (int) str_replace( 'storage_', '', $tag );
			}
		}
	}

	$current_mass = neoweave_get_vehicle_cargo_weight( $vehicle_id );

	return [
		'current'      => $current_mass,
		'max'          => $max_capacity,
		'is_overloaded' => ( $current_mass > $max_capacity ),
	];
}

/**
 * Calculate travel fuel cost for a vehicle.
 */
function neoweave_calculate_travel_cost( string $vehicle_id, string $character_id ): float {
	$storage   = neoweave_get_vehicle_storage_info( $vehicle_id );
	$base_cost = 1.0;
	if ( $storage['is_overloaded'] ) {
		$base_cost += 2.0;
	}
	return max( 0.5, $base_cost );
}
