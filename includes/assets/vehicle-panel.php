<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_register_vehicle_panel_assets' ) ) {
	function tw_register_vehicle_panel_assets(): void {
		$css_rel  = 'assets/css/public/vehicle-panel.css';
		$js_rel   = 'assets/js/public/vehicle-panel.js';
		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;

		wp_register_style(
			'neoweaver-vehicle-panel',
			NEOWEAVER_PLUGIN_URL . $css_rel,
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'neoweaver-vehicle-panel',
			NEOWEAVER_PLUGIN_URL . $js_rel,
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}

	add_action( 'wp_enqueue_scripts', 'tw_register_vehicle_panel_assets', 5 );
}

if ( ! function_exists( 'tw_enqueue_vehicle_panel_assets' ) ) {
	function tw_enqueue_vehicle_panel_assets( array $config = array() ): void {
		static $done = false;

		wp_enqueue_style( 'neoweaver-vehicle-panel' );
		wp_enqueue_script( 'neoweaver-vehicle-panel' );

		if ( $done === true ) {
			return;
		}

		$done = true;

		wp_add_inline_script(
			'neoweaver-vehicle-panel',
			'window.neoweaveVehicle = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
}
