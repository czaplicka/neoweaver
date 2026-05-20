<?php
/**
 * Template Name: Public Character Profile
 * Post Type: page
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'body_class',
	static function ( array $classes ): array {
		$classes[] = 'character-profile';
		return $classes;
	}
);

get_header();

echo do_shortcode( '[tw_public_character_profile]' );

get_footer();
