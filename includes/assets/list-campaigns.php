<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_register_list_campaigns_assets' ) ) {
	function tw_register_list_campaigns_assets(): void {
		$css_rel  = 'assets/css/public/list-campaigns.css';
		$js_rel   = 'assets/js/public/list-campaigns.js';
		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;

		wp_register_style(
			'neoweaver-list-campaigns',
			NEOWEAVER_PLUGIN_URL . $css_rel,
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'neoweaver-list-campaigns',
			NEOWEAVER_PLUGIN_URL . $js_rel,
			array( 'jquery' ),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}
}

if ( ! function_exists( 'tw_enqueue_list_campaigns_assets' ) ) {
	function tw_enqueue_list_campaigns_assets( array $config = array() ): void {
		static $done = false;

		wp_enqueue_style( 'neoweaver-list-campaigns' );
		wp_enqueue_script( 'neoweaver-list-campaigns' );

		if ( $done ) {
			return;
		}

		$done = true;

		$data = array_merge(
			array(
'nonce'       => wp_create_nonce( 'tw__nonce' ),
'restNonce'   => wp_create_nonce( 'wp_rest' ),
'restUrl'     => get_rest_url( null, 'neoweaver/v1/' ),
'sessionUrl'  => get_rest_url( null, 'neoweaver/v1/session/start' ),
'terminalUrl' => home_url( '/terminal/' ),
'agentsUrl'   => home_url( '/agents/?campaign_id=' ),
'lobbyUrl'    => home_url( '/lobby/?campaign_id=' ),
			),
			$config
		);

		wp_add_inline_script(
			'neoweaver-list-campaigns',
			'window.twCampaignData = ' . wp_json_encode( $data ) . ';',
			'before'
		);
	}
}

if ( ! function_exists( 'tw_maybe_enqueue_list_campaigns_assets' ) ) {
	function tw_maybe_enqueue_list_campaigns_assets(): void {
		if ( is_admin() ) {
			return;
		}

		$load = false;

		if ( is_page( 'deployments' ) ) {
			$load = true;
		}

		if ( ! $load && is_singular() ) {
			$post = get_post();
			if ( $post ) {
				$load = has_shortcode( $post->post_content, 'tw_list_campaigns' );
			}
		}

		if ( $load ) {
			tw_enqueue_list_campaigns_assets();
		}
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_list_campaigns_assets', 5 );
add_action( 'wp_enqueue_scripts', 'tw_maybe_enqueue_list_campaigns_assets', 20 );
