<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_register_connect_campaign_world_assets' ) ) {
	function tw_register_connect_campaign_world_assets(): void {
		$css_handle = 'tw-deployment';
		$js_handle  = 'tw-deployment';

		$css_rel = 'assets/css/public/deployment.css';
		$js_rel  = 'assets/js/public/deployment.js';

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

if ( ! function_exists( 'tw_enqueue_connect_campaign_world_assets' ) ) {
	function tw_enqueue_connect_campaign_world_assets(): void {
		static $done = false;

		wp_enqueue_style( 'tw-deployment' );
		wp_enqueue_script( 'tw-deployment' );

		if ( $done ) {
			return;
		}

		$done = true;

		// Tylko REST — service key zostaje po stronie serwera PHP, nie trafia do JS.
		$config = [
			'restUrl' => esc_url_raw( rest_url( 'neoweaver/v1/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'uid'     => get_current_user_id(),
		];

		wp_add_inline_script(
			'tw-deployment',
			'window.twDeploymentCfg = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
}

if ( ! function_exists( 'tw_maybe_enqueue_connect_campaign_world_assets' ) ) {
	function tw_maybe_enqueue_connect_campaign_world_assets(): void {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		$post = get_post();

		if ( ! $post || empty( $post->post_content ) ) {
			return;
		}

		if ( has_shortcode( $post->post_content, 'tw_connect_campaign_world' ) ) {
			tw_enqueue_connect_campaign_world_assets();
		}
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_connect_campaign_world_assets', 5 );
add_action( 'wp_enqueue_scripts', 'tw_maybe_enqueue_connect_campaign_world_assets', 20 );
