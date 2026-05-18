<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_page_template( 'templates/adventure.php' ) || ! get_current_user_id() ) {
		return;
	}

	wp_enqueue_script(
		'tw-skills-loader',
		get_stylesheet_directory_uri() . '/assets/js/skills-loader.js',
		array(),
		filemtime( get_stylesheet_directory() . '/assets/js/skills-loader.js' ),
		true
	);
}, 35 );
