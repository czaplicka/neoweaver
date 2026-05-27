<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NEOWEAVER - QUICK ACTIONS (SERVER-SIDE COOLDOWN)
 * Ładuje się tylko na stronie gry (templates/adventure.php).
 *
 * Zależy od nw-game-data (supabase-global.php, priority 5), który dostarcza
 * window.twSupabase i window.twGameState zanim ten skrypt wykona jakikolwiek kod.
 */

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! is_page_template( 'templates/adventure.php' ) || ! get_current_user_id() ) {
			return;
		}

		$file_rel  = 'assets/js/public/quick-actions.js';
		$file_path = trailingslashit( NEOWEAVER_PLUGIN_DIR ) . $file_rel;
		$file_url  = trailingslashit( NEOWEAVER_PLUGIN_URL ) . $file_rel;
		$version   = file_exists( $file_path ) ? (string) filemtime( $file_path ) : NEOWEAVER_VERSION;

		wp_enqueue_script(
			'tw-quick-actions',
			$file_url,
			array( 'nw-game-data' ),
			$version,
			true
		);
	},
	45
);
