<?php
/**
 * Plugin Name: NeoWeaver WP Core
 * Description: Core logic for NeoWeaver game (Agents, Nodes, Deployments).
 * Version:     0.7.1
 * Author:      Monika Czaplicka
 * Text Domain: neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Primary constants (NEOWEAVER_* namespace)
define( 'NEOWEAVER_VERSION',     '0.7.1' );
define( 'NEOWEAVER_PLUGIN_FILE', __FILE__ );
define( 'NEOWEAVER_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'NEOWEAVER_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// Legacy aliases (NW_* namespace) for backward compatibility
define( 'NW_VERSION',     NEOWEAVER_VERSION );
define( 'NW_PLUGIN_FILE', NEOWEAVER_PLUGIN_FILE );
define( 'NW_PLUGIN_DIR',  NEOWEAVER_PLUGIN_DIR );
define( 'NW_PLUGIN_PATH', NEOWEAVER_PLUGIN_DIR );
define( 'NW_PLUGIN_URL',  NEOWEAVER_PLUGIN_URL );

final class NeoWeaver_Core {

	public static function init(): void {
		self::load_files();

		add_action( 'plugins_loaded',      [ __CLASS__, 'load_admin_files'       ] );
		add_action( 'plugins_loaded',      [ __CLASS__, 'register_page_templates' ] );
		add_action( 'plugins_loaded',      [ __CLASS__, 'bootstrap_game_classes'  ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'register_admin_globals' ] );
		add_action( 'wp_enqueue_scripts',  [ __CLASS__, 'enqueue_public_assets'   ] );
		add_action( 'wp_enqueue_scripts',  [ __CLASS__, 'enqueue_adventure_assets' ] );
		add_action( 'wp_enqueue_scripts',  [ __CLASS__, 'enqueue_checkout_assets'  ] );
		add_action( 'wp_enqueue_scripts',  [ __CLASS__, 'enqueue_agents_list_assets' ] );
		add_action( 'wp_footer',           [ __CLASS__, 'print_supabase_bootstrap' ], 5 );
	}

	// ── File loading ──────────────────────────────────────────────────────

	private static function load_files(): void {
		require_once NEOWEAVER_PLUGIN_DIR . 'includes/trait-transient-cache.php';

		$files = [
			'includes/supabase-config.php',
			'includes/supabase-helpers.php',
			'includes/game-data.php',
			'includes/ajax/public-profile.php',
			'includes/head-injection.php',
			'includes/lexicon-shortcodes.php',
			'includes/ajax.php',
			'includes/ajax/handlers.php',
			'includes/ajax/lobby-heartbeat.php',
			'includes/chat-realtime.php',
			'includes/char-panel.php',
			'includes/scenarios-loader.php',
			'includes/deck-core.php',
			'includes/skills-loader.php',
			'includes/inventory-system.php',
			'includes/quick-actions.php',
			'includes/ajax/deck-scenarios.php',
			'includes/ajax-buffer.php',
			'includes/quest-helpers.php',
			'includes/shortcodes-tags.php',
			'includes/ajax-save-player-notes.php',
			'includes/ajax/tw-update-vehicle-module.php',
			'includes/checkout-block.php',
			'includes/classes/class-agents-repository.php',
			'includes/classes/class-agents-list.php',
			'includes/classes/class-deployments-creator.php',
			'includes/classes/class-nodes-creator.php',
			'includes/classes/class-checkout-block.php',
			'public/shortcodes/achivments.php',
			'public/shortcodes/active-id.php',
			'public/shortcodes/campaign-creator.php',
			'public/shortcodes/character-creator.php',
			'public/shortcodes/character-echo.php',
			'public/shortcodes/compas.php',
			'public/shortcodes/cyber-hud.php',
			'public/shortcodes/deck-panel.php',
			'public/shortcodes/fate-of-loom.php',
			'public/shortcodes/foundry.php',
			'public/shortcodes/join-terminal.php',
			'public/shortcodes/kingdom-info.php',
			'public/shortcodes/library.php',
			'public/shortcodes/lobby.php',
			'public/shortcodes/map.php',
			'public/shortcodes/world-archive.php',
			'public/shortcodes/quests.php',
			'public/shortcodes/quick-actions-cmd-center.php',
			'public/shortcodes/services.php',
			'public/shortcodes/signal-quality.php',
			'public/shortcodes/time-wheel.php',
			'public/shortcodes/connect-character-campaign.php',
			'public/shortcodes/connect-campaign-world.php',
			'public/shortcodes/essence.php',
			'public/shortcodes/list-campaigns.php',
			'public/shortcodes/list-worlds.php',
			'public/shortcodes/vehicle-panel.php',
			'public/shortcodes/weaver-list.php',
			'public/shortcodes/world-creator.php',
			'public/shortcodes/world-news.php',
			'public/class-neoweaver-public.php',
			'includes/api-endpoints.php',
			'includes/api-endpoints-character-data.php',
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

		// Main admin class first (menu registration depends on it)
		$admin_main = NEOWEAVER_PLUGIN_DIR . 'admin/admin.php';
		if ( file_exists( $admin_main ) ) {
			require_once $admin_main;
		}

		// All other admin classes (class-nw-*.php pattern)
		foreach ( glob( NEOWEAVER_PLUGIN_DIR . 'admin/class-nw-*.php' ) ?: [] as $file ) {
			require_once $file;
		}

		// Instantiate — single place, no add_action inside class files
		if ( class_exists( 'NW_Admin' ) )            new NW_Admin();
		if ( class_exists( 'NW_Abilities_Admin' ) )  new NW_Abilities_Admin();
		if ( class_exists( 'NW_Skills_Admin' ) )     new NW_Skills_Admin();
		// Add further admin classes here as you create them
	}

	// ── Global asset registration ─────────────────────────────────────────

	/**
	 * Register shared admin assets so individual admin classes can declare
	 * them as dependencies without worrying about double-loading.
	 * wp_register_* does NOT output anything — assets are only loaded
	 * when a class calls wp_enqueue_* with these handles as dependencies.
	 */
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
		add_filter( 'theme_page_templates', [ __CLASS__, 'filter_page_templates'  ] );
		add_filter( 'template_include',     [ __CLASS__, 'include_plugin_template' ] );
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
		// Lucide — single version, jsdelivr (same CDN as admin)
		wp_enqueue_script(
			'nw-lucide-public',
			'https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js',
			[],
			'0.468.0',
			true
		);

		wp_enqueue_style( 'neoweaver-public',
			NEOWEAVER_PLUGIN_URL . 'assets/css/neoweaver-public.css', [], NEOWEAVER_VERSION );

		wp_enqueue_script( 'neoweaver-public',
			NEOWEAVER_PLUGIN_URL . 'assets/js/neoweaver-public.js',
			[ 'jquery', 'nw-lucide-public' ], NEOWEAVER_VERSION, true );

		wp_enqueue_style( 'neoweaver-buffer',
			NEOWEAVER_PLUGIN_URL . 'assets/css/buffer.css', [], NEOWEAVER_VERSION );

		wp_enqueue_script( 'neoweaver-buffer',
			NEOWEAVER_PLUGIN_URL . 'assets/js/buffer.js',
			[ 'jquery' ], NEOWEAVER_VERSION, true );

		wp_localize_script( 'neoweaver-buffer', 'nwApiData', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonces'  => [
				'use_card'  => wp_create_nonce( 'use_card_nonce' ),
				'deck_sync' => wp_create_nonce( 'cyber_deck_nonce' ),
				'foundry'   => wp_create_nonce( 'foundry_nonce' ),
			],
		] );

		wp_enqueue_script( 'nw-list-worlds',
			NEOWEAVER_PLUGIN_URL . 'public/assets/js/tw-list-worlds.js',
			[ 'jquery' ], NEOWEAVER_VERSION, true );

		// Chart.js — register only, enqueue on demand (e.g. adventure page)
		wp_register_script( 'chartjs',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
			[], '4.4.0', true );
	}

	public static function enqueue_adventure_assets(): void {
		if ( ! is_page_template( 'templates/adventure.php' ) ) {
			return;
		}

		wp_enqueue_script( 'chartjs' );

		$css_url = NEOWEAVER_PLUGIN_URL . 'assets/css/';
		$css_dir = NEOWEAVER_PLUGIN_DIR . 'assets/css/';

		$styles = [
			'neoweaver-tw-core'      => [ 'tw-core.css',                [],                        '1.0.0' ],
			'neoweaver-tw-chat'      => [ 'tw-chat.css',                [ 'neoweaver-tw-core' ],   '1.0.0' ],
			'neoweaver-tw-deck'      => [ 'tw-deck.css',                [ 'neoweaver-tw-core' ],   '1.0.0' ],
			'neoweaver-terminal'     => [ 'neoweaver-terminal.css',     [],                        NEOWEAVER_VERSION ],
			'neoweaver-interference' => [ 'neoweaver-interference.css', [],                        NEOWEAVER_VERSION ],
			'world-news'             => [ 'world-news.css',             [],                        NEOWEAVER_VERSION ],
		];

		foreach ( $styles as $handle => [ $file, $deps, $ver ] ) {
			wp_enqueue_style( $handle, $css_url . $file, $deps, $ver );
		}

		// Char panel — use filemtime for reliable cache-busting in development
		$char_panel_file = $css_dir . 'tw-char-panel.css';
		wp_enqueue_style(
			'neoweaver-tw-char-panel',
			$css_url . 'tw-char-panel.css',
			[ 'neoweaver-tw-core' ],
			file_exists( $char_panel_file ) ? (string) filemtime( $char_panel_file ) : NEOWEAVER_VERSION
		);

		$js_url = NEOWEAVER_PLUGIN_URL;
		$scripts = [
			'nw-panel-tactical-left' => [ 'assets/js/panel-tactical-left.js',    [],           '1.0.0'          ],
			'neoweaver-interference' => [ 'assets/js/neoweave-interference.js',  [ 'jquery' ], NEOWEAVER_VERSION ],
			'world-news'             => [ 'assets/js/world-news.js',             [ 'jquery' ], NEOWEAVER_VERSION ],
			'nw-deck-panel'          => [ 'public/assets/js/deck-panel.js',      [ 'jquery' ], NEOWEAVER_VERSION ],
			'nw-vehicle-panel'       => [ 'public/assets/js/vehicle-panel.js',   [ 'jquery' ], NEOWEAVER_VERSION ],
			'nw-services'            => [ 'public/assets/js/services.js',        [ 'jquery' ], NEOWEAVER_VERSION ],
			'nw-time-wheel'          => [ 'public/assets/js/tw-time-wheel.js',   [ 'jquery' ], NEOWEAVER_VERSION ],
			'neoweaver-header-node'  => [ 'assets/js/neoweaver-header-node.js',  [],           '1.0.0'          ],
		];

		foreach ( $scripts as $handle => [ $file, $deps, $ver ] ) {
			wp_enqueue_script( $handle, $js_url . $file, $deps, $ver, true );
		}

		$uploads = wp_upload_dir();
		wp_localize_script( 'neoweaver-header-node', 'twNeoWeaverData', [
			'supabaseUrl' => function_exists( 'tw_supabase_url' )      ? tw_supabase_url()      : '',
			'supabaseKey' => function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '',
			'soundsUrl'   => trailingslashit( $uploads['baseurl'] ),
		] );
	}

	public static function enqueue_checkout_assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wp_enqueue_script( 'neoweaver-checkout-block',
			NEOWEAVER_PLUGIN_URL . 'assets/js/checkout-block.js',
			[ 'jquery' ], NEOWEAVER_VERSION, true );

		$characters    = function_exists( 'neoweaver_get_player_characters' )
			? neoweaver_get_player_characters( get_current_user_id() )
			: [];

		$has_neoweaver = false;
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				if ( ! empty( $cart_item['data'] )
					&& method_exists( $cart_item['data'], 'get_attribute' )
					&& $cart_item['data']->get_attribute( 'neoweaver_item_id' )
				) {
					$has_neoweaver = true;
					break;
				}
			}
		}

		wp_localize_script( 'neoweaver-checkout-block', 'neoweaverCheckout', [
			'characters'   => $characters ?: [],
			'hasNeoweaver' => $has_neoweaver ? '1' : '0',
			'createUrl'    => home_url( '/new-agent/' ),
		] );
	}

	public static function enqueue_agents_list_assets(): void {
		if ( is_admin() ) {
			return;
		}

		wp_enqueue_style( 'neoweaver-agents-list',
			NEOWEAVER_PLUGIN_URL . 'assets/css/agents-list.css', [], NEOWEAVER_VERSION );

		wp_enqueue_script( 'neoweaver-agents-list',
			NEOWEAVER_PLUGIN_URL . 'assets/js/agents-list.js',
			[], NEOWEAVER_VERSION, true );

		wp_localize_script( 'neoweaver-agents-list', 'twCharData', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'tw_char_nonce' ),
		] );
	}

	// ── Supabase frontend bootstrap ───────────────────────────────────────

	public static function print_supabase_bootstrap(): void {
		if ( ! is_user_logged_in() || ! is_page_template( 'templates/adventure.php' ) ) {
			return;
		}
		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
			return;
		}
		$url = tw_supabase_url();
		$key = tw_supabase_anon_key();
		if ( ! $url || ! $key ) {
			return;
		}
		?>
		<script>
		if ( ! window.twSupabase && window.supabase ) {
			window.twSupabase = window.supabase.createClient(
				<?php echo wp_json_encode( $url ); ?>,
				<?php echo wp_json_encode( $key ); ?>
			);
		}
		</script>
		<?php
	}
}

NeoWeaver_Core::init();
