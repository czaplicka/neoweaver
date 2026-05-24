<?php
/**
 * Vehicle module slot updater.
 *
 * Security / fixes:
 * - nonce check
 * - logged-in users only
 * - ownership check: character belongs to current WP user
 * - ownership check: vehicle belongs to that character
 * - module validation:
 *   - module exists
 *   - module belongs to the same character inventory context
 *   - module type fits the requested slot
 * - vehicle PATCH uses tw_supabase_request() (service key by default)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_update_vehicle_module', 'neoweave_update_vehicle_module' );

if ( ! function_exists( 'neoweave_get_allowed_vehicle_slots' ) ) {
	function neoweave_get_allowed_vehicle_slots(): array {
		return [ 'slot_core', 'slot_lateral_l', 'slot_lateral_r', 'slot_utility' ];
	}
}

if ( ! function_exists( 'neoweave_get_vehicle_slot_type_map' ) ) {
	function neoweave_get_vehicle_slot_type_map(): array {
		return [
			'slot_core'      => [ 'core', 'engine', 'power_core', 'reactor' ],
			'slot_lateral_l' => [ 'lateral', 'side', 'weapon', 'shield', 'tool' ],
			'slot_lateral_r' => [ 'lateral', 'side', 'weapon', 'shield', 'tool' ],
			'slot_utility'   => [ 'utility', 'cargo', 'scanner', 'support', 'storage' ],
		];
	}
}

if ( ! function_exists( 'neoweave_extract_module_type_candidates' ) ) {
	function neoweave_extract_module_type_candidates( array $module ): array {
		$candidates = [];

		$fields_to_check = [
			$module['slot'] ?? null,
			$module['module_slot'] ?? null,
			$module['module_type'] ?? null,
			$module['item_type'] ?? null,
			$module['type'] ?? null,
			$module['category'] ?? null,
			$module['kind'] ?? null,
		];

		foreach ( $fields_to_check as $value ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$candidates[] = sanitize_key( $value );
			}
		}

		if ( ! empty( $module['tags'] ) && is_array( $module['tags'] ) ) {
			foreach ( $module['tags'] as $tag ) {
				if ( is_string( $tag ) && '' !== trim( $tag ) ) {
					$candidates[] = sanitize_key( $tag );
				}
			}
		}

		if ( ! empty( $module['effect_tags'] ) && is_array( $module['effect_tags'] ) ) {
			foreach ( $module['effect_tags'] as $tag ) {
				if ( is_string( $tag ) && '' !== trim( $tag ) ) {
					$candidates[] = sanitize_key( $tag );
				}
			}
		}

		return array_values( array_unique( array_filter( $candidates ) ) );
	}
}

if ( ! function_exists( 'neoweave_module_matches_slot' ) ) {
	function neoweave_module_matches_slot( array $module, string $target_slot ): bool {
		$slot_map = neoweave_get_vehicle_slot_type_map();
		$allowed  = $slot_map[ $target_slot ] ?? [];

		if ( empty( $allowed ) ) {
			return false;
		}

		$candidates = neoweave_extract_module_type_candidates( $module );

		if ( in_array( sanitize_key( $target_slot ), $candidates, true ) ) {
			return true;
		}

		foreach ( $allowed as $allowed_type ) {
			if ( in_array( sanitize_key( $allowed_type ), $candidates, true ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'neoweave_get_owned_vehicle_module' ) ) {
	function neoweave_get_owned_vehicle_module( string $module_id, string $character_id ) {
		if ( ! function_exists( 'tw_supabase_get' ) ) {
			return new WP_Error( 'missing_helper', 'tw_supabase_get() missing' );
		}

		$module_rows = tw_supabase_get(
			'cyber_items',
			[
				'id'           => 'eq.' . $module_id,
				'character_id' => 'eq.' . $character_id,
				'select'       => 'id,character_id,slot,module_slot,module_type,item_type,type,category,kind,tags,effect_tags',
				'limit'        => 1,
			]
		);

		if ( is_wp_error( $module_rows ) ) {
			return $module_rows;
		}

		if ( empty( $module_rows ) ) {
			return new WP_Error( 'module_not_owned', 'Module not found in character inventory.' );
		}

		return $module_rows[0];
	}
}

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

		$allowed_slots = neoweave_get_allowed_vehicle_slots();
		if ( ! in_array( $target_slot, $allowed_slots, true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid target slot.' ], 400 );
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
				'select'       => 'id,character_id,slot_core,slot_lateral_l,slot_lateral_r,slot_utility',
				'limit'        => 1,
			]
		);

		if ( is_wp_error( $vehicle_rows ) || empty( $vehicle_rows ) ) {
			wp_send_json_error( [ 'message' => 'Vehicle not found or not owned by current character.' ], 403 );
			return;
		}

		$vehicle = $vehicle_rows[0];

		$module = neoweave_get_owned_vehicle_module( $module_id, $character_id );
		if ( is_wp_error( $module ) ) {
			wp_send_json_error( [ 'message' => $module->get_error_message() ], 403 );
			return;
		}

		if ( ! neoweave_module_matches_slot( $module, $target_slot ) ) {
			wp_send_json_error( [ 'message' => 'Module does not fit the target slot.' ], 400 );
			return;
		}

		$current_slot_value = $vehicle[ $target_slot ] ?? null;
		if ( (string) $current_slot_value === $module_id ) {
			wp_send_json_success( [ 'message' => 'Vehicle already calibrated.' ] );
			return;
		}

		$other_slots = array_diff( $allowed_slots, [ $target_slot ] );
		foreach ( $other_slots as $slot_name ) {
			if ( (string) ( $vehicle[ $slot_name ] ?? '' ) === $module_id ) {
				wp_send_json_error( [ 'message' => 'Module is already installed in another slot.' ], 409 );
				return;
			}
		}

		$update_data = [ $target_slot => $module_id ];

		$result = tw_supabase_request(
			'PATCH',
			'cyber_vehicles',
			[
				'id'           => 'eq.' . $vehicle_id,
				'character_id' => 'eq.' . $character_id,
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
