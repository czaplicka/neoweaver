<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_register_connect_character_campaign_assets' ) ) {
	function tw_register_connect_character_campaign_assets(): void {
		$css_handle = 'neoweaver-deployment';
		$js_handle  = 'neoweaver-deployment';

		$css_rel = 'assets/css/public/deployment2.css';
		$js_rel  = 'assets/js/public/deployment2.js';

		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;

		wp_register_style(
			$css_handle,
			NEOWEAVER_PLUGIN_URL . $css_rel,
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			$js_handle,
			NEOWEAVER_PLUGIN_URL . $js_rel,
			[],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}
}

if ( ! function_exists( 'tw_enqueue_connect_character_campaign_assets' ) ) {
	function tw_enqueue_connect_character_campaign_assets(): void {
		static $done = false;

		wp_enqueue_style( 'neoweaver-deployment' );
		wp_enqueue_script( 'neoweaver-deployment' );

		if ( $done ) {
			return;
		}

		$done = true;

		$supabase_url = function_exists( 'tw_supabase_url' ) ? trailingslashit( tw_supabase_url() ) : '';
		$anon_key     = function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '';

		$config = [
			'userId'         => get_current_user_id(),
			'supabaseUrl'    => $supabase_url,
			'supabaseKey'    => $anon_key,
			'restNonce'      => wp_create_nonce( 'wp_rest' ),
			'nonce'          => wp_create_nonce( 'tw_deployment_nonce' ),
			'deploymentsUrl' => home_url( '/deployments/' ),
		];

		wp_add_inline_script(
			'neoweaver-deployment',
			'window.twDeploymentConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_connect_character_campaign_assets', 5 );
