<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_user_logged_in() ) {
		return;
	}

	tw_enqueue_script_asset( 'nw-game-data', 'assets/js/public/game-data.js', [], true );

	$jwt = function_exists( 'tw_supabase_get_current_user_token' ) ? tw_supabase_get_current_user_token() : '';

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
