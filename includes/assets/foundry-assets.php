<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_register_foundry_assets' ) ) {
	function tw_register_foundry_assets(): void {
		$css_rel  = 'assets/css/public/foundry.css';
		$cards_rel = 'assets/css/public/cards.css';
		$js_rel   = 'assets/js/public/foundry.js';
		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;

		// Rejestruj cards.css jako osobny styl (dep dla foundry)
		wp_register_style(
			'neoweaver-cards',
			NEOWEAVER_PLUGIN_URL . $cards_rel,
			array(),
			file_exists( NEOWEAVER_PLUGIN_DIR . $cards_rel )
				? (string) filemtime( NEOWEAVER_PLUGIN_DIR . $cards_rel )
				: NEOWEAVER_VERSION
		);

		wp_register_style(
			'neoweaver-foundry',
			NEOWEAVER_PLUGIN_URL . $css_rel,
			array( 'neoweaver-cards' ), // cards.css ładuje się przed foundry.css
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'neoweaver-foundry',
			NEOWEAVER_PLUGIN_URL . $js_rel,
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}

	add_action( 'wp_enqueue_scripts', 'tw_register_foundry_assets', 5 );
}

if ( ! function_exists( 'tw_enqueue_foundry_assets' ) ) {
	function tw_enqueue_foundry_assets( array $config = array() ): void {
		static $done = false;

		wp_enqueue_style( 'neoweaver-foundry' );
		wp_enqueue_script( 'neoweaver-foundry' );

		if ( $done === true ) {
			return;
		}

		$done = true;

		wp_add_inline_script(
			'neoweaver-foundry',
			'window.NeoWeaverFoundryConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
}
