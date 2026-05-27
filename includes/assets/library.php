<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_register_library_assets' ) ) {
	function tw_register_library_assets(): void {
		$cards_rel = 'assets/css/public/cards.css';
		$css_rel   = 'assets/css/public/library.css';
		$js_rel    = 'assets/js/public/library.js';
		$cards_path = NEOWEAVER_PLUGIN_DIR . $cards_rel;
		$css_path   = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path    = NEOWEAVER_PLUGIN_DIR . $js_rel;

		// Rejestruj cards.css (wspoldzielony komponent kart)
		wp_register_style(
			'neoweaver-cards',
			NEOWEAVER_PLUGIN_URL . $cards_rel,
			array(),
			file_exists( $cards_path ) ? (string) filemtime( $cards_path ) : NEOWEAVER_VERSION
		);

		// library.css zalezy od cards.css
		wp_register_style(
			'neoweaver-library',
			NEOWEAVER_PLUGIN_URL . $css_rel,
			array( 'neoweaver-cards' ),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'neoweaver-library',
			NEOWEAVER_PLUGIN_URL . $js_rel,
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}

	add_action( 'wp_enqueue_scripts', 'tw_register_library_assets', 5 );
}

if ( ! function_exists( 'tw_enqueue_library_assets' ) ) {
	function tw_enqueue_library_assets( array $config = array() ): void {
		static $done = false;

		wp_enqueue_style( 'neoweaver-achievements' );
		wp_enqueue_style( 'neoweaver-cards' );
		wp_enqueue_style( 'neoweaver-library' );
		wp_enqueue_script( 'neoweaver-library' );

		if ( $done === true ) {
			return;
		}

		$done = true;

		wp_add_inline_script(
			'neoweaver-library',
			'window.NeoWeaverLibraryConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
}
