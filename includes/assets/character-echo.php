<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_register_character_echo_assets' ) ) {
	function tw_register_character_echo_assets(): void {
		$css_handle = 'neoweaver-character-echo';
		$js_handle  = 'neoweaver-character-echo';

		$css_rel = 'assets/css/public/character-echo.css';
		$js_rel  = 'assets/js/public/character-echo.js';

		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;

		$css_url = NEOWEAVER_PLUGIN_URL . $css_rel;
		$js_url  = NEOWEAVER_PLUGIN_URL . $js_rel;

		wp_register_style(
			$css_handle,
			$css_url,
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			$js_handle,
			$js_url,
			[],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}
}

if ( ! function_exists( 'tw_enqueue_character_echo_assets' ) ) {
	function tw_enqueue_character_echo_assets(): void {
		wp_enqueue_style( 'neoweaver-character-echo' );
		wp_enqueue_script( 'neoweaver-character-echo' );
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_character_echo_assets', 5 );
