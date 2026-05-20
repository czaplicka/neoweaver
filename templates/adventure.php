<?php
/**
 * Template Name: Adventure Template
 * Post Type: page
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'body_class',
	function ( $classes ) {
		$classes[] = 'tw-adventure-page';
		return $classes;
	}
);

get_header();

echo do_shortcode( '[tw_adventure_terminal]' );

get_footer();
