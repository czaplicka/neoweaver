<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_page_template( 'templates/adventure.php' ) ) { return; }
	$user_id = get_current_user_id();
	if ( ! $user_id ) { return; }

	wp_enqueue_script(
		'supabase-js',
		'https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2/dist/umd/supabase.js',
		array(), '2', true
	);

	$file_rel  = 'assets/js/chat-engine.js';
	$file_path = trailingslashit( NEOWEAVER_PLUGIN_DIR ) . $file_rel;
	$file_url  = trailingslashit( NEOWEAVER_PLUGIN_URL ) . $file_rel;
	$version   = file_exists( $file_path ) ? (string) filemtime( $file_path ) : NEOWEAVER_VERSION;

	wp_enqueue_script( 'nw-chat-engine', $file_url, array( 'supabase-js' ), $version, true );

	$char_id     = sanitize_text_field( get_query_var( 'tw_char_id',    get_user_meta( $user_id, 'tw_active_char_id',    true ) ) );
	$channel_id  = sanitize_text_field( get_query_var( 'tw_channel_id', get_user_meta( $user_id, 'tw_active_channel_id', true ) ) );
	$session_id  = sanitize_text_field( get_user_meta( $user_id, 'tw_active_session_id',  true ) );
	$campaign_id = sanitize_text_field( get_user_meta( $user_id, 'tw_active_campaign_id', true ) );

	wp_localize_script( 'nw-chat-engine', 'nwChat', [
		'supabaseUrl' => tw_supabase_url(),
		'supabaseKey' => tw_supabase_anon_key(),
		'restUrl'     => rest_url( 'neoweaver/v1/ai-chat' ),
		'nonce'       => wp_create_nonce( 'wp_rest' ),
		'charId'      => $char_id,
		'channelId'   => $channel_id,
		'sessionId'   => $session_id,
		'campaignId'  => $campaign_id,
	] );
}, 25 );
