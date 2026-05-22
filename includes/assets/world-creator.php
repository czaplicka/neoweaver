<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_register_world_creator_assets' ) ) {
	function tw_register_world_creator_assets(): void {
		$css_rel          = 'assets/css/public/world-creator.css';
		$js_rel           = 'assets/js/public/world-creator.js';
		$spinner_css_rel  = 'assets/css/public/node-spinner.css';

		$css_path         = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path          = NEOWEAVER_PLUGIN_DIR . $js_rel;
		$spinner_css_path = NEOWEAVER_PLUGIN_DIR . $spinner_css_rel;

		wp_register_style(
			'neoweaver-world-creator',
			NEOWEAVER_PLUGIN_URL . $css_rel,
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_style(
			'neoweaver-node-spinner',
			NEOWEAVER_PLUGIN_URL . $spinner_css_rel,
			array(),
			file_exists( $spinner_css_path ) ? (string) filemtime( $spinner_css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'neoweaver-world-creator',
			NEOWEAVER_PLUGIN_URL . $js_rel,
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}

	add_action( 'wp_enqueue_scripts', 'tw_register_world_creator_assets', 5 );
}

if ( ! function_exists( 'tw_enqueue_world_creator_assets' ) ) {
	function tw_enqueue_world_creator_assets( array $config = array() ): void {
		static $done = false;

		wp_enqueue_style( 'neoweaver-world-creator' );
		wp_enqueue_style( 'neoweaver-node-spinner' );
		wp_enqueue_script( 'neoweaver-world-creator' );

		if ( $done === true ) {
			return;
		}

		$done = true;

		wp_add_inline_script(
			'neoweaver-world-creator',
			'window.twWorldCreatorConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
}
