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

define( 'NEOWEAVER_VERSION', '0.7.1' );
define( 'NEOWEAVER_PLUGIN_FILE', __FILE__ );
define( 'NEOWEAVER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEOWEAVER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

final class NeoWeaver_Core {

	public static function init() {
		self::load_files();

		add_action( 'plugins_loaded', [ __CLASS__, 'register_page_templates' ] );
		add_action( 'plugins_loaded', [ __CLASS__, 'bootstrap_game_classes' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_public_assets' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_adventure_assets' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_checkout_assets' ] );
		add_action( 'wp_footer', [ __CLASS__, 'print_supabase_bootstrap' ], 5 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_agents_list_assets' ] );
	}

	private static function load_files() {
		$files = [
			'includes/supabase-config.php',
			'includes/supabase-helpers.php',
			'includes/game-data.php',
			'includes/ajax-public-profile.php',
			'includes/head-injection.php',
			'includes/lexicon-shortcodes.php',
			'includes/ajax.php',
			'includes/ajax-handlers.php',
			'includes/ajax-lobby-heartbeat.php',
			'includes/chat-realtime.php',
			'includes/char-panel.php',
			'includes/scenarios-loader.php',
			'includes/deck-core.php',
			'includes/skills-loader.php',
			'includes/inventory-system.php',
			'includes/quick-actions.php',
			'includes/ajax-deck-scenarios.php',
			'includes/ajax-buffer.php',
			'includes/quest-helpers.php',
			'includes/shortcodes-tags.php',
			'includes/ajax-save-player-notes.php',
			'includes/ajax/tw-update-vehicle-module.php',
			'includes/checkout-block.php',
			'includes/classes/class-neoweaver-agents-repository.php',
			'includes/classes/class-neoweaver-agents-list.php',
			'includes/classes/class-neoweaver-deployments-creator.php',
			'includes/classes/class-neoweaver-nodes-creator.php',
			'includes/classes/class-neoweaver-checkout-block.php',
			'public/shortcodes/shortcode-achivments.php',
			'public/shortcodes/shortcode-active-id.php',
			'public/shortcodes/shortcode-campaign-creator.php',
			'public/shortcodes/shortcode-character-creator.php',
			'public/shortcodes/shortcode-character-echo.php',
			'public/shortcodes/shortcode-compas.php',
			'public/shortcodes/shortcode-cyber-hud.php',
			'public/shortcodes/shortcode-deck-panel.php',
			'public/shortcodes/shortcode-fate-of-loom.php',
			'public/shortcodes/shortcode-foundry.php',
			'public/shortcodes/shortcode-join-terminal.php',
			'public/shortcodes/shortcode-kingdom-info.php',
			'public/shortcodes/shortcode-library.php',
			'public/shortcodes/shortcode-lobby.php',
			'public/shortcodes/shortcode-map.php',
			'public/shortcodes/shortcode-neoweave_my_world_archive.php',
			'public/shortcodes/shortcode-quests.php',
			'public/shortcodes/shortcode-quick-actions-cmd-center.php',
			'public/shortcodes/shortcode-services.php',
			'public/shortcodes/shortcode-signal-quality.php',
			'public/shortcodes/shortcode-time-wheel.php',
			'public/shortcodes/shortcode-tw-connect-character-campaign.php',
			'public/shortcodes/shortcode-tw-connect-campaign-world.php',
			'public/shortcodes/shortcode-tw-essence.php',
			'public/shortcodes/shortcode-tw-list-campaigns.php',
			'public/shortcodes/shortcode-tw-list-worlds.php',
			'public/shortcodes/shortcode-vehicle-panel.php',
			'public/shortcodes/shortcode-weaver-list.php',
			'public/shortcodes/shortcode-world-creator.php',
			'public/shortcodes/shortcode-world-news.php',
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

		if ( is_admin() ) {
			$admin_main = NEOWEAVER_PLUGIN_DIR . 'admin/class-nw-admin.php';
			if ( file_exists( $admin_main ) ) {
				require_once $admin_main;
			}
			foreach ( glob( NEOWEAVER_PLUGIN_DIR . 'admin/class-nw-*.php' ) ?: [] as $file ) {
				if ( basename( $file ) !== 'class-nw-admin.php' ) {
					require_once $file;
				}
			}
		}
	}

	public static function register_page_templates() {
		add_filter( 'theme_page_templates', [ __CLASS__, 'filter_page_templates' ] );
		add_filter( 'template_include',     [ __CLASS__, 'include_plugin_template' ] );
	}

	public static function filter_page_templates( array $templates ): array {
		$templates['templates/public-character-profile.php'] = __( 'Public Character Profile', 'neoweaver' );
		$templates['templates/adventure.php']                = __( 'NeoWeaver Adventure', 'neoweaver' );
		return $templates;
	}

	public static function include_plugin_template( string $template ): string {
		if ( ! is_page() ) return $template;
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

	public static function bootstrap_game_classes() {
		$repo                = new Neoweaver_Agents_Repository();
		$list                = new Neoweaver_Agents_List( $repo );
		$deployments_creator = new Neoweaver_Deployments_Creator();
		$nodes_creator       = new Neoweaver_Nodes_Creator();
		new Neoweaver_Public( $list, $deployments_creator, $nodes_creator );
	}

	public static function enqueue_public_assets() {
		wp_enqueue_style( 'neoweaver-public', NEOWEAVER_PLUGIN_URL . 'assets/css/neoweaver-public.css', [], NEOWEAVER_VERSION );
		wp_enqueue_script( 'lucide', 'https://unpkg.com/lucide@latest/dist/umd/lucide.min.js', [], null, true );
		wp_enqueue_script( 'neoweaver-public', NEOWEAVER_PLUGIN_URL . 'assets/js/neoweaver-public.js', [ 'jquery' ], NEOWEAVER_VERSION, true );
		wp_enqueue_style( 'neoweaver-buffer', NEOWEAVER_PLUGIN_URL . 'assets/css/buffer.css', [], NEOWEAVER_VERSION );
		wp_enqueue_script( 'neoweaver-buffer', NEOWEAVER_PLUGIN_URL . 'assets/js/buffer.js', [ 'jquery' ], NEOWEAVER_VERSION, true );
		wp_localize_script( 'neoweaver-buffer', 'nwApiData', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonces'  => [
				'use_card'  => wp_create_nonce( 'use_card_nonce' ),
				'deck_sync' => wp_create_nonce( 'cyber_deck_nonce' ),
				'foundry'   => wp_create_nonce( 'foundry_nonce' ),
			],
		] );
		wp_enqueue_script( 'nw-list-worlds', NEOWEAVER_PLUGIN_URL . 'public/assets/js/tw-list-worlds.js', [ 'jquery' ], NEOWEAVER_VERSION, true );
		wp_register_script( 'chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true );
	}

	public static function enqueue_adventure_assets() {
		if ( ! is_page_template( 'templates/adventure.php' ) ) return;
		$base_url = NEOWEAVER_PLUGIN_URL . 'assets/css/';
		$base_dir = NEOWEAVER_PLUGIN_DIR . 'assets/css/';
		wp_enqueue_script( 'chartjs' );
		wp_enqueue_style( 'neoweaver-tw-core',      $base_url . 'tw-core.css',                [],                      '1.0.0' );
		wp_enqueue_style( 'neoweaver-tw-chat',      $base_url . 'tw-chat.css',                [ 'neoweaver-tw-core' ], '1.0.0' );
		wp_enqueue_style( 'neoweaver-tw-deck',      $base_url . 'tw-deck.css',                [ 'neoweaver-tw-core' ], '1.0.0' );
		wp_enqueue_style( 'neoweaver-terminal',     $base_url . 'neoweaver-terminal.css',     [],                      NEOWEAVER_VERSION );
		wp_enqueue_style( 'neoweaver-interference', $base_url . 'neoweaver-interference.css', [],                      NEOWEAVER_VERSION );
		wp_enqueue_style( 'world-news',             $base_url . 'world-news.css',             [],                      NEOWEAVER_VERSION );
		$char_panel_css = $base_dir . 'tw-char-panel.css';
		wp_enqueue_style( 'neoweaver-tw-char-panel', $base_url . 'tw-char-panel.css', [ 'neoweaver-tw-core' ],
			file_exists( $char_panel_css ) ? (string) filemtime( $char_panel_css ) : NEOWEAVER_VERSION );
		wp_enqueue_script( 'nw-panel-tactical-left',  NEOWEAVER_PLUGIN_URL . 'assets/js/panel-tactical-left.js',    [],           '1.0.0',          true );
		wp_enqueue_script( 'neoweaver-interference',  NEOWEAVER_PLUGIN_URL . 'assets/js/neoweave-interference.js', [ 'jquery' ], NEOWEAVER_VERSION, true );
		wp_enqueue_script( 'world-news',              NEOWEAVER_PLUGIN_URL . 'assets/js/world-news.js',            [ 'jquery' ], NEOWEAVER_VERSION, true );
		wp_enqueue_script( 'nw-deck-panel',           NEOWEAVER_PLUGIN_URL . 'public/assets/js/deck-panel.js',     [ 'jquery' ], NEOWEAVER_VERSION, true );
		wp_enqueue_script( 'nw-vehicle-panel',        NEOWEAVER_PLUGIN_URL . 'public/assets/js/vehicle-panel.js',  [ 'jquery' ], NEOWEAVER_VERSION, true );
		wp_enqueue_script( 'nw-services',             NEOWEAVER_PLUGIN_URL . 'public/assets/js/services.js',       [ 'jquery' ], NEOWEAVER_VERSION, true );
		wp_enqueue_script( 'nw-time-wheel',           NEOWEAVER_PLUGIN_URL . 'public/assets/js/tw-time-wheel.js',  [ 'jquery' ], NEOWEAVER_VERSION, true );
		wp_enqueue_script( 'neoweaver-header-node',   NEOWEAVER_PLUGIN_URL . 'assets/js/neoweaver-header-node.js', [], '1.0.0', true );
		$uploads = wp_upload_dir();
		wp_localize_script( 'neoweaver-header-node', 'twNeoWeaverData', [
			'supabaseUrl' => function_exists( 'tw_supabase_url' )      ? tw_supabase_url()      : '',
			'supabaseKey' => function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '',
			'soundsUrl'   => trailingslashit( $uploads['baseurl'] ),
		] );
	}

	public static function enqueue_checkout_assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
		wp_enqueue_script( 'neoweaver-checkout-block', NEOWEAVER_PLUGIN_URL . 'assets/js/checkout-block.js', [ 'jquery' ], NEOWEAVER_VERSION, true );
		$characters    = function_exists( 'neoweaver_get_player_characters' ) ? neoweaver_get_player_characters( get_current_user_id() ) : [];
		$has_neoweaver = false;
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				if ( ! empty( $cart_item['data'] ) && method_exists( $cart_item['data'], 'get_attribute' ) ) {
					if ( $cart_item['data']->get_attribute( 'neoweaver_item_id' ) ) {
						$has_neoweaver = true;
						break;
					}
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
		// Shortcode [neoweaver_weaver_list] ustawia globalny flag gdy jest na stronie.
		// Używamy has_shortcode() na post content — ładujemy assets tylko tam gdzie faktycznie jest shortcode.
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) ) return;

		$shortcodes = [ 'neoweaver_weaver_list', 'tw_agents_list', 'neoweaver_agents_list' ];
		$has = false;
		foreach ( $shortcodes as $sc ) {
			if ( has_shortcode( $post->post_content, $sc ) ) {
				$has = true;
				break;
			}
		}
		if ( ! $has ) return;

		wp_enqueue_style(
			'neoweaver-agents-list',
			NEOWEAVER_PLUGIN_URL . 'assets/css/agents-list.css',
			[],
			NEOWEAVER_VERSION
		);

		wp_enqueue_script(
			'neoweaver-agents-list',
			NEOWEAVER_PLUGIN_URL . 'assets/js/agents-list.js',
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

	public static function print_supabase_bootstrap() {
		if ( ! is_user_logged_in() || ! is_page_template( 'templates/adventure.php' ) ) return;
		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) return;
		$url = tw_supabase_url();
		$key = tw_supabase_anon_key();
		if ( ! $url || ! $key ) return;
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
