<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_register_fate_of_loom_assets' ) ) {
	function tw_register_fate_of_loom_assets(): void {
		$css_rel = 'assets/css/public/fate-of-loom.css';
		$js_rel  = 'assets/js/public/fate-of-loom.js';

		wp_register_style(
			'tw-fate-of-loom',
			NEOWEAVER_PLUGIN_URL . $css_rel,
			array(),
			file_exists( NEOWEAVER_PLUGIN_DIR . $css_rel ) ? (string) filemtime( NEOWEAVER_PLUGIN_DIR . $css_rel ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'tw-fate-of-loom',
			NEOWEAVER_PLUGIN_URL . $js_rel,
			array( 'chartjs' ),
			file_exists( NEOWEAVER_PLUGIN_DIR . $js_rel ) ? (string) filemtime( NEOWEAVER_PLUGIN_DIR . $js_rel ) : NEOWEAVER_VERSION,
			true
		);
	}
	add_action( 'wp_enqueue_scripts', 'tw_register_fate_of_loom_assets', 5 );
}

if ( ! function_exists( 'tw_enqueue_fate_of_loom_assets' ) ) {
	function tw_enqueue_fate_of_loom_assets(): void {
		static $done = false;

		wp_enqueue_style( 'tw-fate-of-loom' );
		wp_enqueue_script( 'tw-fate-of-loom' );

		if ( $done ) {
			return;
		}
		$done = true;
	}
}
