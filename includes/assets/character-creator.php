<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_should_enqueue_character_creator_assets' ) ) {
	function tw_should_enqueue_character_creator_assets(): bool {
		if ( is_admin() ) {
			return false;
		}

		if ( is_singular() ) {
			$post = get_post();

			if ( $post && has_shortcode( $post->post_content, 'tale_weaver_character_creator' ) ) {
				return true;
			}

			if ( $post && has_shortcode( $post->post_content, 'neoweaver_character_creator' ) ) {
				return true;
			}
		}

		return false;
	}
}

/**
 * Register (but do not enqueue) the character-creator CSS and JS.
 *
 * By registering on priority 10 the handles are available for:
 *  - wp_enqueue_style/script() calls from within the shortcode itself
 *    (called during content rendering, after wp_enqueue_scripts fires)
 *  - tw_maybe_enqueue_character_creator() on priority 20 (page-detect enqueue)
 *
 * Dependencies:
 *  - tw-gamedata: head-injection inline script that sets window.twAdventureData
 *    (Supabase URL, anon key, character ID). Must execute before CC JS.
 *
 * Note: supabaseUrl / supabaseKey are intentionally omitted from
 * twCharCreatorConfig — they are already available via
 * window.twAdventureData.supabase_url and .supabase_anon_key.
 */
if ( ! function_exists( 'tw_register_character_creator_assets' ) ) {
	function tw_register_character_creator_assets(): void {
		$css_path = NEOWEAVER_PLUGIN_DIR . 'assets/css/public/character-creator.css';
		$js_path  = NEOWEAVER_PLUGIN_DIR . 'assets/js/public/character-creator.js';

		$css_url = NEOWEAVER_PLUGIN_URL . 'assets/css/public/character-creator.css';
		$js_url  = NEOWEAVER_PLUGIN_URL . 'assets/js/public/character-creator.js';

		wp_register_style(
			'neoweaver-character-creator',
			$css_url,
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			'neoweaver-character-creator',
			$js_url,
			[ 'tw-gamedata' ],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);

		$uploads = wp_get_upload_dir();

		wp_add_inline_script(
			'neoweaver-character-creator',
			'window.twCharCreatorConfig = ' . wp_json_encode(
				[
					'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
					'nonce'         => wp_create_nonce( 'neoweaver_nonce' ),
					'siteBase'      => home_url( '/' ),
					'uploadsBase'   => trailingslashit( $uploads['baseurl'] ?? '' ),
					'avatarGallery' => [
						[
							'id'   => 'avatar-1',
							'name' => 'Avatar',
							'url'  => trailingslashit( $uploads['baseurl'] ?? '' ) . 'Avatar.svg',
						],
						[
							'id'   => 'avatar-2',
							'name' => 'Avatar 2',
							'url'  => trailingslashit( $uploads['baseurl'] ?? '' ) . 'Avatar-1.svg',
						],
					],
					'restNonce'     => wp_create_nonce( 'wp_rest' ),
					'restBase'      => home_url( '/wp-json/neoweaver/v1' ),
				]
			) . ';',
			'before'
		);
	}
}

/**
 * Conditionally enqueue already-registered assets when the page contains
 * the character-creator shortcode.
 * Runs at priority 20, after tw_register_character_creator_assets (priority 10).
 */
if ( ! function_exists( 'tw_maybe_enqueue_character_creator' ) ) {
	function tw_maybe_enqueue_character_creator(): void {
		if ( tw_should_enqueue_character_creator_assets() ) {
			wp_enqueue_style( 'neoweaver-character-creator' );
			wp_enqueue_script( 'neoweaver-character-creator' );
		}
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_character_creator_assets', 10 );
add_action( 'wp_enqueue_scripts', 'tw_maybe_enqueue_character_creator',   20 );
