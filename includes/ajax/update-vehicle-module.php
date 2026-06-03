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
 *   - module exists in cyber_character_vehicle_modules
 *   - module quantity > 0
 *   - module type fits the requested slot
 *   - module is not already installed in another slot of the same vehicle
 * - vehicle PATCH uses tw_supabase_request() (service key by default)
 *
 * FIX (Bug 1): neoweave_get_vehicle_storage_info used 'slot_utility(effect_tags)'
 *   as if slot_utility were a PostgREST embedded resource. Because the column stores
 *   a plain UUID string (no FK constraint), PostgREST returns it as a raw string and
 *   $vehicle['slot_utility']['effect_tags'] is always null — capacity silently defaulted
 *   to 5 for every vehicle regardless of installed module.
 *   Fix: fetch slot_utility UUID, then do a second targeted query against
 *   cyber_vehicle_module_types to get effect_tags.
 *
 * FIX (Bug 2): neoweave_calculate_travel_cost accepted $vehicle_id from the caller
 *   without verifying the vehicle belongs to $character_id. Added ownership check.
 *   Also added wp_cache to avoid redundant HTTP calls within the same request.
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
			'slot_core'      => 'core',
			'slot_lateral_l' => 'lateral',
			'slot_lateral_r' => 'lateral',
			'slot_utility'   => 'utility',
		];
	}
}

if ( ! function_exists( 'neoweave_get_owned_vehicle_module_type' ) ) {
	function neoweave_get_owned_vehicle_module_type( string $module_id, string $character_id ) {
		if ( ! function_exists( 'tw_supabase_get' ) ) {
			return new WP_Error( 'missing_helper', 'tw_supabase_get() missing' );
		}

		$rows = tw_supabase_get(
			'cyber_character_vehicle_modules',
			[
				'character_id'   => 'eq.' . $character_id,
				'module_type_id' => 'eq.' . $module_id,
				'select'         => 'quantity,module:cyber_vehicle_module_types!cyber_character_vehicle_modules_module_type_id_fkey(id,name,slot_type,weight,durability_bonus,min_vehicles_skill,effect_tags)',
				'limit'          => 1,
			]
		);

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		if ( empty( $rows ) ) {
			return new WP_Error( 'module_not_owned', 'Module not owned by this character.' );
		}

		$row = $rows[0];
		$qty = (int) ( $row['quantity'] ?? 0 );

		if ( $qty <= 0 ) {
			return new WP_Error( 'module_not_available', 'Module quantity is 0.' );
		}

		if ( empty( $row['module'] ) || ! is_array( $row['module'] ) ) {
			return new WP_Error( 'module_missing', 'Module type data missing.' );
		}

		return $row['module'];
	}
}

if ( ! function_exists( 'neoweave_update_vehicle_module' ) ) {
	function neoweave_update_vehicle_module(): void {
		if ( ! check_ajax_referer( 'tw_adventure_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'Unauthorized.' ], 401 );
			return;
		}

		$wp_user_id = get_current_user_id();

		$vehicle_id   = strtolower( preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) ( $_POST['vehicle_id'] ?? '' ) ) );
		$module_id    = strtolower( preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) ( $_POST['module_id'] ?? '' ) ) );
		$target_slot  = sanitize_key( (string) ( $_POST['target_slot'] ?? '' ) );
		$character_id = strtolower( preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) ( $_POST['character_id'] ?? '' ) ) );

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
				'id'       => 'eq.' . $vehicle_id,
				'owner_id' => 'eq.' . $character_id,
				'select'   => 'id,owner_id,slot_core,slot_lateral_l,slot_lateral_r,slot_utility',
				'limit'    => 1,
			]
		);

		if ( is_wp_error( $vehicle_rows ) || empty( $vehicle_rows ) ) {
			wp_send_json_error( [ 'message' => 'Vehicle not found or not owned by current character.' ], 403 );
			return;
		}

		$vehicle = $vehicle_rows[0];

		$slot_type_map      = neoweave_get_vehicle_slot_type_map();
		$expected_slot_type = $slot_type_map[ $target_slot ] ?? '';

		if ( '' === $expected_slot_type ) {
			wp_send_json_error( [ 'message' => 'Invalid target slot.' ], 400 );
			return;
		}

		$module = neoweave_get_owned_vehicle_module_type( $module_id, $character_id );
		if ( is_wp_error( $module ) ) {
			wp_send_json_error( [ 'message' => $module->get_error_message() ], 403 );
			return;
		}

		$module_slot_type = sanitize_key( (string) ( $module['slot_type'] ?? '' ) );
		if ( $module_slot_type !== $expected_slot_type ) {
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
				'id'       => 'eq.' . $vehicle_id,
				'owner_id' => 'eq.' . $character_id,
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

		$vehicle_id = strtolower( preg_replace( '/[^a-zA-Z0-9\-]/', '', $vehicle_id ) );

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
 *
 * FIX (Bug 1): The original query used 'slot_utility(effect_tags)' as a PostgREST
 * embedded resource join. Because cyber_vehicles.slot_utility holds a plain UUID
 * string without a database-level FK constraint pointing to cyber_vehicle_module_types,
 * PostgREST returns it as a raw scalar — $vehicle['slot_utility']['effect_tags'] is
 * always null and max capacity silently defaulted to 5 for every vehicle.
 *
 * Fix: fetch slot_utility as a plain UUID, then do an explicit second query to
 * cyber_vehicle_module_types. Results are stored in wp_cache so multiple calls within
 * the same request (e.g. storage info + travel cost) do not repeat the HTTP round-trips.
 */
if ( ! function_exists( 'neoweave_get_vehicle_storage_info' ) ) {
	function neoweave_get_vehicle_storage_info( string $vehicle_id ): array {
		$default = [ 'current' => 0, 'max' => 5, 'is_overloaded' => false ];

		if ( ! function_exists( 'tw_supabase_get' ) ) {
			return $default;
		}

		$vehicle_id = strtolower( preg_replace( '/[^a-zA-Z0-9\-]/', '', $vehicle_id ) );

		if ( '' === $vehicle_id ) {
			return $default;
		}

		// ── Cache check ───────────────────────────────────────────────────────
		$cache_key = 'nw_vsi_' . $vehicle_id;
		$cached    = wp_cache_get( $cache_key, 'neoweaver' );
		if ( false !== $cached ) {
			return $cached;
		}

		// ── Step 1: fetch vehicle row, slot_utility UUID only ─────────────────
		$vehicles = tw_supabase_get(
			'cyber_vehicles',
			[
				'id'     => 'eq.' . $vehicle_id,
				'select' => 'id,slot_utility',
				'limit'  => 1,
			]
		);

		if ( is_wp_error( $vehicles ) || empty( $vehicles ) ) {
			return $default;
		}

		$vehicle         = $vehicles[0] ?? [];
		$max_capacity    = 5;
		$slot_utility_id = isset( $vehicle['slot_utility'] ) && is_string( $vehicle['slot_utility'] )
			? strtolower( preg_replace( '/[^a-zA-Z0-9\-]/', '', $vehicle['slot_utility'] ) )
			: '';

		// ── Step 2: if a utility module is installed, fetch its effect_tags ───
		if ( '' !== $slot_utility_id ) {
			$module_rows = tw_supabase_get(
				'cyber_vehicle_module_types',
				[
					'id'     => 'eq.' . $slot_utility_id,
					'select' => 'effect_tags',
					'limit'  => 1,
				]
			);

			if ( ! is_wp_error( $module_rows ) && ! empty( $module_rows[0]['effect_tags'] ) ) {
				$effect_tags = $module_rows[0]['effect_tags'];
				if ( is_string( $effect_tags ) ) {
					// Supabase may return a JSON-encoded array as a string.
					$effect_tags = json_decode( $effect_tags, true ) ?: [];
				}
				if ( is_array( $effect_tags ) ) {
					foreach ( $effect_tags as $tag ) {
						if ( strpos( (string) $tag, 'storage_' ) === 0 ) {
							$parsed = (int) str_replace( 'storage_', '', (string) $tag );
							if ( $parsed > 0 ) {
								$max_capacity = $parsed;
							}
						}
					}
				}
			}
		}

		$current_mass = neoweave_get_vehicle_cargo_weight( $vehicle_id );

		$result = [
			'current'       => $current_mass,
			'max'           => $max_capacity,
			'is_overloaded' => ( $current_mass > $max_capacity ),
		];

		wp_cache_set( $cache_key, $result, 'neoweaver', 60 );

		return $result;
	}
}

/**
 * Calculate travel fuel cost for a vehicle.
 *
 * FIX (Bug 2): The original function accepted $vehicle_id from the caller without
 * verifying ownership. Any code path (or future AJAX handler) could pass an arbitrary
 * vehicle_id and get a cost calculation based on that vehicle's storage state.
 * Fix: verify vehicle belongs to $character_id before proceeding.
 * neoweave_get_vehicle_storage_info results are wp_cached, so the extra ownership
 * query is the only added overhead.
 */
if ( ! function_exists( 'neoweave_calculate_travel_cost' ) ) {
	function neoweave_calculate_travel_cost( string $vehicle_id, string $character_id ): float {
		$vehicle_id   = strtolower( preg_replace( '/[^a-zA-Z0-9\-]/', '', $vehicle_id ) );
		$character_id = strtolower( preg_replace( '/[^a-zA-Z0-9\-]/', '', $character_id ) );

		if ( '' === $vehicle_id || '' === $character_id ) {
			return 1.0;
		}

		// ── Ownership check ───────────────────────────────────────────────────
		if ( ! function_exists( 'tw_supabase_get' ) ) {
			return 1.0;
		}

		$ownership_cache_key = 'nw_vown_' . $vehicle_id . '_' . $character_id;
		$owns                = wp_cache_get( $ownership_cache_key, 'neoweaver' );

		if ( false === $owns ) {
			$owns_rows = tw_supabase_get(
				'cyber_vehicles',
				[
					'id'       => 'eq.' . $vehicle_id,
					'owner_id' => 'eq.' . $character_id,
					'select'   => 'id',
					'limit'    => 1,
				]
			);
			$owns = ( ! is_wp_error( $owns_rows ) && ! empty( $owns_rows ) ) ? 'yes' : 'no';
			wp_cache_set( $ownership_cache_key, $owns, 'neoweaver', 60 );
		}

		if ( 'yes' !== $owns ) {
			// Vehicle does not belong to this character — return neutral cost.
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
