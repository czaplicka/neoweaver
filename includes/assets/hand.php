<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_register_buffer_hand_assets' ) ) {
	function tw_register_buffer_hand_assets(): void {
		$css_rel  = 'assets/css/public/hand.css';
		$js_rel   = 'assets/js/public/hand.js';
		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;

		wp_register_style(
			'neoweaver-buffer-hand',
			NEOWEAVER_PLUGIN_URL . $css_rel,
			array( 'swiper' ),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'neoweaver-buffer-hand',
			NEOWEAVER_PLUGIN_URL . $js_rel,
			array( 'swiper' ),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}

	add_action( 'wp_enqueue_scripts', 'tw_register_buffer_hand_assets', 5 );
}

if ( ! function_exists( 'tw_enqueue_buffer_hand_assets' ) ) {
	function tw_enqueue_buffer_hand_assets( array $config = array() ): void {
		static $done = false;

		wp_enqueue_style( 'swiper' );
		wp_enqueue_script( 'swiper' );

		wp_enqueue_style( 'neoweaver-buffer-hand' );
		wp_enqueue_script( 'neoweaver-buffer-hand' );

		if ( $done === true ) {
			return;
		}

		$done = true;

		wp_add_inline_script(
			'neoweaver-buffer-hand',
			'window.NeoWeaverBufferHandConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
}
