<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_is_agents_list_context' ) ) {
	function tw_is_agents_list_context(): bool {
		if ( is_admin() ) {
			return false;
		}

		return function_exists( 'tw_has_shortcode_on_current_page' )
			&& tw_has_shortcode_on_current_page( 'tw_characters_list' );
	}
}

if ( ! function_exists( 'tw_register_agents_list_assets' ) ) {
	function tw_register_agents_list_assets(): void {
		if ( ! tw_is_agents_list_context() ) {
			return;
		}

		tw_enqueue_style_asset(
			'tw-agents-list',
			'assets/css/public/agents-list.css'
		);

		tw_enqueue_script_asset(
			'tw-agents-list',
			'assets/js/public/agents-list.js',
			[],
			true
		);

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

add_action( 'wp_enqueue_scripts', 'tw_register_agents_list_assets' );
