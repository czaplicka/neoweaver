<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_is_adventure_runtime_context' ) ) {
	function tw_is_adventure_runtime_context(): bool {
		if ( is_admin() ) {
			return false;
		}

		return is_page_template( 'templates/adventure.php' );
	}
}

if ( ! function_exists( 'tw_register_public_runtime_assets' ) ) {
	function tw_register_public_runtime_assets(): void {
		if ( ! tw_is_adventure_runtime_context() ) {
			return;
		}

		tw_enqueue_script_asset(
			'neoweaver-public-runtime',
			'assets/js/public/class-public.js',
			[],
			true
		);
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_public_runtime_assets', 20 );
