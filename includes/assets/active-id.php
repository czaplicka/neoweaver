<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_is_active_id_context' ) ) {
	function tw_is_active_id_context(): bool {
		if ( is_admin() ) {
			return false;
		}

		return function_exists( 'tw_has_shortcode_on_current_page' )
			&& tw_has_shortcode_on_current_page( 'active_id' );
	}
}

if ( ! function_exists( 'tw_register_active_id_assets' ) ) {
	function tw_register_active_id_assets(): void {
		if ( ! tw_is_active_id_context() ) {
			return;
		}

		wp_enqueue_style( 'nw-font-chakra-petch' );

		tw_enqueue_style_asset(
			'nw-active-id',
			'assets/css/public/active-id.css',
			array( 'nw-font-chakra-petch' )
		);
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_active_id_assets', 20 );
