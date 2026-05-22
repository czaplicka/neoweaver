<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_register_essences_assets' ) ) {
	function tw_register_essences_assets(): void {
		$module   = 'essences';
		$css_rel  = 'assets/css/public/' . $module . '.css';
		$js_rel   = 'assets/js/public/' . $module . '.js';
		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;

		wp_register_style(
			'neoweaver-essences',
			NEOWEAVER_PLUGIN_URL . $css_rel,
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'neoweaver-essences',
			NEOWEAVER_PLUGIN_URL . $js_rel,
			[],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}
}

if ( ! function_exists( 'tw_enqueue_essences_assets' ) ) {
	function tw_enqueue_essences_assets(): void {
		static $done = false;

		wp_enqueue_style( 'neoweaver-essences' );
		wp_enqueue_script( 'neoweaver-essences' );

		$done = true;
	}
}

if ( ! function_exists( 'tw_maybe_enqueue_essences_assets' ) ) {
	function tw_maybe_enqueue_essences_assets(): void {
		if ( is_admin() ) {
			return;
		}

		if ( is_page_template( 'templates/adventure.php' ) ) {
			tw_enqueue_essences_assets();
		}
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_essences_assets', 5 );
add_action( 'wp_enqueue_scripts', 'tw_maybe_enqueue_essences_assets', 20 );
