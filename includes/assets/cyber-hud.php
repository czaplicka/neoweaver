<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_register_cyber_hud_assets' ) ) {
	function tw_register_cyber_hud_assets(): void {
		$module   = 'cyber-hud';
		$css_rel  = 'assets/css/public/' . $module . '.css';
		$js_rel   = 'assets/js/public/' . $module . '.js';
		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;
		$css_url  = NEOWEAVER_PLUGIN_URL . $css_rel;
		$js_url   = NEOWEAVER_PLUGIN_URL . $js_rel;

		wp_register_style(
			'neoweaver-cyber-hud',
			$css_url,
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'neoweaver-cyber-hud',
			$js_url,
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}
}

if ( ! function_exists( 'tw_enqueue_cyber_hud_assets' ) ) {
	function tw_enqueue_cyber_hud_assets( array $config = array() ): void {
		static $inline_done = false;

		wp_enqueue_style( 'neoweaver-cyber-hud' );
		wp_enqueue_script( 'neoweaver-cyber-hud' );

		if ( true === $inline_done ) {
			return;
		}

		if ( ! empty( $config ) ) {
			wp_add_inline_script(
				'neoweaver-cyber-hud',
				'window.twCyberHud = ' . wp_json_encode( $config ) . ';',
				'before'
			);
		}

		$inline_done = true;
	}
}

if ( ! function_exists( 'tw_maybe_enqueue_cyber_hud_assets' ) ) {
	function tw_maybe_enqueue_cyber_hud_assets(): void {
		if ( is_admin() ) {
			return;
		}

		if ( is_page_template( 'templates/adventure.php' ) ) {
			tw_enqueue_cyber_hud_assets();
		}
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_cyber_hud_assets', 5 );
add_action( 'wp_enqueue_scripts', 'tw_maybe_enqueue_cyber_hud_assets', 20 );
