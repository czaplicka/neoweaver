<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_register_connect_character_campaign_assets' ) ) {
	function tw_register_connect_character_campaign_assets(): void {
		$css_handle = 'neoweaver-connect-character-campaign';
		$js_handle  = 'neoweaver-connect-character-campaign';

		$css_rel = 'assets/css/public/deployment2.css';
		$js_rel  = 'assets/js/public/deployment2.js';

		$css_path = NEOWEAVER_PLUGIN_DIR . $css_rel;
		$js_path  = NEOWEAVER_PLUGIN_DIR . $js_rel;

		wp_register_style(
			$css_handle,
			NEOWEAVER_PLUGIN_URL . $css_rel,
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEOWEAVER_VERSION
		);

		wp_register_script(
			$js_handle,
			NEOWEAVER_PLUGIN_URL . $js_rel,
			[],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEOWEAVER_VERSION,
			true
		);
	}
}

if ( ! function_exists( 'tw_enqueue_connect_character_campaign_assets' ) ) {
	function tw_enqueue_connect_character_campaign_assets(): void {
		static $done = false;

		wp_enqueue_style( 'neoweaver-connect-character-campaign' );
		wp_enqueue_script( 'neoweaver-connect-character-campaign' );

		if ( $done ) {
			return;
		}

		$done = true;

		$user_id      = get_current_user_id();
		$supabase_url = function_exists( 'tw_supabase_url' ) ? trailingslashit( tw_supabase_url() ) : '';
		$anon_key     = function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '';

		// Load campaigns and characters server-side using service key (bypasses RLS).
		$campaigns  = [];
		$characters = [];

		if ( $user_id && function_exists( 'tw_supabase_get_admin' ) ) {
			$raw_camps = tw_supabase_get_admin(
				'cyber_campaigns_ready_for_agent',
				[
					'select' => 'id,name,world_id',
					'order'  => 'created_at.desc',
				]
			);
			if ( is_array( $raw_camps ) ) {
				foreach ( $raw_camps as $c ) {
					$campaigns[] = [
						'id'       => (string) ( $c['id'] ?? '' ),
						'name'     => (string) ( $c['name'] ?? '' ),
						'world_id' => isset( $c['world_id'] ) ? (string) $c['world_id'] : null,
					];
				}
			}

			$raw_chars = tw_supabase_get_admin(
				'cyber_characters',
				[
					'wp_user_id' => 'eq.' . $user_id,
					'select'     => 'id,name,race_id,class_id,cyber_races(name),cyber_classes(name)',
				]
			);
			if ( is_array( $raw_chars ) ) {
				foreach ( $raw_chars as $ch ) {
					$characters[] = [
						'id'         => (string) ( $ch['id'] ?? '' ),
						'name'       => (string) ( $ch['name'] ?? '' ),
						'race_id'    => $ch['race_id'] ?? null,
						'class_id'   => $ch['class_id'] ?? null,
						'race_name'  => is_array( $ch['cyber_races'] ?? null ) ? ( $ch['cyber_races']['name'] ?? null ) : null,
						'class_name' => is_array( $ch['cyber_classes'] ?? null ) ? ( $ch['cyber_classes']['name'] ?? null ) : null,
					];
				}
			}
		}

		$config = [
			'userId'         => $user_id,
			'supabaseUrl'    => $supabase_url,
			'supabaseKey'    => $anon_key,
			'restNonce'      => wp_create_nonce( 'wp_rest' ),
			'nonce'          => wp_create_nonce( 'tw_deployment_nonce' ),
			'deploymentsUrl' => home_url( '/deployments/' ),
			'initialData'    => [
				'campaigns'  => $campaigns,
				'characters' => $characters,
			],
		];

		wp_add_inline_script(
			'neoweaver-connect-character-campaign',
			'window.twDeploymentConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
}

if ( ! function_exists( 'tw_maybe_enqueue_connect_character_campaign_assets' ) ) {
	function tw_maybe_enqueue_connect_character_campaign_assets(): void {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		$post = get_post();

		if ( ! $post || empty( $post->post_content ) ) {
			return;
		}

		if ( has_shortcode( $post->post_content, 'tw_connect_character_campaign' ) ) {
			tw_enqueue_connect_character_campaign_assets();
		}
	}
}

add_action( 'wp_enqueue_scripts', 'tw_register_connect_character_campaign_assets', 5 );
add_action( 'wp_enqueue_scripts', 'tw_maybe_enqueue_connect_character_campaign_assets', 20 );
