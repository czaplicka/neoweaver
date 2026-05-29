<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NEOWEAVER - INVENTORY SYSTEM
 * Drag & drop, paperdoll, ekwipunek postaci.
 * ᐚduje się tylko na stronie gry (templates/adventure.php).
 */

/**
 * Canonical list of valid equipment slots.
 *
 * define() zamiast const:
 * - PHP const na poziomie pliku (poza klasą) jest legalne, ale staje się
 *   fatal error gdy plik zostanie dołączony wewnątrz funkcji lub warunku.
 * - define() działa bezpiecznie w każdym kontekstu include/require.
 * - Wartości zamrożone — stałe globalne dla całego request.
 */
if ( ! defined( 'NW_VALID_SLOTS' ) ) {
	define(
		'NW_VALID_SLOTS',
		array(
			'head',
			'torso',
			'hand_l',
			'hand_r',
			'belt_1',
			'belt_2',
			'belt_3',
			'legs',
			'feet',
			'accessory_1',
			'accessory_2',
		)
	);
}

/**
 * Map each slot to the item slot_type tag(s) that may be equipped there.
 * Values must match the slot_type tags used in cyber_items.
 */
if ( ! defined( 'NW_SLOT_ALLOWED_TYPES' ) ) {
	define(
		'NW_SLOT_ALLOWED_TYPES',
		array(
			'head'        => array( 'head' ),
			'torso'       => array( 'torso', 'armor' ),
			'hand_l'      => array( 'hand', 'shield', 'weapon' ),
			'hand_r'      => array( 'hand', 'weapon' ),
			'belt_1'      => array( 'belt', 'consumable', 'tool' ),
			'belt_2'      => array( 'belt', 'consumable', 'tool' ),
			'belt_3'      => array( 'belt', 'consumable', 'tool' ),
			'legs'        => array( 'legs' ),
			'feet'        => array( 'feet', 'boots' ),
			'accessory_1' => array( 'accessory', 'implant' ),
			'accessory_2' => array( 'accessory', 'implant' ),
		)
	);
}

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! is_page_template( 'templates/adventure.php' ) || ! get_current_user_id() ) {
			return;
		}

		$file_rel  = 'assets/js/public/inventory-system.js';
		$file_path = trailingslashit( NEOWEAVER_PLUGIN_DIR ) . $file_rel;
		$file_url  = trailingslashit( NEOWEAVER_PLUGIN_URL ) . $file_rel;
		$version   = file_exists( $file_path ) ? (string) filemtime( $file_path ) : NEOWEAVER_VERSION;

		wp_enqueue_script(
			'tw-inventory-system',
			$file_url,
			array(),
			$version,
			true
		);
	},
	40
);

// ─── AJAX handler: tw_update_inventory_slot ─────────────────────────────────────────────────
add_action( 'wp_ajax_tw_update_inventory_slot', 'tw_handle_update_inventory_slot' );

if ( ! function_exists( 'tw_handle_update_inventory_slot' ) ) {
	function tw_handle_update_inventory_slot(): void {
		if ( ! check_ajax_referer( 'tw_adventure_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed' ) );
			return;
		}

		$wp_user_id = get_current_user_id();
		if ( ! $wp_user_id ) {
			wp_send_json_error( array( 'message' => 'Not logged in' ) );
			return;
		}

		$inventory_id = isset( $_POST['inventory_id'] )
			? preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) wp_unslash( $_POST['inventory_id'] ) )
			: '';

		if ( empty( $inventory_id ) ) {
			wp_send_json_error( array( 'message' => 'Missing inventory_id' ) );
			return;
		}

		$is_equipped = ! empty( $_POST['is_equipped'] ) && '1' === $_POST['is_equipped'];
		$slot_name   = isset( $_POST['slot_name'] )
			? sanitize_key( wp_unslash( (string) $_POST['slot_name'] ) )
			: null;

		if ( '' === $slot_name ) {
			$slot_name = null;
		}

		// Validate slot_name against the canonical allowlist.
		if ( $is_equipped && ( null === $slot_name || ! in_array( $slot_name, NW_VALID_SLOTS, true ) ) ) {
			wp_send_json_error( array( 'message' => 'Invalid equipment slot' ) );
			return;
		}

		if ( ! function_exists( 'get_user_game_data_from_supabase' ) ) {
			wp_send_json_error( array( 'message' => 'Game data helper missing' ) );
			return;
		}

		$game_data    = get_user_game_data_from_supabase( $wp_user_id );
		$character_id = $game_data['active_character_id'] ?? '';

		if ( empty( $character_id ) ) {
			wp_send_json_error( array( 'message' => 'No active character' ) );
			return;
		}

		// Verify ownership and fetch item slot_type in one query.
		$ownership_rows = tw_supabase_get(
			'cyber_character_inventory',
			array(
				'id'           => 'eq.' . $inventory_id,
				'character_id' => 'eq.' . $character_id,
				'select'       => 'id,cyber_items(slot_type)',
				'limit'        => 1,
			)
		);

		/**
		 * KOLEJNOŚĆ sprawdzeń ma znaczenie:
		 *
		 * 1. is_wp_error() MUSI być PRZED empty().
		 *
		 *    empty() na obiekcie WP_Error zwraca FALSE (obiekt nie jest pusty),
		 *    więc stary kod przechodził dalej z błędnym $ownership_rows[0]
		 *    przy każdym błędzie sieciowym — PHP Warning + PATCH bez sprawdzenia właśności.
		 *
		 * 2. empty() sprawdza czy Supabase zwróciło puste array (brak wiersza = brak właśności).
		 */
		if ( is_wp_error( $ownership_rows ) ) {
			error_log( '[NeoWeaver] tw_handle_update_inventory_slot: Supabase ownership check failed – ' . $ownership_rows->get_error_message() );
			wp_send_json_error( array( 'message' => 'Database error during ownership check' ) );
			return;
		}

		if ( empty( $ownership_rows ) ) {
			wp_send_json_error( array( 'message' => 'Inventory item not found or not owned by current character' ) );
			return;
		}

		// Enforce item slot_type restriction when equipping.
		if ( $is_equipped && null !== $slot_name ) {
			$allowed_types  = NW_SLOT_ALLOWED_TYPES[ $slot_name ] ?? array();
			$item_slot_type = $ownership_rows[0]['cyber_items']['slot_type'] ?? null;

			if ( null === $item_slot_type || ! in_array( $item_slot_type, $allowed_types, true ) ) {
				wp_send_json_error( array( 'message' => 'Item type not allowed in this slot' ) );
				return;
			}
		}

		$patch_body = array(
			'is_equipped'   => $is_equipped,
			'equipped_slot' => $slot_name,
		);

		$result = tw_supabase_request(
			'PATCH',
			'cyber_character_inventory',
			array( 'id' => 'eq.' . $inventory_id ),
			$patch_body
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => 'Database update failed' ) );
			return;
		}

		if ( is_array( $result ) && isset( $result['code'] ) && ( (int) $result['code'] < 200 || (int) $result['code'] >= 300 ) ) {
			error_log( 'NW tw_handle_update_inventory_slot: Supabase PATCH failed, code=' . $result['code'] );
			wp_send_json_error( array( 'message' => 'Database update failed', 'code' => $result['code'] ) );
			return;
		}

		wp_send_json_success( array( 'message' => 'Inventory updated' ) );
	}
}
