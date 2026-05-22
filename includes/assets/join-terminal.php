<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_register_join_terminal_assets' ) ) {
	function tw_register_join_terminal_assets(): void {
		$css_rel  = 'assets/css/public/join-terminal.css';
		$js_rel   = 'assets/js/public/join-terminal.js';
		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;

		wp_register_style(
			'neoweaver-join-terminal-fonts',
			'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;700&family=Share+Tech+Mono&display=swap',
			array(),
			null
		);

		wp_register_style(
			'neoweaver-join-terminal',
			NEOWEAVER_PLUGIN_URL . $css_rel,
			array( 'neoweaver-join-terminal-fonts' ),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'neoweaver-join-terminal',
			NEOWEAVER_PLUGIN_URL . $js_rel,
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}

	add_action( 'wp_enqueue_scripts', 'tw_register_join_terminal_assets', 5 );
}

if ( ! function_exists( 'tw_enqueue_join_terminal_assets' ) ) {
	function tw_enqueue_join_terminal_assets( array $config = array() ): void {
		static $done = false;

		wp_enqueue_style( 'neoweaver-join-terminal-fonts' );
		wp_enqueue_style( 'neoweaver-join-terminal' );
		wp_enqueue_script( 'neoweaver-join-terminal' );

		if ( $done === true ) {
			return;
		}

		$done = true;

		wp_add_inline_script(
			'neoweaver-join-terminal',
			'window.NeoWeaverJoinTerminalConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
}
