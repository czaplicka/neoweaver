<?php
/**
 * Plugin Name: NeoWeaver WP Core
 * Description: Core logic for NeoWeaver game (Agents, Nodes, Deployments).
 * Version:     0.7.0
 * Author:      Monika Czaplicka
 * Text Domain: neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Constants ───────────────────────────────────────────────────────────────────────────────────────
define( 'NEOWEAVER_VERSION',    '0.0.7' );
define( 'NEOWEAVER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEOWEAVER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ─── Helpers & core includes ──────────────────────────────────────────────
require_once NEOWEAVER_PLUGIN_DIR . 'includes/supabase-config.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/supabase-helpers.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/game-data.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/ajax-public-profile.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/head-injection.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/lexicon-shortcodes.php';

// ─── AJAX handlers ──────────────────────────────────────────────────────────────────────────────
require_once NEOWEAVER_PLUGIN_DIR . 'includes/ajax.php';

// ─── Game page scripts (wp_footer, page 2857 only) ────────────────────
require_once NEOWEAVER_PLUGIN_DIR . 'includes/chat-realtime.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/char-panel.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/scenarios-loader.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/deck-core.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/skills-loader.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/inventory-system.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/quick-actions.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/ajax-deck-scenarios.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/quest-helpers.php';

// ─── Class autoload ──────────────────────────────────────────────────────────────────────────────────────
require_once NEOWEAVER_PLUGIN_DIR . 'includes/classes/class-neoweaver-agents-repository.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/classes/class-neoweaver-agents-list.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/classes/class-neoweaver-agents-creator.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/classes/class-neoweaver-deployments-creator.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/classes/class-neoweaver-nodes-creator.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/class-neoweaver-public.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-tw-connect-character-campaign.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-tw-connect-campaign-world.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-tw-list-campaigns.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-tw-list-worlds.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-tw-essence.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-lobby.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-join-terminal.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-time-wheel.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-map.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-compas.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-weaver-list.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-fate-of-loom.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-kingdom-info.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-quests.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-quick-actions-cmd-center.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-character-echo.php';


// ─── REST API endpoints ──────────────────────────────────────────────────────────────────────────────
require_once NEOWEAVER_PLUGIN_DIR . 'includes/api-endpoints.php';

// ─── Enqueue shared public assets ──────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'neoweaver-public', NEOWEAVER_PLUGIN_URL . 'assets/css/neoweaver-public.css', [], NEOWEAVER_VERSION );
	wp_enqueue_style( 'neoweaver', NEOWEAVER_PLUGIN_URL . 'assets/css/neoweaver.css', [], NEOWEAVER_VERSION );
	wp_enqueue_script( 'neoweaver-public', NEOWEAVER_PLUGIN_URL . 'assets/js/neoweaver-public.js', [ 'jquery' ], NEOWEAVER_VERSION, true );

	if ( is_page_template( 'templates/adventure.php' ) || is_page( 2857 ) ) {
		wp_enqueue_script( 'nw-panel-tactical-left', plugin_dir_url( __FILE__ ) . 'assets/js/panel-tactical-left.js', [], '1.0.0', true );
	}
} );

// ─── Register plugin page templates ───────────────────────────────────────────
add_action( 'plugins_loaded', function () {
	add_filter( 'theme_page_templates', function ( $templates ) {
		$templates['templates/public-character-profile.php'] = __( 'Public Character Profile', 'neoweaver' );
		$templates['templates/adventure.php']               = __( 'NeoWeaver Adventure', 'neoweaver' );
		return $templates;
	} );

	add_filter( 'template_include', function ( $template ) {
		if ( ! is_page() ) return $template;
		$slug = get_page_template_slug( get_queried_object_id() );
		$map  = [
			'templates/public-character-profile.php' => NEOWEAVER_PLUGIN_DIR . 'templates/public-character-profile.php',
			'templates/adventure.php'                => NEOWEAVER_PLUGIN_DIR . 'templates/adventure.php',
		];
		if ( isset( $map[ $slug ] ) && file_exists( $map[ $slug ] ) ) return $map[ $slug ];
		return $template;
	} );
} );

// ─── Bootstrap game classes ──────────────────────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
	$repo                = new Neoweaver_Agents_Repository();
	$list                = new Neoweaver_Agents_List( $repo );
	$creator             = new Neoweaver_Agents_Creator();
	$deployments_creator = new Neoweaver_Deployments_Creator();
	$nodes_creator       = new Neoweaver_Nodes_Creator();
	new Neoweaver_Public( $list, $creator, $deployments_creator, $nodes_creator );
} );

// ─── Enqueue game page CSS ────────────────────────────────────────────────────────────────────────────────────
function neoweaver_enqueue_frontend_styles() {
	if ( is_page_template( 'templates/adventure.php' ) || is_page( 2857 ) ) {
		$base = plugin_dir_url( __FILE__ ) . 'assets/css/';
		wp_enqueue_style( 'neoweaver-tw-core', $base . 'tw-core.css', [], '1.0.0' );
		wp_enqueue_style( 'neoweaver-tw-chat', $base . 'tw-chat.css', [ 'neoweaver-tw-core' ], '1.0.0' );
		wp_enqueue_style( 'neoweaver-tw-deck', $base . 'tw-deck.css', [ 'neoweaver-tw-core' ], '1.0.0' );
		wp_enqueue_script(
			'neoweaver-header-node',
			plugin_dir_url( __FILE__ ) . 'assets/js/neoweaver-header-node.js',
			[],
			'1.0.0',
			true
		);
		wp_enqueue_style(
    'neoweaver-terminal',
    plugin_dir_url( __FILE__ ) . '../public/assets/css/neoweaver-terminal.css',
    [],
    NEOWEAVER_VERSION
);
		wp_localize_script( 'neoweaver-header-node', 'twNeoWeaverData', [
			'supabaseUrl' => tw_supabase_url(),
			'supabaseKey' => tw_supabase_anon_key(),
		] );
	}
}
add_action( 'wp_enqueue_scripts', 'neoweaver_enqueue_frontend_styles' );
