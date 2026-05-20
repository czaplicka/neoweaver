<?php
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tw_is_public_character_profile_context' ) ) {
	/**
	 * Detect whether current frontend request should load public character profile assets.
	 */
	function tw_is_public_character_profile_context(): bool {
		if ( is_admin() ) {
			return false;
		}

		if ( function_exists( 'tw_has_shortcode_on_current_page' ) && tw_has_shortcode_on_current_page( 'tw_public_character_profile' ) ) {
			return true;
		}

		return is_page_template( 'templates/public-character-profile.php' );
	}
}

if ( ! function_exists( 'tw_register_public_character_profile_assets' ) ) {
	/**
	 * Register and enqueue all frontend assets for public character profile.
	 */
	function tw_register_public_character_profile_assets(): void {
		if ( ! tw_is_public_character_profile_context() ) {
			return;
		}

		tw_enqueue_style_asset(
			'neo-public-character-profile',
			'assets/css/public/public-character-profile.css'
		);

		wp_enqueue_script( 'chartjs' );

		tw_enqueue_script_asset(
			'neo-public-character-profile',
			'assets/js/public/public-character-profile.js',
			[ 'chartjs' ],
			true
		);
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_public_character_profile_assets' );
