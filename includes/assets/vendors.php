<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_register_vendor_assets' ) ) {
	/**
	 * Register shared third-party/vendor assets used across NeoWeaver.
	 */
	function tw_register_vendor_assets(): void {
		if ( ! wp_style_is( 'nw-font-chakra-petch', 'registered' ) ) {
			wp_register_style(
				'nw-font-chakra-petch',
				'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
				[],
				null
			);
		}

		if ( ! wp_script_is( 'nw-lucide', 'registered' ) ) {
			wp_register_script(
				'nw-lucide',
				'https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js',
				[],
				'0.468.0',
				true
			);
		}

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

add_action( 'wp_enqueue_scripts', 'tw_register_vendor_assets', 1 );
add_action( 'admin_enqueue_scripts', 'tw_register_vendor_assets', 1 );
