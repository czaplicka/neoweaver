<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_is_adventure_context' ) ) {
	/**
	 * Detect whether current frontend request should load NeoWeaver adventure assets.
	 */
	function tw_is_adventure_context(): bool {
		if ( is_admin() ) {
			return false;
		}

		if ( function_exists( 'tw_has_shortcode_on_current_page' ) && tw_has_shortcode_on_current_page( 'tw_adventure_terminal' ) ) {
			return true;
		}

		return is_page_template( 'templates/adventure.php' );
	}
}

if ( ! function_exists( 'tw_register_adventure_assets' ) ) {
	/**
	 * Register and enqueue all frontend adventure assets.
	 */
	function tw_register_adventure_assets(): void {
		if ( ! tw_is_adventure_context() ) {
			return;
		}

		wp_enqueue_script( 'chartjs' );

		$styles = [
			'neoweaver-tw-core'      => [ 'assets/css/public/core.css', [], 'all' ],
			'neoweaver-tw-chat'      => [ 'assets/css/public/chat.css', [ 'neoweaver-tw-core' ], 'all' ],
			'neoweaver-tw-deck'      => [ 'assets/css/public/deck.css', [ 'neoweaver-tw-core' ], 'all' ],
			'neoweaver-terminal'     => [ 'assets/css/public/terminal.css', [], 'all' ],
			'neoweaver-interference' => [ 'assets/css/public/interference.css', [], 'all' ],
			'world-news'             => [ 'assets/css/public/world-news.css', [], 'all' ],
			'neoweaver-char-panel'   => [ 'assets/css/public/char-panel.css', [ 'neoweaver-tw-core' ], 'all' ],
		];

		foreach ( $styles as $handle => [ $relative_path, $deps, $media ] ) {
			tw_enqueue_style_asset( $handle, $relative_path, $deps, $media );
		}

		$scripts = [
			'nw-panel-tactical-left' => [ 'assets/js/public/panel-tactical-left.js', [] ],
			'neoweaver-interference' => [ 'assets/js/public/interference.js', [ 'jquery' ] ],
			'world-news'             => [ 'assets/js/public/world-news.js', [ 'jquery' ] ],
			'nw-deck-panel'          => [ 'assets/js/public/deck-panel.js', [ 'jquery' ] ],
			'nw-vehicle-panel'       => [ 'assets/js/public/vehicle-panel.js', [ 'jquery' ] ],
			'nw-services'            => [ 'assets/js/public/services.js', [ 'jquery' ] ],
			'neoweaver-header-node'  => [ 'assets/js/public/header-node.js', [] ],
			'neoweaver-ai-chat'      => [ 'assets/js/public/neoweaver-ai-chat.js', [ 'jquery' ] ],
			'tw-adventure'           => [ 'assets/js/adventure.js', [] ],
		];

		foreach ( $scripts as $handle => [ $relative_path, $deps ] ) {
			tw_enqueue_script_asset( $handle, $relative_path, $deps, true );
		}

		$uploads = wp_upload_dir();

		wp_localize_script(
			'neoweaver-header-node',
			'twNeoWeaverData',
			[
				'supabaseUrl' => function_exists( 'tw_supabase_url' ) ? tw_supabase_url() : '',
				'supabaseKey' => function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '',
				'soundsUrl'   => trailingslashit( $uploads['baseurl'] ),
			]
		);

		wp_localize_script(
			'neoweaver-ai-chat',
			'neoweaver_ajax',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'neoweaver_chat' ),
				'is_admin' => current_user_can( 'manage_options' ),
			]
		);

		if ( is_user_logged_in() && function_exists( 'tw_supabase_url' ) && function_exists( 'tw_supabase_anon_key' ) ) {
			$url = tw_supabase_url();
			$key = tw_supabase_anon_key();

			if ( $url && $key ) {
				tw_enqueue_script_asset(
					'neoweaver-supabase-bootstrap',
					'assets/js/public/supabase-bootstrap.js',
					[],
					true
				);

				wp_add_inline_script(
					'neoweaver-supabase-bootstrap',
					'window.twSupabaseConfig = ' . wp_json_encode(
						[
							'url' => $url,
							'key' => $key,
						]
					) . ';',
					'before'
				);
			}
		}
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_adventure_assets' );
