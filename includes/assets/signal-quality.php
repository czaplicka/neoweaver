<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_register_signal_quality_assets' ) ) {
	function tw_register_signal_quality_assets(): void {
		$css_rel  = 'assets/css/public/signal-quality.css';
		$js_rel   = 'assets/js/public/signal-quality.js';
		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;

		wp_register_style(
			'neoweaver-signal-quality',
			NEOWEAVER_PLUGIN_URL . $css_rel,
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'neoweaver-signal-quality',
			NEOWEAVER_PLUGIN_URL . $js_rel,
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}

	add_action( 'wp_enqueue_scripts', 'tw_register_signal_quality_assets', 5 );
}

if ( ! function_exists( 'tw_enqueue_signal_quality_assets' ) ) {
	function tw_enqueue_signal_quality_assets(): void {
		wp_enqueue_style( 'neoweaver-signal-quality' );
		wp_enqueue_script( 'neoweaver-signal-quality' );
	}
}
