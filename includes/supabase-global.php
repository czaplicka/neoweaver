<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

error_log( 'NW: supabase-global loaded' );

add_action( 'wp_enqueue_scripts', function () {
	error_log( 'NW: supabase-global enqueue hook fired' );

	if ( ! is_user_logged_in() ) {
		error_log( 'NW: user not logged in' );
		return;
	}

	error_log( 'NW: user logged in, enqueue supabase init' );

	tw_enqueue_script_asset( 'nw-game-data', 'assets/js/public/game-data.js', [], true );

	$jwt = function_exists( 'tw_supabase_get_current_user_token' )
		? tw_supabase_get_current_user_token()
		: '';

	error_log( 'NW: JWT present = ' . ( $jwt ? 'yes' : 'no' ) );

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
