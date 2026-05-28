<?php
/**
 * Plugin Name: NeoWeaver
 * Description: Core logic for NeoWeaver game
 * Version:     0.7.5
 * Author:      Monika Czaplicka
 * Text Domain: neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

defined( 'NEOWEAVER_VERSION' )     || define( 'NEOWEAVER_VERSION', '0.7.5' );
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

		// Public bootstrap tylko dla realnego frontu.
		add_action( 'init', [ __CLASS__, 'bootstrap_game_classes' ], 20 );
	}

	/* ---------------------------------------------------------------- */
	/* Request context helpers                                          */
	/* ---------------------------------------------------------------- */

	private static function is_ajax_request(): bool {
		return function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
	}

	private static function is_rest_request(): bool {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	private static function is_cron_request(): bool {
		return function_exists( 'wp_doing_cron' ) && wp_doing_cron();
	}

	private static function is_cli_request(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	private static function is_frontend_page_request(): bool {
		if ( self::is_ajax_request() || self::is_rest_request() || self::is_cron_request() || self::is_cli_request() ) {
			return false;
		}

		return ! is_admin();
	}

	/* ---------------------------------------------------------------- */
	/* File loading                                                     */
	/* ---------------------------------------------------------------- */

	private static function load_files(): void {

		$always = [
			// Supabase — musi być pierwsza, reszta zależy.
			'includes/supabase-config.php',
			'includes/supabase-helpers.php',
			'includes/supabase-auth.php',
			'includes/supabase-global.php',

			// AI engine.
			'includes/ai/class-neoweaver-claude-client.php',
			'includes/ai/class-neoweaver-intent-router.php',
			'includes/ai/class-neoweaver-context-builder.php',
			'includes/ai/class-neoweaver-claude-engine.php',

			// REST + API.
			'includes/rest-ai-chat.php',
			'includes/api-endpoints.php',
			'includes/api-endpoints-character-data.php',
			'includes/api-endpoints-character-write.php',

			// Shared classes / repositories.
			'includes/classes/class-supabase.php',
			'includes/classes/class-loader.php',
			'includes/classes/class-agents-creator.php',
			'includes/classes/class-agents-repository.php',
			'includes/classes/class-agents-list.php',
			'includes/classes/class-deployments-creator.php',
			'includes/classes/class-nodes-creator.php',
			'includes/classes/class-memory-parser.php',

			// Shared core.
			'includes/trait-transient-cache.php',
			'includes/adventure-data.php',
			'includes/assets.php',
			'includes/char-panel.php',
			'includes/checkout.php',
			'includes/deck-core.php',
			'includes/fetch-foundry.php',
			'includes/game-data.php',
			'includes/head-injection.php',
			'includes/inventory-system.php',
			'includes/lexicon-shortcodes.php',
			'includes/quest-helpers.php',
			'includes/quick-actions.php',
			'includes/scenarios-loader.php',
			'includes/shortcodes-tags.php',

			'public/class-public.php',

			// Frontend assets.
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
			'includes/assets/essences.php',
			'includes/assets/fate-of-loom.php',
			'includes/assets/foundry.php',
			'includes/assets/hand.php',
			'includes/assets/join-terminal.php',
			'includes/assets/kingdom-info.php',
			'includes/assets/library.php',
			'includes/assets/list-campaigns.php',
			'includes/assets/list-worlds.php',
			'includes/assets/lobby.php',
			'includes/assets/map.php',
			'includes/assets/onboarding.php',
			'includes/assets/public-character-profile.php',
			'includes/assets/public-runtime.php',
			'includes/assets/quests.php',
			'includes/assets/quick-actions-cmd-center.php',
			'includes/assets/services.php',
			'includes/assets/signal-quality.php',
			'includes/assets/time-wheel.php',
			'includes/assets/vehicle-panel.php',
			'includes/assets/weaver-list.php',
			'includes/assets/world-archive.php',
			'includes/assets/world-creator.php',
			'includes/assets/world-news.php',

			// Frontend shortcodes.
			'public/shortcodes/achievements.php',
			'public/shortcodes/adventure-terminal.php',
			'public/shortcodes/agents-list.php',
			'includes/shortcodes/ascension.php',
			'public/shortcodes/connect-campaign-world.php',
			'public/shortcodes/connect-character-campaign.php',
			'public/shortcodes/essences.php',
			'public/shortcodes/hand.php',
			'public/shortcodes/foundry.php',
			'public/shortcodes/join-terminal.php',
			'public/shortcodes/kingdom-info.php',
			'public/shortcodes/library.php',
			'public/shortcodes/list-campaigns.php',
			'public/shortcodes/list-worlds.php',
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

		$ajax_only = [
			'includes/ajax/ascension.php',
			'includes/ajax/buffer.php',
			'includes/ajax/deck-scenarios.php',
			'includes/ajax/ensure-world-state.php',
			'includes/ajax/get-char-state.php',
			'includes/ajax/get-scenarios.php',
			'includes/ajax/get-session-state.php',
			'includes/ajax/handlers.php',
			'includes/ajax/join-terminal.php',
			'includes/ajax/lobby-heartbeat.php',
			'includes/ajax/public-profile.php',
			'includes/ajax/save-deck.php',
			'includes/ajax/save-player-notes.php',
			'includes/ajax/update-vehicle-module.php',
			'includes/ajax/world-news.php',
		];

		foreach ( $always as $file ) {
			$path = NEOWEAVER_PLUGIN_DIR . $file;

			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		// Tylko requesty AJAX.
		if ( self::is_ajax_request() ) {
			foreach ( $ajax_only as $file ) {
				$path = NEOWEAVER_PLUGIN_DIR . $file;

				if ( file_exists( $path ) ) {
					require_once $path;
				}
			}
		}
	}

	/* ---------------------------------------------------------------- */
	/* Admin files                                                      */
	/* ---------------------------------------------------------------- */

	public static function load_admin_files(): void {
		if ( ! is_admin() ) {
			return;
		}

		foreach ( [ 'admin/admin-dashboard.php', 'admin/class-admin-bootstrap.php' ] as $file ) {
			$path = NW_PLUGIN_DIR . $file;

			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/* ---------------------------------------------------------------- */
	/* Page templates                                                   */
	/* ---------------------------------------------------------------- */

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

		return ( isset( $map[ $slug ] ) && file_exists( $map[ $slug ] ) )
			? $map[ $slug ]
			: $template;
	}

	/* ---------------------------------------------------------------- */
	/* Public bootstrap                                                 */
	/* ---------------------------------------------------------------- */

	public static function bootstrap_game_classes(): void {
		static $bootstrapped = false;

		if ( $bootstrapped ) {
			return;
		}

		if ( ! self::is_frontend_page_request() ) {
			return;
		}

		// class-public.php is already loaded unconditionally via $always above.
		// If the class still doesn't exist here, there's a parse error in that file.
		if ( ! class_exists( 'Neoweaver_Public', false ) ) {
			return;
		}

		$repo = new Neoweaver_Agents_Repository();
		$list = new Neoweaver_Agents_List( $repo );

		new Neoweaver_Public(
			$list,
			new Neoweaver_Deployments_Creator(),
			new Neoweaver_Nodes_Creator()
		);

		$bootstrapped = true;
	}
}

NeoWeaver_Core::init();
