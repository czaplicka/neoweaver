<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NEOWEAVER - CHARACTER PANEL LOGIC
 * ᐚduje się tylko na stronie gry (templates/adventure.php).
 */

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! is_page_template( 'templates/adventure.php' ) || ! get_current_user_id() ) {
			return;
		}

		$file_rel  = 'assets/js/public/char-panel.js';
		$file_path = trailingslashit( NEOWEAVER_PLUGIN_DIR ) . $file_rel;
		$file_url  = trailingslashit( NEOWEAVER_PLUGIN_URL ) . $file_rel;
		$version   = file_exists( $file_path ) ? (string) filemtime( $file_path ) : NEOWEAVER_VERSION;

		// BUG 1 FIX — prawidłowy handle to 'nw-game-data' (zarejestrowany w neoweaver-wp-core.php
		// i quick-actions.php). Poprzedni handle 'tw-gamedata' nie istniał, więc WordPress
		// ignorował zależność i ładował char-panel.js zanim window.twCharacterPanelData
		// było gotowe — skutkowało to JS TypeError przy każdym załadowaniu strony gry.
		wp_enqueue_script(
			'tw-char-panel',
			$file_url,
			array( 'nw-game-data' ),
			$version,
			true
		);

		$user_id   = get_current_user_id();
		$game_data = function_exists( 'get_user_game_data_from_supabase' )
			? get_user_game_data_from_supabase( $user_id )
			: array();

		// BUG 2 UWAGA (dokumentacja wzorca, nie błąd w tym pliku):
		// Ten obiekt celowo NIE zawiera supabaseKey.
		//
		// ZASADA: do twCharacterPanelData / twQuickActionsData i podobnych
		// konfiguracji JS przekazujemy wyłącznie supabaseUrl.
		// Klucze Supabase mogą trafić do JS TYLKO jeśli jest to:
		//   • ANON KEY (tw_supabase_anon_key()) — dopuszczalne dla
		//     subskrypcji Realtime po stronie klienta (patrz: cyber-hud.php).
		//   • SERVICE KEY (≈ TW_SUPABASE_SERVICE_KEY) — NIGDY nie trafia do JS.
		//     Występuje tylko server-side w tw_supabase_request() / tw_supabase_get_admin().
		//
		// Przed dodaniem klucza do wp_add_inline_script upewnij się, że to anon key,
		// nie service key. Skopiowanie wzorca z cyber-hud.php z użyciem złego klucza
		// ujawniłoby pełny dostęp do bazy danych.
		$char_panel_data = array(
			'supabaseUrl'       => function_exists( 'tw_supabase_url' ) ? (string) tw_supabase_url() : '',
			'activeCharacterId' => (string) ( $game_data['active_character_id'] ?? '' ),
			'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
			'nonce'             => wp_create_nonce( 'tw_adventure_nonce' ),
		);

		wp_add_inline_script(
			'tw-char-panel',
			'window.twCharacterPanelData = ' . wp_json_encode( $char_panel_data ) . ';',
			'before'
		);
	},
	25
);
