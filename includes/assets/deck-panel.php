<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! function_exists( 'tw_register_deck_panel_assets' ) ) {
	function tw_register_deck_panel_assets(): void {
		$module   = 'deck-panel';
		$css_rel  = 'assets/css/public/' . $module . '.css';
		$js_rel   = 'assets/js/public/' . $module . '.js';
		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;
		$css_url  = NEOWEAVER_PLUGIN_URL . $css_rel;
		$js_url   = NEOWEAVER_PLUGIN_URL . $js_rel;

		wp_register_style(
			'neoweaver-deck-panel',
			$css_url,
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'neoweaver-deck-panel',
			$js_url,
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}
}

if ( ! function_exists( 'tw_enqueue_deck_panel_assets' ) ) {
	function tw_enqueue_deck_panel_assets( array $config = array() ): void {
		static $done = false;

		wp_enqueue_style( 'neoweaver-deck-panel' );
		wp_enqueue_script( 'neoweaver-deck-panel' );

		if ( true === $done ) {
			return;
		}

		if ( ! empty( $config ) ) {
			wp_add_inline_script(
				'neoweaver-deck-panel',
				'window.twDeckPanelConfig = ' . wp_json_encode( $config ) . ';',
				'before'
			);
		}

		$done = true;
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_deck_panel_assets', 5 );
