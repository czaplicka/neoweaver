<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_register_compass_assets' ) ) {
	function tw_register_compass_assets(): void {
		$css_handle = 'neoweaver-compass';
		$js_handle  = 'neoweaver-compass';

		$css_rel = 'assets/css/public/compass.css';
		$js_rel  = 'assets/js/public/compass.js';

		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;

		$css_url = NEOWEAVER_PLUGIN_URL . $css_rel;
		$js_url  = NEOWEAVER_PLUGIN_URL . $js_rel;

		wp_register_style(
			$css_handle,
			$css_url,
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		$script_deps = [];

		if ( wp_script_is( 'tw-adventure', 'registered' ) || wp_script_is( 'tw-adventure', 'enqueued' ) ) {
			$script_deps[] = 'tw-adventure';
		}

		wp_register_script(
			$js_handle,
			$js_url,
			$script_deps,
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}
}

if ( ! function_exists( 'tw_enqueue_compass_assets' ) ) {
	function tw_enqueue_compass_assets(): void {
		$wp_user_id = get_current_user_id();

		if ( ! $wp_user_id ) {
			return;
		}

		if ( ! is_page_template( 'templates/adventure.php' ) ) {
			return;
		}

		wp_enqueue_style( 'neoweaver-compass' );
		wp_enqueue_script( 'neoweaver-compass' );

		$game_data = function_exists( 'get_user_game_data_from_supabase' )
			? get_user_game_data_from_supabase( (int) $wp_user_id )
			: [];

		wp_add_inline_script(
			'neoweaver-compass',
			'window.twCompassData = ' . wp_json_encode(
				[
					'wpUserId'         => (int) $wp_user_id,
					'activeLocationId' => isset( $game_data['active_location_id'] ) ? (int) $game_data['active_location_id'] : 0,
					'activeWorldId'    => isset( $game_data['active_world_id'] ) ? (string) $game_data['active_world_id'] : '',
					'activeSessionId'  => isset( $game_data['active_session_id'] ) ? (string) $game_data['active_session_id'] : '',
				]
			) . ';',
			'before'
		);
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_compass_assets', 5 );
