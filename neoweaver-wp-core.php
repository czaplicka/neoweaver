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

defined( 'NEOWEAVER_VERSION' )     || define( 'NEOWEAVER_VERSION', '0.7.4' );
defined( 'NEOWEAVER_PLUGIN_FILE' ) || define( 'NEOWEAVER_PLUGIN_FILE', __FILE__ );
defined( 'NEOWEAVER_PLUGIN_DIR' )  || define( 'NEOWEAVER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
defined( 'NEOWEAVER_PLUGIN_URL' )  || define( 'NEOWEAVER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

defined( 'NW_VERSION' )     || define( 'NW_VERSION', NEOWEAVER_VERSION );
defined( 'NW_PLUGIN_FILE' ) || define( 'NW_PLUGIN_FILE', NEOWEAVER_PLUGIN_FILE );
defined( 'NW_PLUGIN_DIR' )  || define( 'NW_PLUGIN_DIR', NEOWEAVER_PLUGIN_DIR );
defined( 'NW_PLUGIN_PATH' ) || define( 'NW_PLUGIN_PATH', NEOWEAVER_PLUGIN_DIR );
defined( 'NW_PLUGIN_URL' )  || define( 'NW_PLUGIN_URL', NEOWEAVER_PLUGIN_URL );

final class NeoWeaver_Core {

	public static function init(): void {
		self::load_files();

		add_action( 'plugins_loaded', [ __CLASS__, 'load_admin_files' ] );
		add_action( 'plugins_loaded', [ __CLASS__, 'register_page_templates' ] );
		add_action( 'plugins_loaded', [ __CLASS__, 'bootstrap_game_classes' ] );
	}

	// ── File loading ─────────────────────────────────────────────

	private static function load_files(): void {
		$files = [
			// includes
			'includes/supabase-config.php',
			'includes/supabase-helpers.php',
			'includes/supabase-auth.php',
			'includes/supabase-global.php', 
			
			'includes/assets.php',
			'includes/assets/vendors.php',
			'includes/assets/adventure.php',
			'includes/assets/agents-list.php',
			'includes/assets/buffer.php',
			'includes/assets/achievements.php',
			'includes/assets/active-id.php',
			'includes/assets/campaign-creator.php',
			'includes/assets/character-creator.php',
			'includes/assets/character-echo.php',
			'includes/assets/compass.php',
			'includes/assets/connect-campaign-world.php',
			'includes/assets/connect-character-campaign.php',
			'includes/assets/cyber-hud.php',
			'includes/assets/deck-panel.php',
			//'includes/assets/',
			//'includes/assets/',
			//'includes/assets/',
			//'includes/assets/',
			//'includes/assets/',
			//'includes/assets/',
			//'includes/assets/',
			//'includes/assets/',
			//'includes/assets/',
			//'includes/assets/',
			//'includes/assets/',
			//'includes/assets/',
			//'includes/assets/',
			'includes/assets/public-character-profile.php',
			'includes/assets/public-runtime.php',
			
			'includes/trait-transient-cache.php',
			'includes/adventure-data.php',
			'includes/api-endpoints-character-data.php',
			'includes/api-endpoints.php',
			'includes/char-panel.php',
			// 'includes/chat-realtime.php',
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
			// 'includes/classes/class-agents-creator.php',
			'includes/classes/class-deployments-creator.php',
			'includes/classes/class-nodes-creator.php',
			'includes/classes/class-memory-parser.php',
			'includes/classes/class-chat-gpt.php',
			'includes/classes/class-chat-handler.php',

			// includes/ai
			'includes/ai/class-neoweaver-gpt-engine.php',
			'includes/ai/class-neoweaver-intent-router.php',
			'includes/ai/class-neoweaver-context-builder.php',

			// public
			'public/class-public.php',

			// public/shortcodes
			'public/shortcodes/achievements.php',
			'public/shortcodes/adventure-terminal.php',
			'public/shortcodes/agents-list.php',
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
			'public/shortcodes/public-character-profile.php',
			'public/shortcodes/signal-quality.php',
			'public/shortcodes/time-wheel.php',
			'public/shortcodes/vehicle-panel.php',
			'public/shortcodes/vitalis.php',
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

	// ── Page templates ──────────────────────────────────────────

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

	// ── Game class bootstrap ───────────────────────────────────────

	public static function bootstrap_game_classes(): void {
		$repo                = new Neoweaver_Agents_Repository();
		$list                = new Neoweaver_Agents_List( $repo );
		$deployments_creator = new Neoweaver_Deployments_Creator();
		$nodes_creator       = new Neoweaver_Nodes_Creator();

		new Neoweaver_Public( $list, $deployments_creator, $nodes_creator );
	}
}
NeoWeaver_Core::init();
if ( class_exists( 'NW_Chat_Handler' ) ) {
	new NW_Chat_Handler();
}
