<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TALE WEAVER - INVENTORY SYSTEM
 * Drag & drop, paperdoll, ekwipunek postaci.
 * Ładuje się tylko na stronie gry (templates/adventure.php).
 */

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

// ─── AJAX handler: tw_update_inventory_slot ──────────────────────────────────
add_action( 'wp_ajax_tw_update_inventory_slot', 'tw_handle_update_inventory_slot' );

if ( ! function_exists( 'tw_handle_update_inventory_slot' ) ) {
	function tw_handle_update_inventory_slot(): void {
		if ( ! check_ajax_referer( 'tw_ajax_nonce', 'nonce', false ) ) {
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
			? sanitize_text_field( wp_unslash( (string) $_POST['slot_name'] ) )
			: null;

		if ( '' === $slot_name ) {
			$slot_name = null;
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

		$ownership_rows = tw_supabase_get(
			'cyber_character_inventory',
			array(
				'id'           => 'eq.' . $inventory_id,
				'character_id' => 'eq.' . $character_id,
				'select'       => 'id',
				'limit'        => 1,
			)
		);

		if ( empty( $ownership_rows ) ) {
			wp_send_json_error( array( 'message' => 'Inventory item not found or not owned by current character' ) );
			return;
		}

		$patch_body = array(
			'is_equipped'  => $is_equipped,
			'equipped_slot'=> $slot_name,
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
			error_log( 'TW tw_handle_update_inventory_slot: Supabase PATCH failed, code=' . $result['code'] );
			wp_send_json_error( array( 'message' => 'Database update failed', 'code' => $result['code'] ) );
			return;
		}

		wp_send_json_success( array( 'message' => 'Inventory updated' ) );
	}
}
