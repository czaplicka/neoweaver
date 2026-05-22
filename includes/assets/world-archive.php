<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_register_world_archive_assets' ) ) {
	function tw_register_world_archive_assets(): void {
		$css_rel  = 'assets/css/public/world-archive.css';
		$js_rel   = 'assets/js/public/world-archive.js';
		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;

		wp_register_style(
			'neoweaver-world-archive',
			NEOWEAVER_PLUGIN_URL . $css_rel,
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'neoweaver-world-archive',
			NEOWEAVER_PLUGIN_URL . $js_rel,
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}

	add_action( 'wp_enqueue_scripts', 'tw_register_world_archive_assets', 5 );
}

if ( ! function_exists( 'tw_enqueue_world_archive_assets' ) ) {
	function tw_enqueue_world_archive_assets(): void {
		wp_enqueue_style( 'neoweaver-world-archive' );
		wp_enqueue_script( 'neoweaver-world-archive' );
	}
}
