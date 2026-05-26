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

		$data = array_merge(
			array(
				'restUrl' => esc_url_raw( rest_url( 'neoweaver/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'uid'     => get_current_user_id(),
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

// Auto-enqueue by page slug — działa zanim shortcode zostanie wykonany.
if ( ! function_exists( 'tw_maybe_enqueue_list_campaigns_assets' ) ) {
	function tw_maybe_enqueue_list_campaigns_assets(): void {
		if ( is_admin() ) {
			return;
		}

		$load = false;

		// Sprawdza popularne slugi stron z listą kampanii.
		if ( is_page( array( 'deployments', 'campaigns', 'my-campaigns', 'dashboard' ) ) ) {
			$load = true;
		}

		// Fallback: sprawdź shortcode w post_content.
		if ( ! $load && is_singular() ) {
			$post = get_post();
			if ( $post ) {
				$load = has_shortcode( $post->post_content, 'tw_list_campaigns' )
					|| ( function_exists( 'tw_has_shortcode_on_current_page' )
						&& tw_has_shortcode_on_current_page( 'tw_list_campaigns' ) );
			}
		}

		if ( $load ) {
			tw_enqueue_list_campaigns_assets();
		}
	}
}

add_action( 'wp_enqueue_scripts', 'tw_maybe_enqueue_list_campaigns_assets', 20 );
