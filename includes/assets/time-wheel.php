<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_register_time_wheel_assets' ) ) {
	function tw_register_time_wheel_assets(): void {
		$css_rel  = 'assets/css/public/time-wheel.css';
		$js_rel   = 'assets/js/public/time-wheel.js';
		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;

		wp_register_style(
			'neoweaver-time-wheel',
			NEOWEAVER_PLUGIN_URL . $css_rel,
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'neoweaver-time-wheel',
			NEOWEAVER_PLUGIN_URL . $js_rel,
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}

	add_action( 'wp_enqueue_scripts', 'tw_register_time_wheel_assets', 5 );
}

if ( ! function_exists( 'tw_enqueue_time_wheel_assets' ) ) {
	function tw_enqueue_time_wheel_assets( array $config = array() ): void {
		static $done = false;

		wp_enqueue_style( 'neoweaver-time-wheel' );
		wp_enqueue_script( 'neoweaver-time-wheel' );

		if ( $done === true ) {
			return;
		}

		$done = true;

		wp_add_inline_script(
			'neoweaver-time-wheel',
			'window.twClockConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
}
