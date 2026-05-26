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

nw_debug_log( 'supabase-global loaded' );

add_action( 'wp_enqueue_scripts', function () {
	nw_debug_log( 'supabase-global enqueue hook fired' );

	if ( ! is_user_logged_in() ) {
		nw_debug_log( 'user not logged in' );
		return;
	}

	nw_debug_log( 'user logged in, enqueue supabase init' );

	tw_enqueue_script_asset( 'nw-game-data', 'assets/js/public/game-data.js', [], true );

	$jwt = function_exists( 'tw_supabase_get_current_user_token' )
		? tw_supabase_get_current_user_token()
		: '';

	nw_debug_log( 'JWT present = ' . ( $jwt ? 'yes' : 'no' ) );

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
