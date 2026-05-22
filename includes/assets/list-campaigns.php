<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_register_list_campaigns_assets' ) ) {
	function tw_register_list_campaigns_assets(): void {
		$core_css_rel  = 'assets/css/public/core.css';
		$core_css_path = NEOWEAVER_PLUGIN_DIR . $core_css_rel;

		$js_rel  = 'assets/js/public/list-campaigns.js';
		$js_path = NEOWEAVER_PLUGIN_DIR . $js_rel;

		wp_register_style(
			'neoweaver-tw-core',
			NEOWEAVER_PLUGIN_URL . $core_css_rel,
			array(),
			file_exists( $core_css_path ) ? (string) filemtime( $core_css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'neoweaver-list-campaigns',
			NEOWEAVER_PLUGIN_URL . $js_rel,
			array( 'jquery' ),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}

	add_action( 'wp_enqueue_scripts', 'tw_register_list_campaigns_assets', 5 );
}

if ( ! function_exists( 'tw_enqueue_list_campaigns_assets' ) ) {
	function tw_enqueue_list_campaigns_assets( array $config = array() ): void {
		static $done = false;

		wp_enqueue_style( 'neoweaver-tw-core' );
		wp_enqueue_script( 'neoweaver-list-campaigns' );

		if ( $done === true ) {
			return;
		}

		$done = true;

		wp_add_inline_script(
			'neoweaver-list-campaigns',
			'window.twCampaignData = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
}
