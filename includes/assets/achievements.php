<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_is_achievements_context' ) ) {
	function tw_is_achievements_context(): bool {
		if ( is_admin() ) {
			return false;
		}

		return function_exists( 'tw_has_shortcode_on_current_page' )
			&& tw_has_shortcode_on_current_page( 'achievements' );
	}
}

if ( ! function_exists( 'tw_register_achievements_assets' ) ) {
	function tw_register_achievements_assets(): void {
		if ( ! tw_is_achievements_context() ) {
			return;
		}

		wp_enqueue_script( 'nw-lucide' );

		tw_enqueue_style_asset(
			'neoweaver-achievements',
			'assets/css/public/achievements.css'
		);

		tw_enqueue_script_asset(
			'neoweaver-achievements',
			'assets/js/public/achievements.js',
			[ 'jquery', 'nw-lucide' ],
			true
		);
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_achievements_assets', 20 );
