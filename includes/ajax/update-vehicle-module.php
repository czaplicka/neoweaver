<?php
/**
 * Vehicle module slot updater.
 *
 * Security / fixes:
 * - nonce check
 * - logged-in users only
 * - ownership check: character belongs to current WP user
 * - ownership check: vehicle belongs to that character
 * - vehicle PATCH uses tw_supabase_request() (service key by default)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_update_vehicle_module', 'neoweave_update_vehicle_module' );

if ( ! function_exists( 'neoweave_update_vehicle_module' ) ) {
	function neoweave_update_vehicle_module(): void {
		if ( ! check_ajax_referer( 'tw_ajax_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'Unauthorized.' ], 401 );
			return;
		}

		$wp_user_id = get_current_user_id();

		$vehicle_id   = strtolower( preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) ( $_POST['vehicle_id'] ?? '' ) ) );
		$module_id    = strtolower( preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) ( $_POST['module_id'] ?? '' ) ) );
		$target_slot  = sanitize_key( (string) ( $_POST['target_slot'] ?? '' ) );
		$character_id = strtolower( preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) ( $_POST['character_id'] ?? '' ) ) );

		if ( ! $vehicle_id || ! $module_id || ! $target_slot || ! $character_id ) {
			wp_send_json_error( [ 'message' => 'Missing required fields.' ], 400 );
			return;
		}

		if ( ! function_exists( 'tw_supabase_get' ) || ! function_exists( 'tw_supabase_request' ) ) {
			wp_send_json_error( [ 'message' => 'Supabase helper missing.' ], 500 );
			return;
		}

		$char_rows = tw_supabase_get(
			'cyber_characters',
			[
				'id'         => 'eq.' . $character_id,
				'wp_user_id' => 'eq.' . $wp_user_id,
				'select'     => 'id',
				'limit'      => 1,
			]
		);

		if ( is_wp_error( $char_rows ) || empty( $char_rows ) ) {
			wp_send_json_error( [ 'message' => 'Character not found or not owned by current user.' ], 403 );
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

		if ( is_wp_error( $vehicle_rows ) || empty( $vehicle_rows ) ) {
			wp_send_json_error( [ 'message' => 'Vehicle not found or not owned by current character.' ], 403 );
			return;
		}

		$allowed_slots = [ 'slot_core', 'slot_lateral_l', 'slot_lateral_r', 'slot_utility' ];
		if ( ! in_array( $target_slot, $allowed_slots, true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid target slot.' ], 400 );
			return;
		}

		$update_data = [ $target_slot => $module_id ];

		$result = tw_supabase_request(
			'PATCH',
			'cyber_vehicles',
			[
				'id' => 'eq.' . $vehicle_id,
			],
			$update_data,
			[
				'headers' => [
					'Prefer' => 'return=minimal',
				],
				'timeout'   => 10,
				'sslverify' => true,
			]
		);

		if ( is_wp_error( $result ) ) {
			$status  = (int) ( $result->get_error_data()['status'] ?? 500 );
			$body    = $result->get_error_data()['body'] ?? '';
			$message = $result->get_error_message();

			error_log(
				'TW update_vehicle_module error: '
				. $message
				. ' | status=' . $status
				. ' | body=' . ( is_scalar( $body ) ? (string) $body : wp_json_encode( $body ) )
				. ' | vehicle_id=' . $vehicle_id
				. ' | module_id=' . $module_id
				. ' | target_slot=' . $target_slot
				. ' | character_id=' . $character_id
				. ' | wp_user_id=' . $wp_user_id
			);

			wp_send_json_error(
				[
					'message' => 'Database error.',
					'status'  => $status,
					'error'   => $message,
				],
				$status > 0 ? $status : 500
			);
			return;
		}

		wp_send_json_success( [ 'message' => 'Vehicle calibrated.' ] );
	}
}

/**
 * Calculate total cargo mass for a vehicle.
 */
if ( ! function_exists( 'neoweave_get_vehicle_cargo_weight' ) ) {
	function neoweave_get_vehicle_cargo_weight( string $vehicle_id ): int {
		if ( ! function_exists( 'tw_supabase_get' ) ) {
			return 0;
		}

		$vehicle_id = strtolower( preg_replace( '/[^a-zA-Z0-9\\-]/', '', $vehicle_id ) );

		if ( '' === $vehicle_id ) {
			return 0;
		}

		$items = tw_supabase_get(
			'cyber_items',
			[
				'container_id' => 'eq.' . $vehicle_id,
				'select'       => 'mass',
			]
		);

		if ( is_wp_error( $items ) || empty( $items ) ) {
			return 0;
		}

		$total = 0;
		foreach ( (array) $items as $item ) {
			$total += (int) ( $item['mass'] ?? 0 );
		}

		return $total;
	}
}

/**
 * Get vehicle storage capacity and current usage.
 */
if ( ! function_exists( 'neoweave_get_vehicle_storage_info' ) ) {
	function neoweave_get_vehicle_storage_info( string $vehicle_id ): array {
		if ( ! function_exists( 'tw_supabase_get' ) ) {
			return [ 'current' => 0, 'max' => 5, 'is_overloaded' => false ];
		}

		$vehicle_id = strtolower( preg_replace( '/[^a-zA-Z0-9\\-]/', '', $vehicle_id ) );

		if ( '' === $vehicle_id ) {
			return [ 'current' => 0, 'max' => 5, 'is_overloaded' => false ];
		}

		$vehicles = tw_supabase_get(
			'cyber_vehicles',
			[
				'id'     => 'eq.' . $vehicle_id,
				'select' => 'id,slot_utility(effect_tags)',
				'limit'  => 1,
			]
		);

		if ( is_wp_error( $vehicles ) || empty( $vehicles ) ) {
			return [ 'current' => 0, 'max' => 5, 'is_overloaded' => false ];
		}

		$vehicle      = $vehicles[0] ?? [];
		$max_capacity = 5;

		if ( isset( $vehicle['slot_utility']['effect_tags'] ) && is_array( $vehicle['slot_utility']['effect_tags'] ) ) {
			foreach ( $vehicle['slot_utility']['effect_tags'] as $tag ) {
				if ( strpos( (string) $tag, 'storage_' ) === 0 ) {
					$parsed = (int) str_replace( 'storage_', '', (string) $tag );
					if ( $parsed > 0 ) {
						$max_capacity = $parsed;
					}
				}
			}
		}

		$current_mass = neoweave_get_vehicle_cargo_weight( $vehicle_id );

		return [
			'current'       => $current_mass,
			'max'           => $max_capacity,
			'is_overloaded' => ( $current_mass > $max_capacity ),
		];
	}
}

/**
 * Calculate travel fuel cost for a vehicle.
 */
if ( ! function_exists( 'neoweave_calculate_travel_cost' ) ) {
	function neoweave_calculate_travel_cost( string $vehicle_id, string $character_id ): float {
		$vehicle_id   = strtolower( preg_replace( '/[^a-zA-Z0-9\\-]/', '', $vehicle_id ) );
		$character_id = strtolower( preg_replace( '/[^a-zA-Z0-9\\-]/', '', $character_id ) );

		if ( '' === $vehicle_id || '' === $character_id ) {
			return 1.0;
		}

		$storage   = neoweave_get_vehicle_storage_info( $vehicle_id );
		$base_cost = 1.0;

		if ( ! empty( $storage['is_overloaded'] ) ) {
			$base_cost += 2.0;
		}

		return max( 0.5, $base_cost );
	}
}
