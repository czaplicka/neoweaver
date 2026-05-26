<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_register_compass_assets' ) ) {
	function tw_register_compass_assets(): void {
		$module   = 'compass';
		$css_rel  = 'assets/css/public/' . $module . '.css';
		$js_rel   = 'assets/js/public/' . $module . '.js';
		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;
		$css_url  = NEOWEAVER_PLUGIN_URL . $css_rel;
		$js_url   = NEOWEAVER_PLUGIN_URL . $js_rel;

		wp_register_style(
			'neoweaver-compass',
			$css_url,
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'neoweaver-compass',
			$js_url,
			[],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}
}

if ( ! function_exists( 'tw_enqueue_compass_assets' ) ) {
	function tw_enqueue_compass_assets(): void {
		static $done = false;

		$wp_user_id = get_current_user_id();

		if ( ! $wp_user_id ) {
			return;
		}

		wp_enqueue_style( 'neoweaver-compass' );
		wp_enqueue_script( 'neoweaver-compass' );

		if ( $done ) {
			return;
		}

		$done = true;

		$game_data = function_exists( 'get_user_game_data_from_supabase' )
			? get_user_game_data_from_supabase( (int) $wp_user_id )
			: [];

		wp_add_inline_script(
			'neoweaver-compass',
			'window.twCompassData = ' . wp_json_encode(
				[
					'wpUserId'         => (int) $wp_user_id,
					'activeLocationId' => isset( $game_data['active_location_id'] ) ? (string) $game_data['active_location_id'] : '',
					'activeWorldId'    => isset( $game_data['active_world_id'] ) ? (string) $game_data['active_world_id'] : '',
					'activeSessionId'  => isset( $game_data['active_session_id'] ) ? (string) $game_data['active_session_id'] : '',
				]
			) . ';',
			'before'
		);
	}
}

if ( ! function_exists( 'tw_maybe_enqueue_compass_assets' ) ) {
	function tw_maybe_enqueue_compass_assets(): void {
		if ( is_admin() ) {
			return;
		}

		if ( is_page_template( 'templates/adventure.php' ) ) {
			tw_enqueue_compass_assets();
		}
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_compass_assets', 5 );
add_action( 'wp_enqueue_scripts', 'tw_maybe_enqueue_compass_assets', 20 );
