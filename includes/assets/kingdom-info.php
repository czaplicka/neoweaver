<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_register_kingdom_info_assets' ) ) {
	function tw_register_kingdom_info_assets(): void {
		$css_rel  = 'assets/css/public/kingdom-info.css';
		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;

		wp_register_style(
			'neoweaver-kingdom-info',
			NEOWEAVER_PLUGIN_URL . $css_rel,
			array( 'nw-font-chakra-petch' ),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);
	}

	add_action( 'wp_enqueue_scripts', 'tw_register_kingdom_info_assets', 5 );
}

if ( ! function_exists( 'tw_enqueue_kingdom_info_assets' ) ) {
	function tw_enqueue_kingdom_info_assets(): void {
		wp_enqueue_style( 'neoweaver-kingdom-info' );
	}
}
