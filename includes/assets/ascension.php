<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'register_ascension_assets' ) ) {
	function tw_register_ascension_assets(): void {
		wp_enqueue_script( 'nw-lucide' );

		tw_enqueue_style_asset(
			'ascension',
			'assets/css/public/ascension.css'
		);

		tw_enqueue_script_asset(
			'ascension',
			'assets/js/public/ascension.js',
			[ 'jquery', 'nw-lucide' ],
			true
		);
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_ascension_assets', 20 );
