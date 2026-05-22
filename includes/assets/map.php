<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_register_map_assets' ) ) {
	function tw_register_map_assets(): void {
		$css_rel  = 'assets/css/public/map.css';
		$js_rel   = 'assets/js/public/map.js';
		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;

		wp_register_style(
			'neoweaver-map',
			NEOWEAVER_PLUGIN_URL . $css_rel,
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'neoweaver-d3',
			'https://d3js.org/d3.v7.min.js',
			array(),
			'7.9.0',
			true
		);

		wp_register_script(
			'neoweaver-map-script',
			NEOWEAVER_PLUGIN_URL . $js_rel,
			array( 'jquery', 'neoweaver-header-node', 'neoweaver-d3' ),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}

	add_action( 'wp_enqueue_scripts', 'tw_register_map_assets', 5 );
}

if ( ! function_exists( 'tw_enqueue_map_assets' ) ) {
	function tw_enqueue_map_assets( array $config = array() ): void {
		static $done = false;

		wp_enqueue_style( 'neoweaver-map' );
		wp_enqueue_script( 'neoweaver-d3' );
		wp_enqueue_script( 'neoweaver-map-script' );

		if ( $done === true ) {
			return;
		}

		$done = true;

		wp_add_inline_script(
			'neoweaver-map-script',
			'window.twMapData = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
}
