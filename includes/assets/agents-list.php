<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_register_agents_list_assets' ) ) {
	function tw_register_agents_list_assets(): void {
		$css_rel  = 'assets/css/public/agents-list.css';
		$js_rel   = 'assets/js/public/agents-list.js';
		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;

		wp_register_style(
			'tw-agents-list',
			NEOWEAVER_PLUGIN_URL . $css_rel,
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'tw-agents-list',
			NEOWEAVER_PLUGIN_URL . $js_rel,
			[],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}
}

if ( ! function_exists( 'tw_enqueue_agents_list_assets' ) ) {
	function tw_enqueue_agents_list_assets(): void {
		static $done = false;

		wp_enqueue_style( 'tw-agents-list' );
		wp_enqueue_script( 'tw-agents-list' );

		if ( $done ) {
			return;
		}

		$done = true;

		wp_add_inline_script(
			'tw-agents-list',
			'window.twCharData = ' . wp_json_encode(
				[
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'tw_char_nonce' ),
				]
			) . ';',
			'before'
		);

		wp_add_inline_script(
			'tw-agents-list',
			'window.twCampaignData = ' . wp_json_encode(
				[
					'nonce'       => wp_create_nonce( 'neoweaver_game' ),
					'restNonce'   => wp_create_nonce( 'wp_rest' ),
					'sessionUrl'  => rest_url( 'neoweaver/v1/session/start' ),
					'terminalUrl' => home_url( '/terminal/' ),
					'lobbyUrl'    => home_url( '/lobby/?campaign_id=' ),
					'agentsUrl'   => home_url( '/agents/?campaign_id=' ),
				]
			) . ';',
			'before'
		);
	}
}

if ( ! function_exists( 'tw_maybe_enqueue_agents_list_assets' ) ) {
	function tw_maybe_enqueue_agents_list_assets(): void {
		if ( is_admin() ) {
			return;
		}

		// Load on any page with slug 'agents' or that contains the shortcode.
		$load = false;

		if ( is_page( 'agents' ) ) {
			$load = true;
		}

		if ( ! $load && is_singular() ) {
			$post = get_post();
			if ( $post ) {
				$load = has_shortcode( $post->post_content, 'tw_characters_list' )
					|| ( function_exists( 'tw_has_shortcode_on_current_page' )
						&& tw_has_shortcode_on_current_page( 'tw_characters_list' ) );
			}
		}

		if ( $load ) {
			tw_enqueue_agents_list_assets();
		}
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_agents_list_assets', 5 );
add_action( 'wp_enqueue_scripts', 'tw_maybe_enqueue_agents_list_assets', 20 );
