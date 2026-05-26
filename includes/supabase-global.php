<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'NEOWEAVER_DEBUG' ) ) {
	define( 'NEOWEAVER_DEBUG', false );
}

if ( ! function_exists( 'nw_debug_log' ) ) {
	function nw_debug_log( $message ) {
		if ( NEOWEAVER_DEBUG ) {
			error_log( '[NeoWeaver] ' . $message );
		}
	}
}

/**
 * Returns true only on pages using the Adventure Template
 * (templates/adventure.php → Template Name: Adventure Template).
 */
function nw_is_adventure_page() {
	if ( ! is_singular() ) {
		return false;
	}
	return 'templates/adventure.php' === get_page_template_slug();
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_user_logged_in() ) {
		nw_debug_log( 'supabase-global: user not logged in, skipping enqueue' );
		return;
	}

	if ( ! nw_is_adventure_page() ) {
		nw_debug_log( 'supabase-global: not an adventure page, skipping enqueue' );
		return;
	}

	nw_debug_log( 'supabase-global: adventure page detected, enqueuing nw-game-data' );

	tw_enqueue_script_asset( 'nw-game-data', 'assets/js/public/game-data.js', [], true );

	$jwt = function_exists( 'tw_supabase_get_current_user_token' )
		? tw_supabase_get_current_user_token()
		: '';

	nw_debug_log( 'supabase-global: JWT present = ' . ( $jwt ? 'yes' : 'no' ) );

	wp_localize_script(
		'nw-game-data',
		'twAdventureData',
		[
			'supabase_url'      => function_exists( 'tw_supabase_url' ) ? tw_supabase_url() : '',
			'supabase_anon_key' => function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '',
			'supabaseToken'     => $jwt,
			'wpUserId'          => get_current_user_id(),
		]
	);
}, 5 );
