<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', 'tw_enqueue_quick_actions_cmd_center_assets', 46 );

function tw_enqueue_quick_actions_cmd_center_assets() {
	if ( is_admin() ) {
		return;
	}

	if ( ! is_page_template( 'templates/adventure.php' ) ) {
		return;
	}

	$plugin_url = defined( 'NEOWEAVER_PLUGIN_URL' )
		? NEOWEAVER_PLUGIN_URL
		: plugin_dir_url( dirname( __FILE__, 2 ) );

	$plugin_dir = defined( 'NEOWEAVER_PLUGIN_DIR' )
		? NEOWEAVER_PLUGIN_DIR
		: plugin_dir_path( dirname( __FILE__, 2 ) );

	$js_rel  = 'assets/js/public/quick-actions-cmd-center.js';
	$js_path = trailingslashit( $plugin_dir ) . $js_rel;
	$js_url  = trailingslashit( $plugin_url ) . $js_rel;
	$js_ver  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : '2.2.0';

	wp_enqueue_script(
		'tw-quick-actions-cmd-center',
		$js_url,
		array( 'jquery' ),
		$js_ver,
		true
	);

	$config = array(
		'supabaseUrl' => trailingslashit( tw_supabase_url() ),
		'anonKey'     => tw_supabase_anon_key(),
		'searchDebounce' => 200,
		'confirmDeleteCustomAction' => 'Delete custom action?',
		'requiredFieldsMessage'     => 'Label and Prompt are required!',
	);

	wp_add_inline_script(
		'tw-quick-actions-cmd-center',
		'window.twQuickActionsData = ' . wp_json_encode( $config ) . ';',
		'before'
	);
}
