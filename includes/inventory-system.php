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
 * - define() działa bezpiecznie w każdym kontekście include/require.
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

/**
 * PRIORYTETY wp_enqueue_scripts w projekcie:
 *   neoweaver-wp-core.php  — nw-game-data  → priorytet domyślny (10)
 *   deck-core.php          — nw-deck-core  → 20
 *   char-panel.php         — tw-char-panel → 25
 *   inventory-system.php   — tw-inventory  → 40  (ten plik)
 *
 * Priorytet >= 11 gwarantuje, że is_page_template() jest wiarygodne
 * (main query WordPress ustawiony). Inventory ładuje się po char-panel,
 * bo może chcieć odczytać dane aktywnej postaci z window.nwGameData.
 *
 * BUG 5 FIX: dodano jquery i nw-game-data jako dependencies + config JS.
 * BUG 6 FIX: ownership query przeniesiona przed rozgałęzienie equip/unequip,
 *            więc unequip RÓWNIEŻ weryfikuje własność wiersza.
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

		// BUG 5 FIX — oryginał miał array() — brak jQuery i nw-game-data.
		// inventory-system.js używa $ i window.nwGameData (dane postaci),
		// więc oba muszą być załadowane jako pierwsze.
		wp_enqueue_script(
			'tw-inventory-system',
			$file_url,
			array( 'jquery', 'nw-game-data' ),
			$version,
			true
		);

		// BUG 5 FIX — config JS: ajaxUrl + nonce + activeCharacterId.
		// Bez tego inventory-system.js nie miał jak wysłać poprawnego AJAX requestu.
		//
		// UWAGA NA KLUCZE:
		//   supabaseUrl  — OK do JS (publiczny endpoint)
		//   anon key     — tylko jeśli potrzebny Realtime po stronie klienta (patrz cyber-hud.php)
		//   service key  — NIGDY do JS
		$user_id   = get_current_user_id();
		$game_data = function_exists( 'get_user_game_data_from_supabase' )
			? get_user_game_data_from_supabase( $user_id )
			: array();

		wp_add_inline_script(
			'tw-inventory-system',
			'window.nwInventoryConfig = ' . wp_json_encode(
				array(
					'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
					'nonce'             => wp_create_nonce( 'tw_adventure_nonce' ),
					'activeCharacterId' => (string) ( $game_data['active_character_id'] ?? '' ),
					'supabaseUrl'       => function_exists( 'tw_supabase_url' ) ? (string) tw_supabase_url() : '',
					'validSlots'        => NW_VALID_SLOTS,
				)
			) . ';',
			'before'
		);
	},
	40
);

// ─── AJAX handler: tw_update_inventory_slot ─────────────────────────────────
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

		// Validate slot_name against the canonical allowlist (tylko przy equipowaniu).
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

		// BUG 6 FIX — ownership check ZAWSZE przed PATCH, niezależnie od equip/unequip.
		//
		// Oryginał: przy $is_equipped=false ownership w ogóle nie był sprawdzany.
		// Gracz mógł wysłać inventory_id dowolnej postaci i wyzerować jej equipped_slot.
		//
		// Fix: ownership query (character_id = aktywna postać gracza) odpala ZAWSZE.
		// Jeśli wiersz nie należy do tej postaci, Supabase zwraca pusty array → 403.
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
		 *    więc bez tej kolejności błąd sieciowy byłby traktowany jako sukces.
		 *
		 * 2. empty() sprawdza czy Supabase zwróciło puste array
		 *    (brak wiersza = brak własności lub nieistniejące ID).
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

		// Enforce item slot_type restriction (tylko przy equipowaniu).
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

		// tw_supabase_request() zwraca WP_Error przy każdym błędzie HTTP (w tym 4xx/5xx).
		// Sprawdzanie $result['code'] po tym bloku byłoby dead code — jeśli dotarliśmy
		// tutaj, 'code' jest zawsze w [200, 299]. Patrz: supabase-helpers.php kontrakt.
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => 'Database update failed' ) );
			return;
		}

		wp_send_json_success( array( 'message' => 'Inventory updated' ) );
	}
}
