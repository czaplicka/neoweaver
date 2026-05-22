<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_register_lobby_assets' ) ) {
	function tw_register_lobby_assets(): void {
		$css_rel  = 'assets/css/public/lobby.css';
		$js_rel   = 'assets/js/public/lobby.js';
		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;

		wp_register_style(
			'neoweaver-lobby',
			NEOWEAVER_PLUGIN_URL . $css_rel,
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'neoweaver-lobby',
			NEOWEAVER_PLUGIN_URL . $js_rel,
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}

	add_action( 'wp_enqueue_scripts', 'tw_register_lobby_assets', 5 );
}

if ( ! function_exists( 'tw_enqueue_lobby_assets' ) ) {
	function tw_enqueue_lobby_assets( array $config = array() ): void {
		static $done = false;

		wp_enqueue_style( 'neoweaver-lobby' );
		wp_enqueue_script( 'neoweaver-lobby' );

		if ( $done === true ) {
			return;
		}

		$done = true;

		wp_add_inline_script(
			'neoweaver-lobby',
			'window.NeoWeaverLobbyConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
}
