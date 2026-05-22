<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_register_quick_actions_cmd_center_assets' ) ) {
	function tw_register_quick_actions_cmd_center_assets(): void {
		$css_rel  = 'assets/css/public/quick-actions-cmd-center.css';
		$js_rel   = 'assets/js/public/quick-actions-cmd-center.js';
		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;

		wp_register_style(
			'neoweaver-quick-actions-cmd-center',
			NEOWEAVER_PLUGIN_URL . $css_rel,
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'neoweaver-quick-actions-cmd-center',
			NEOWEAVER_PLUGIN_URL . $js_rel,
			array( 'jquery' ),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}

	add_action( 'wp_enqueue_scripts', 'tw_register_quick_actions_cmd_center_assets', 5 );
}

if ( ! function_exists( 'tw_enqueue_quick_actions_cmd_center_assets' ) ) {
	function tw_enqueue_quick_actions_cmd_center_assets( array $config = array() ): void {
		static $done = false;

		wp_enqueue_style( 'neoweaver-quick-actions-cmd-center' );
		wp_enqueue_script( 'neoweaver-quick-actions-cmd-center' );

		if ( $done === true ) {
			return;
		}

		$done = true;

		wp_add_inline_script(
			'neoweaver-quick-actions-cmd-center',
			'window.twQuickActionsData = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
}
