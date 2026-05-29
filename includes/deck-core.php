<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_page_template( 'templates/adventure.php' ) || ! get_current_user_id() ) {
		return;
	}

	$file_rel  = 'assets/js/public/deck-core.js';
	$file_path = trailingslashit( NEOWEAVER_PLUGIN_DIR ) . $file_rel;
	$file_url  = trailingslashit( NEOWEAVER_PLUGIN_URL ) . $file_rel;
	$version   = file_exists( $file_path ) ? (string) filemtime( $file_path ) : NEOWEAVER_VERSION;

	wp_enqueue_script(
		'nw-deck-core',
		$file_url,
		[ 'jquery' ],
		$version,
		true
	);
} );
