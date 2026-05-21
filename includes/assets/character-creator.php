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

if ( ! function_exists( 'tw_register_character_creator_assets' ) ) {
	function tw_register_character_creator_assets(): void {
		if ( ! tw_should_enqueue_character_creator_assets() ) {
			return;
		}

		$css_handle = 'neoweaver-character-creator';
		$js_handle  = 'neoweaver-character-creator';

		$css_path = NEOWEAVER_PLUGIN_DIR . 'assets/css/public/character-creator.css';
		$js_path  = NEOWEAVER_PLUGIN_DIR . 'assets/js/public/character-creator.js';

		$css_url = NEOWEAVER_PLUGIN_URL . 'assets/css/public/character-creator.css';
		$js_url  = NEOWEAVER_PLUGIN_URL . 'assets/js/public/character-creator.js';

		wp_enqueue_style(
			$css_handle,
			$css_url,
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_enqueue_script(
			$js_handle,
			$js_url,
			[],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);

		$uploads = wp_get_upload_dir();

		wp_add_inline_script(
			$js_handle,
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
					'supabaseUrl'   => function_exists( 'tw_supabase_url' ) ? tw_supabase_url() : '',
					'supabaseKey'   => function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '',
				]
			) . ';',
			'before'
		);
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_character_creator_assets', 20 );
