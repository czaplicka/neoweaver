<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NEOWEAVER - DECK CORE
 * ᐚduje silnik talii kart tylko na stronie gry (templates/adventure.php).
 *
 * PRIORYTETY wp_enqueue_scripts:
 * - char-panel.php   → priorytet 25
 * - quick-actions.php → priorytet 30 (sprawdzić w pliku)
 * - deck-core.php    → priorytet 20 (ten plik)
 *
 * Priorytet >= 11 gwarantuje, że main query WordPress został uruchomiony
 * i is_page_template() zwraca poprawny wynik. Nie używamy priorytetu
 * domyślnego (10), bo przy nim kolejność względem set_queried_object()
 * nie jest gwarantowana w każdym scenariuszu (patrz BUG 4).
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		// BUG 4 FIX — is_page_template() jest bezpieczne od priorytetu 11+,
		// kiedy main query jest już ustawione. Priorytet 20 zostawia margines
		// i pasuje do kolejności oczekiwanej przez char-panel.php (25) i
		// nw-game-data (rejestrowany wcześniej w neoweaver-wp-core.php).
		if ( ! is_page_template( 'templates/adventure.php' ) || ! get_current_user_id() ) {
			return;
		}

		$file_rel  = 'assets/js/public/deck-core.js';
		$file_path = trailingslashit( NEOWEAVER_PLUGIN_DIR ) . $file_rel;
		$file_url  = trailingslashit( NEOWEAVER_PLUGIN_URL ) . $file_rel;
		$version   = file_exists( $file_path ) ? (string) filemtime( $file_path ) : NEOWEAVER_VERSION;

		wp_enqueue_script(
			'nw-deck-core',
			$file_url,
			array( 'jquery', 'nw-game-data' ),
			$version,
			true
		);

		// BUG 3 FIX — deck-core.js potrzebuje ajaxUrl, nonce i characterId
		// do wywołań AJAX (losowanie kart, efekty, scenariusze).
		// Bez tego każde wp_ajax_* wywołanie zwracało -1 (WordPress 0 response).
		//
		// NIE przekazujemy tu supabaseKey (service key nigdy do JS;
		// anon key tylko gdy potrzebny Realtime — patrz cyber-hud.php).
		$user_id   = get_current_user_id();
		$game_data = function_exists( 'get_user_game_data_from_supabase' )
			? get_user_game_data_from_supabase( $user_id )
			: array();

		$deck_config = array(
			'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
			'nonce'             => wp_create_nonce( 'tw_adventure_nonce' ),
			'activeCharacterId' => (string) ( $game_data['active_character_id'] ?? '' ),
			'supabaseUrl'       => function_exists( 'tw_supabase_url' ) ? (string) tw_supabase_url() : '',
		);

		wp_add_inline_script(
			'nw-deck-core',
			'window.nwDeckConfig = ' . wp_json_encode( $deck_config ) . ';',
			'before'
		);
	},
	20
);
