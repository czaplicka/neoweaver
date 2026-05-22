<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_register_character_echo_assets' ) ) {
	function tw_register_character_echo_assets(): void {
		$module   = 'character-echo';
		$css_rel  = 'assets/css/public/' . $module . '.css';
		$js_rel   = 'assets/js/public/' . $module . '.js';
		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;
		$css_url  = NEOWEAVER_PLUGIN_URL . $css_rel;
		$js_url   = NEOWEAVER_PLUGIN_URL . $js_rel;

		wp_register_style(
			'neoweaver-character-echo',
			$css_url,
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'neoweaver-character-echo',
			$js_url,
			[],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}
}

if ( ! function_exists( 'tw_enqueue_character_echo_assets' ) ) {
	function tw_enqueue_character_echo_assets(): void {
		static $done = false;

		wp_enqueue_style( 'neoweaver-character-echo' );
		wp_enqueue_script( 'neoweaver-character-echo' );

		$done = true;
	}
}

if ( ! function_exists( 'tw_maybe_enqueue_character_echo_assets' ) ) {
	function tw_maybe_enqueue_character_echo_assets(): void {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		$post = get_post();

		if ( ! $post || empty( $post->post_content ) ) {
			return;
		}

		if ( has_shortcode( $post->post_content, 'tw_character_echo' ) ) {
			tw_enqueue_character_echo_assets();
		}
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_character_echo_assets', 5 );
add_action( 'wp_enqueue_scripts', 'tw_maybe_enqueue_character_echo_assets', 20 );
