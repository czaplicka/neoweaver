<?php
/**
 * Plugin Name: NeoWeaver
 * Description: Core logic for NeoWeaver game
 * Version:     0.7.4
 * Author:      Monika Czaplicka
 * Text Domain: neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Primary constants (NEOWEAVER_* namespace)
define( 'NEOWEAVER_VERSION', '0.7.4' );
define( 'NEOWEAVER_PLUGIN_FILE', __FILE__ );
define( 'NEOWEAVER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEOWEAVER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Legacy aliases (NW_* namespace) for backward compatibility
define( 'NW_VERSION', NEOWEAVER_VERSION );
define( 'NW_PLUGIN_FILE', NEOWEAVER_PLUGIN_FILE );
define( 'NW_PLUGIN_DIR', NEOWEAVER_PLUGIN_DIR );
define( 'NW_PLUGIN_PATH', NEOWEAVER_PLUGIN_DIR );
define( 'NW_PLUGIN_URL', NEOWEAVER_PLUGIN_URL );

final class NeoWeaver_Core {

	public static function init(): void {
		self::load_files();

		add_action( 'plugins_loaded', [ __CLASS__, 'load_admin_files' ] );
		add_action( 'plugins_loaded', [ __CLASS__, 'register_page_templates' ] );
		add_action( 'plugins_loaded', [ __CLASS__, 'bootstrap_game_classes' ] );

		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'register_admin_globals' ] );

		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_public_assets' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_onboarding_assets' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_adventure_assets' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_agents_list_assets' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_public_character_profile_assets' ] );
	}

	// ── File loading ──────────────────────────────────────────────────────

	private static function load_files(): void {
		$files = [
			// includes
			'includes/trait-transient-cache.php',
			'includes/adventure-data.php',
			'includes/api-endpoints-character-data.php',
			'includes/api-endpoints.php',
			'includes/char-panel.php',
			'includes/chat-realtime.php',
			'includes/checkout.php',
			'includes/deck-core.php',
			'includes/game-data.php',
			'includes/head-injection.php',
			'includes/inventory-system.php',
			'includes/lexicon-shortcodes.php',
			'includes/quest-helpers.php',
			'includes/quick-actions.php',
			'includes/scenarios-loader.php',
			'includes/shortcodes-tags.php',
			'includes/supabase-config.php',
			'includes/supabase-helpers.php',

			// includes/ajax
			'includes/ajax/buffer.php',
			'includes/ajax/chat-gm.php',
			'includes/ajax/deck-scenarios.php',
			'includes/ajax/ensure-world-state.php',
			'includes/ajax/get-char-state.php',
			'includes/ajax/get-scenarios.php',
			'includes/ajax/get-session-state.php',
			'includes/ajax/handlers.php',
			'includes/ajax/lobby-heartbeat.php',
			'includes/ajax/public-profile.php',
			'includes/ajax/save-player-notes.php',
			'includes/ajax/update-vehicle-module.php',

			// includes/classes
			'includes/classes/class-supabase.php',
			'includes/classes/class-loader.php',
			'includes/classes/class-agents-repository.php',
			'includes/classes/class-agents-list.php',
			'includes/classes/class-agents-creator.php',
			'includes/classes/class-deployments-creator.php',
			'includes/classes/class-nodes-creator.php',

			// includes/ai
			'includes/ai/class-neoweaver-gpt-engine.php',

			// public
			'public/class-public.php',

			// public/shortcodes
			'public/shortcodes/achievements.php',
			'public/shortcodes/connect-campaign-world.php',
			'public/shortcodes/connect-character-campaign.php',
			'public/shortcodes/essence.php',
			'public/shortcodes/hand.php',
			'public/shortcodes/foundry.php',
			'public/shortcodes/join-terminal.php',
			'public/shortcodes/kingdom-info.php',
			'public/shortcodes/library.php',
			'public/shortcodes/list-campaigns.php',
			'public/shortcodes/lobby.php',
			'public/shortcodes/map.php',
			'public/shortcodes/quests.php',
			'public/shortcodes/quick-actions-cmd-center.php',
			'public/shortcodes/services.php',
			'public/shortcodes/active-id.php',
			'public/shortcodes/campaign-creator.php',
			'public/shortcodes/character-creator.php',
			'public/shortcodes/character-echo.php',
			'public/shortcodes/compass.php',
			'public/shortcodes/cyber-hud.php',
			'public/shortcodes/deck-panel.php',
			'public/shortcodes/fate-of-loom.php',
			'public/shortcodes/onboarding.php',
			'public/shortcodes/signal-quality.php',
			'public/shortcodes/time-wheel.php',
			'public/shortcodes/vehicle-panel.php',
			'public/shortcodes/weaver-list.php',
			'public/shortcodes/world-archive.php',
			'public/shortcodes/world-creator.php',
			'public/shortcodes/world-news.php',
		];

		foreach ( $files as $file ) {
			$path = NEOWEAVER_PLUGIN_DIR . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Load and instantiate admin classes.
	 * Files are loaded here (inside plugins_loaded) so Supabase helpers
	 * are guaranteed to exist before any admin constructor runs.
	 * Classes must NOT have their own add_action('plugins_loaded') at the bottom.
	 */
	public static function load_admin_files(): void {
		if ( ! is_admin() ) {
			return;
		}

		$root = NW_PLUGIN_DIR . 'admin/admin.php';
		if ( file_exists( $root ) ) {
			require_once $root;
		}

		$bootstrap = NW_PLUGIN_DIR . 'admin/class-admin.php';
		if ( file_exists( $bootstrap ) ) {
			require_once $bootstrap;
		}
	}

	// ── Global asset registration ─────────────────────────────────────────

	public static function register_admin_globals(): void {
		wp_register_style(
			'nw-font-chakra-petch',
			'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
			[],
			null
		);

		wp_register_script(
			'nw-lucide',
			'https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js',
			[],
			'0.468.0',
			true
		);
	}

	// ── Page templates ────────────────────────────────────────────────────

	public static function register_page_templates(): void {
		add_filter( 'theme_page_templates', [ __CLASS__, 'filter_page_templates' ] );
		add_filter( 'template_include', [ __CLASS__, 'include_plugin_template' ] );
	}

	public static function filter_page_templates( array $templates ): array {
		$templates['templates/public-character-profile.php'] = __( 'Public Character Profile', 'neoweaver' );
		$templates['templates/adventure.php']                = __( 'NeoWeaver Adventure', 'neoweaver' );

		return $templates;
	}

	public static function include_plugin_template( string $template ): string {
		if ( ! is_page() ) {
			return $template;
		}

		$slug = get_page_template_slug( get_queried_object_id() );
		$map  = [
			'templates/public-character-profile.php' => NEOWEAVER_PLUGIN_DIR . 'templates/public-character-profile.php',
			'templates/adventure.php'                => NEOWEAVER_PLUGIN_DIR . 'templates/adventure.php',
		];

		if ( isset( $map[ $slug ] ) && file_exists( $map[ $slug ] ) ) {
			return $map[ $slug ];
		}

		return $template;
	}

	// ── Game class bootstrap ──────────────────────────────────────────────

	public static function bootstrap_game_classes(): void {
		$repo                = new Neoweaver_Agents_Repository();
		$list                = new Neoweaver_Agents_List( $repo );
		$deployments_creator = new Neoweaver_Deployments_Creator();
		$nodes_creator       = new Neoweaver_Nodes_Creator();

		new Neoweaver_Public( $list, $deployments_creator, $nodes_creator );
	}

	// ── Frontend assets ───────────────────────────────────────────────────

	public static function enqueue_public_assets(): void {
		wp_enqueue_script(
			'nw-lucide-public',
			'https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js',
			[],
			'0.468.0',
			true
		);

		wp_enqueue_style(
			'neoweaver-public',
			NEOWEAVER_PLUGIN_URL . 'assets/css/public/public.css',
			[],
			(string) filemtime( NEOWEAVER_PLUGIN_DIR . 'assets/css/public/public.css' )
		);

		wp_enqueue_script(
			'neoweaver-public',
			NEOWEAVER_PLUGIN_URL . 'assets/js/public/public.js',
			[ 'jquery', 'nw-lucide-public' ],
			(string) filemtime( NEOWEAVER_PLUGIN_DIR . 'assets/js/public/public.js' ),
			true
		);

		wp_enqueue_style(
			'neoweaver-buffer',
			NEOWEAVER_PLUGIN_URL . 'assets/css/public/buffer.css',
			[],
			(string) filemtime( NEOWEAVER_PLUGIN_DIR . 'assets/css/public/buffer.css' )
		);

		wp_enqueue_script(
			'neoweaver-buffer',
			NEOWEAVER_PLUGIN_URL . 'assets/js/public/buffer.js',
			[ 'jquery' ],
			(string) filemtime( NEOWEAVER_PLUGIN_DIR . 'assets/js/public/buffer.js' ),
			true
		);

		wp_localize_script(
			'neoweaver-buffer',
			'nwApiData',
			[
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonces'  => [
					'use_card'  => wp_create_nonce( 'use_card_nonce' ),
					'deck_sync' => wp_create_nonce( 'cyber_deck_nonce' ),
					'foundry'   => wp_create_nonce( 'foundry_nonce' ),
				],
			]
		);

		wp_enqueue_script(
			'nw-list-worlds',
			NEOWEAVER_PLUGIN_URL . 'assets/js/public/list-worlds.js',
			[ 'jquery' ],
			(string) filemtime( NEOWEAVER_PLUGIN_DIR . 'assets/js/public/list-worlds.js' ),
			true
		);

		wp_register_script(
			'chartjs',
			'https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js',
			[],
			'4.5.1',
			true
		);
	}

	public static function enqueue_onboarding_assets(): void {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		global $post;

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( ! has_shortcode( $post->post_content, 'tw_onboarding_slider' ) ) {
			return;
		}

		$script_rel = 'assets/js/public/onboarding.js';
		$script_abs = NEOWEAVER_PLUGIN_DIR . $script_rel;
		$style_rel  = 'assets/css/public/onboarding.css';
		$style_abs  = NEOWEAVER_PLUGIN_DIR . $style_rel;

		if ( file_exists( $style_abs ) ) {
			wp_enqueue_style(
				'neoweaver-onboarding',
				NEOWEAVER_PLUGIN_URL . $style_rel,
				[ 'neoweaver-public' ],
				(string) filemtime( $style_abs )
			);
		}

		if ( ! file_exists( $script_abs ) ) {
			return;
		}

		wp_enqueue_script(
			'neoweaver-onboarding',
			NEOWEAVER_PLUGIN_URL . $script_rel,
			[ 'jquery' ],
			(string) filemtime( $script_abs ),
			true
		);

		wp_localize_script(
			'neoweaver-onboarding',
			'twOnboarding',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'neoweaver_onboarding' ),
			]
		);
	}

	public static function enqueue_adventure_assets(): void {
		if ( ! is_page_template( 'templates/adventure.php' ) ) {
			return;
		}

		wp_enqueue_script( 'chartjs' );

		$css_url = NEOWEAVER_PLUGIN_URL . 'assets/css/public/';
		$css_dir = NEOWEAVER_PLUGIN_DIR . 'assets/css/public/';

		$styles = [
			'neoweaver-tw-core'      => [ 'core.css', [], '1.0.0' ],
			'neoweaver-tw-chat'      => [ 'chat.css', [ 'neoweaver-tw-core' ], '1.0.0' ],
			'neoweaver-tw-deck'      => [ 'deck.css', [ 'neoweaver-tw-core' ], '1.0.0' ],
			'neoweaver-terminal'     => [ 'terminal.css', [], NEOWEAVER_VERSION ],
			'neoweaver-interference' => [ 'interference.css', [], NEOWEAVER_VERSION ],
			'world-news'             => [ 'world-news.css', [], NEOWEAVER_VERSION ],
		];

		foreach ( $styles as $handle => [ $file, $deps, $ver ] ) {
			wp_enqueue_style( $handle, $css_url . $file, $deps, $ver );
		}

		$char_panel_file = $css_dir . 'char-panel.css';
		wp_enqueue_style(
			'neoweaver-char-panel',
			$css_url . 'char-panel.css',
			[ 'neoweaver-tw-core' ],
			file_exists( $char_panel_file ) ? (string) filemtime( $char_panel_file ) : NEOWEAVER_VERSION
		);

		$js_url  = NEOWEAVER_PLUGIN_URL;
		$scripts = [
			'nw-panel-tactical-left' => [ 'assets/js/public/panel-tactical-left.js', [], '1.0.0' ],
			'neoweaver-interference' => [ 'assets/js/public/interference.js', [ 'jquery' ], NEOWEAVER_VERSION ],
			'world-news'             => [ 'assets/js/public/world-news.js', [ 'jquery' ], NEOWEAVER_VERSION ],
			'nw-deck-panel'          => [ 'assets/js/public/deck-panel.js', [ 'jquery' ], NEOWEAVER_VERSION ],
			'nw-vehicle-panel'       => [ 'assets/js/public/vehicle-panel.js', [ 'jquery' ], NEOWEAVER_VERSION ],
			'nw-services'            => [ 'assets/js/public/services.js', [ 'jquery' ], NEOWEAVER_VERSION ],
			'neoweaver-header-node'  => [ 'assets/js/public/header-node.js', [], '1.0.0' ],
		];

		foreach ( $scripts as $handle => [ $file, $deps, $ver ] ) {
			wp_enqueue_script( $handle, $js_url . $file, $deps, $ver, true );
		}

		$adventure_script = NEOWEAVER_PLUGIN_DIR . 'assets/js/adventure.js';
		if ( file_exists( $adventure_script ) ) {
			wp_enqueue_script(
				'tw-adventure',
				NEOWEAVER_PLUGIN_URL . 'assets/js/adventure.js',
				[],
				(string) filemtime( $adventure_script ),
				true
			);
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

		if ( is_user_logged_in() && function_exists( 'tw_supabase_url' ) && function_exists( 'tw_supabase_anon_key' ) ) {
			$url = tw_supabase_url();
			$key = tw_supabase_anon_key();

			if ( $url && $key ) {
				$bootstrap_rel = 'assets/js/public/supabase-bootstrap.js';
				$bootstrap_abs = NEOWEAVER_PLUGIN_DIR . $bootstrap_rel;

				if ( file_exists( $bootstrap_abs ) ) {
					wp_enqueue_script(
						'neoweaver-supabase-bootstrap',
						NEOWEAVER_PLUGIN_URL . $bootstrap_rel,
						[],
						(string) filemtime( $bootstrap_abs ),
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

	public static function enqueue_agents_list_assets(): void {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		global $post;

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( ! has_shortcode( $post->post_content, 'tw_characters_list' ) ) {
			return;
		}

		wp_enqueue_style(
			'neoweaver-agents-list',
			NEOWEAVER_PLUGIN_URL . 'assets/css/public/agents-list.css',
			[],
			NEOWEAVER_VERSION
		);

		wp_enqueue_script(
			'neoweaver-agents-list',
			NEOWEAVER_PLUGIN_URL . 'assets/js/public/agents-list.js',
			[],
			NEOWEAVER_VERSION,
			true
		);

		wp_localize_script(
			'neoweaver-agents-list',
			'twCharData',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'tw_char_nonce' ),
			]
		);
	}

	public static function enqueue_public_character_profile_assets(): void {
		if ( ! is_page_template( 'templates/public-character-profile.php' ) ) {
			return;
		}

		wp_enqueue_style(
			'neo-public-character-profile',
			NEOWEAVER_PLUGIN_URL . 'assets/css/public/public-character-profile.css',
			[],
			NEOWEAVER_VERSION
		);

		wp_enqueue_script( 'chartjs' );

		wp_enqueue_script(
			'neo-public-character-profile',
			NEOWEAVER_PLUGIN_URL . 'assets/js/public/public-character-profile.js',
			[ 'chartjs' ],
			NEOWEAVER_VERSION,
			true
		);
	}
}

add_action( 'wp_enqueue_scripts', 'neoweaver_enqueue_chat_assets' );

function neoweaver_enqueue_chat_assets(): void {
	if ( ! is_page_template( 'templates/adventure.php' ) ) {
		return;
	}

	$chat_js = NEOWEAVER_PLUGIN_DIR . 'assets/js/public/neoweaver-ai-chat.js';

	wp_enqueue_script(
		'neoweaver-ai-chat',
		NEOWEAVER_PLUGIN_URL . 'assets/js/public/neoweaver-ai-chat.js',
		[ 'jquery' ],
		file_exists( $chat_js ) ? (string) filemtime( $chat_js ) : NEOWEAVER_VERSION,
		true
	);

	wp_localize_script(
    'neoweaver-ai-chat',
    'neoweaver_ajax',
    [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('neoweaver_chat'),
        'is_admin' => current_user_can('manage_options'),
    ]
);
}

NeoWeaver_Core::init();
