<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_register_onboarding_assets' ) ) {
	function tw_register_onboarding_assets(): void {
		$css_rel  = 'assets/css/public/onboarding.css';
		$js_rel   = 'assets/js/public/onboarding.js';
		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;

		wp_register_style(
			'neoweaver-onboarding',
			NEOWEAVER_PLUGIN_URL . $css_rel,
			array( '' ),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'neoweaver-onboarding',
			NEOWEAVER_PLUGIN_URL . $js_rel,
			array( 'jquery' ),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}

	add_action( 'wp_enqueue_scripts', 'tw_register_onboarding_assets', 5 );
	add_action( 'wp_enqueue_scripts', function() {
    tw_register_onboarding_assets();
    wp_enqueue_style( 'neoweaver-onboarding' );
    wp_enqueue_script( 'neoweaver-onboarding' );
}, 10 );
}

if ( ! function_exists( 'tw_enqueue_onboarding_assets' ) ) {
	function tw_enqueue_onboarding_assets( array $config = array() ): void {
		static $done = false;

		wp_enqueue_style( 'neoweaver-onboarding' );
		wp_enqueue_script( 'neoweaver-onboarding' );

		if ( $done === true ) {
			return;
		}

		$done = true;

		wp_add_inline_script(
			'neoweaver-onboarding',
			'window.twOnboarding = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
}
