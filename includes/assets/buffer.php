<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_is_buffer_context' ) ) {
	function tw_is_buffer_context(): bool {
		if ( is_admin() ) {
			return false;
		}

		if ( is_page_template( 'templates/adventure.php' ) ) {
			return true;
		}

		if ( function_exists( 'tw_has_shortcode_on_current_page' ) && tw_has_shortcode_on_current_page( 'tw_buffer' ) ) {
			return true;
		}

		return false;
	}
}

if ( ! function_exists( 'tw_register_buffer_assets' ) ) {
	function tw_register_buffer_assets(): void {
		if ( ! tw_is_buffer_context() ) {
			return;
		}

		tw_enqueue_style_asset(
			'neoweaver-buffer',
			'assets/css/public/buffer.css'
		);

		tw_enqueue_script_asset(
			'neoweaver-buffer',
			'assets/js/public/buffer.js',
			[ 'jquery', 'chartjs' ],
			true
		);

		wp_add_inline_script(
			'neoweaver-buffer',
			'window.nwApiData = ' . wp_json_encode(
				[
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'nonces'  => [
						'use_card'  => wp_create_nonce( 'use_card_nonce' ),
						'deck_sync' => wp_create_nonce( 'cyber_deck_nonce' ),
						'foundry'   => wp_create_nonce( 'foundry_nonce' ),
					],
				]
			) . ';',
			'before'
		);
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_buffer_assets', 20 );
