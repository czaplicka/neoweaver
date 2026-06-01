<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NEOWEAVER - QUICK ACTIONS (SERVER-SIDE COOLDOWN)
 * ᐚduje się tylko na stronie gry (templates/adventure.php).
 *
 * Zależy od nw-game-data (supabase-global.php, priority 5), który dostarcza
 * window.twSupabase i window.twGameState zanim ten skrypt wykona jakikolwiek kod.
 *
 * BUG 8 FIX — is_page_template() opiera się na get_queried_object(), który może
 * być wyzerowany przez inny plugin między parse_query a tym hookiem.
 * Rozwiązanie: podwójne sprawdzenie:
 *   1. is_page_template()       — standardowa droga (szybka, cached)
 *   2. wp_is_post_template()    — fallback: sprawdza meta _wp_page_template
 *      bezpośrednio na $post, bez polegania na queried object.
 *      Dostępne od WP 5.5, nie jest podatne na reset query object.
 * Jeśli obie metody zwracają false mimo że jesteśmy na adventure page,
 * error_log zostawia ślad w logach zamiast cichego braku skryptu.
 */

/**
 * Pomocnicza funkcja: czy bieżąca strona używa szablonu adventure.php?
 * Odporna na reset queried_object przez zewnętrzne pluginy.
 *
 * @return bool
 */
function nw_is_adventure_template(): bool {
	$template = 'templates/adventure.php';

	// Standardowe sprawdzenie (priorytet: szybkie + cache WP).
	if ( is_page_template( $template ) ) {
		return true;
	}

	// Fallback: wp_is_post_template() czyta meta _wp_page_template bezpośrednio
	// z globalnego $post — nie wymaga poprawnego queried_object.
	if ( function_exists( 'wp_is_post_template' ) && wp_is_post_template( $template ) ) {
		return true;
	}

	return false;
}

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! nw_is_adventure_template() || ! get_current_user_id() ) {
			return;
		}

		$file_rel  = 'assets/js/public/quick-actions.js';
		$file_path = trailingslashit( NEOWEAVER_PLUGIN_DIR ) . $file_rel;
		$file_url  = trailingslashit( NEOWEAVER_PLUGIN_URL ) . $file_rel;
		$version   = file_exists( $file_path ) ? (string) filemtime( $file_path ) : NEOWEAVER_VERSION;

		// Sanity check: jeśli plik JS nie istnieje, zaloguj żeby nie ścigać duchow.
		if ( ! file_exists( $file_path ) ) {
			error_log( '[NeoWeaver] quick-actions.php: JS file not found at ' . $file_path );
			return;
		}

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
