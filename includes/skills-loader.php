<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_page_template( 'templates/adventure.php' ) || ! get_current_user_id() ) {
		return;
	}

	$file_path = NW_PLUGIN_DIR . 'assets/js/public/skills-loader.js';
	if ( ! file_exists( $file_path ) ) {
		return;
	}

	wp_enqueue_script(
		'tw-skills-loader',
		NW_PLUGIN_URL . 'assets/js/public/skills-loader.js',
		array(),
		filemtime( $file_path ),
		true
	);
}, 35 );
