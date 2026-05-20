<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_register_vendor_assets' ) ) {
	/**
	 * Register shared third-party assets once.
	 */
	function tw_register_vendor_assets(): void {
		if ( ! wp_script_is( 'chartjs', 'registered' ) ) {
			wp_register_script(
				'chartjs',
				'https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js',
				[],
				'4.5.1',
				true
			);
		}
	}
}

if ( ! function_exists( 'tw_is_buffer_context' ) ) {
	/**
	 * Detect whether current request needs buffer assets.
	 * Dostosuj warunki do miejsc, gdzie realnie używasz buffera.
	 */
	function tw_is_buffer_context(): bool {
		if ( is_admin() ) {
			return false;
		}

		if ( function_exists( 'tw_has_shortcode_on_current_page' ) && tw_has_shortcode_on_current_page( 'tw_buffer' ) ) {
			return true;
		}

		if ( function_exists( 'tw_has_shortcode_on_current_page' ) && tw_has_shortcode_on_current_page( 'cyber_hud' ) ) {
			return true;
		}

		return false;
	}
}

if ( ! function_exists( 'tw_register_buffer_assets' ) ) {
	/**
	 * Enqueue public buffer assets.
	 */
	function tw_register_buffer_assets(): void {
		if ( ! tw_is_buffer_context() ) {
			return;
		}

		tw_register_vendor_assets();

		tw_enqueue_style_asset(
			'neoweaver-buffer',
			'assets/css/public/buffer.css'
		);

		tw_enqueue_script_asset(
			'neoweaver-buffer',
			'assets/js/public/buffer.js',
			[ 'jquery' ],
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

add_action( 'wp_enqueue_scripts', 'tw_register_vendor_assets', 1 );
add_action( 'wp_enqueue_scripts', 'tw_register_buffer_assets', 20 );
