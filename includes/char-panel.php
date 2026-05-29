<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TALE WEAVER - CHARACTER PANEL LOGIC
 * Ładuje się tylko na stronie gry (templates/adventure.php).
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

		wp_enqueue_script(
			'tw-char-panel',
			$file_url,
			array( 'tw-gamedata' ),
			$version,
			true
		);

		$user_id   = get_current_user_id();
		$game_data = function_exists( 'get_user_game_data_from_supabase' )
			? get_user_game_data_from_supabase( $user_id )
			: array();

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
