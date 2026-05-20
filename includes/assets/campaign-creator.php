<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_is_campaign_creator_context' ) ) {
	function tw_is_campaign_creator_context(): bool {
		if ( is_admin() ) {
			return false;
		}

		return function_exists( 'tw_has_shortcode_on_current_page' )
			&& tw_has_shortcode_on_current_page( 'tw_create_campaign' );
	}
}

if ( ! function_exists( 'tw_register_campaign_creator_assets' ) ) {
	function tw_register_campaign_creator_assets(): void {
		if ( ! tw_is_campaign_creator_context() ) {
			return;
		}

		tw_enqueue_style_asset(
			'neoweaver-campaign-creator',
			'assets/css/public/campaign-creator.css'
		);

		tw_enqueue_script_asset(
			'neoweaver-campaign-creator',
			'assets/js/public/campaign-creator.js',
			[],
			true
		);

		if ( file_exists( tw_asset_path( 'assets/css/public/node-spinner.css' ) ) ) {
			tw_enqueue_style_asset(
				'neoweaver-node-spinner',
				'assets/css/public/node-spinner.css'
			);
		}

		wp_add_inline_script(
			'neoweaver-campaign-creator',
			'window.twCampaignConfig = ' . wp_json_encode(
				[
					'nonce'        => wp_create_nonce( 'tw_campaign_nonce' ),
					'restNonce'    => wp_create_nonce( 'wp_rest' ),
					'restUrl'      => home_url( '/wp-json/neoweaver/v1/campaign/create' ),
					'campaignsUrl' => home_url( '/deployments/' ),
					'supabaseUrl'  => function_exists( 'tw_supabase_url' ) ? tw_supabase_url() : '',
					'supabaseKey'  => function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '',
					'userId'       => get_current_user_id(),
					'uploadsUrl'   => wp_upload_dir()['baseurl'] ?? '',
				]
			) . ';',
			'before'
		);
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_campaign_creator_assets', 20 );
