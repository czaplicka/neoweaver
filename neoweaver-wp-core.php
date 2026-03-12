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

// ─── Constants ─────────────────────────────────────────────────────────────────
define( 'NEOWEAVER_VERSION',    '0.7.0' );
define( 'NEOWEAVER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEOWEAVER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ─── Helpers MUST be loaded before any class or action that needs them ─────────
// BUG-FIX 3: was placed after plugins_loaded add_action, meaning tw_supabase_url()
// didn't exist yet when classes were instantiated.
// ─── Helpers & core includes ──────────────────────────────────────────────────
// 1) Konfiguracja Supabase (URL/keys)
require_once NEOWEAVER_PLUGIN_DIR . 'includes/supabase-config.php';

// 2) Helpery REST (tw_supabase_get / tw_supabase_request / tw_get_data)
require_once NEOWEAVER_PLUGIN_DIR . 'includes/supabase-helpers.php';

// 3) Dane gry (get_user_game_data_from_supabase, tw_get_current_character_id)
require_once NEOWEAVER_PLUGIN_DIR . 'includes/game-data.php';

// 4) AJAX (publiczny profil postaci)
require_once NEOWEAVER_PLUGIN_DIR . 'includes/ajax-public-profile.php';

// 5) Globalny injection do <head> (twAdventureData + init Supabase JS)
require_once NEOWEAVER_PLUGIN_DIR . 'includes/head-injection.php';

// 6) Lexicon + shortcode + body_class
require_once NEOWEAVER_PLUGIN_DIR . 'includes/lexicon-shortcodes.php';

// ─── Class autoload ────────────────────────────────────────────────────────────
require_once NEOWEAVER_PLUGIN_DIR . 'includes/ajax.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/chat-realtime.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/char-panel.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/classes/class-neoweaver-agents-repository.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/classes/class-neoweaver-agents-list.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/classes/class-neoweaver-agents-creator.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/classes/class-neoweaver-deployments-creator.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/classes/class-neoweaver-nodes-creator.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/class-neoweaver-public.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-tw-connect-character-campaign.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-tw-connect-campaign-world.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-tw-list-campaign.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-tw-list-worlds.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-tw-esence.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-lobby.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-join-terminal.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-time-wheel.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-map.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-compas.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-weaver-list.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-fate-of-loom.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-kingdom-info.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-quests.php';

// ─── REST API endpoints ────────────────────────────────────────────────────────
// BUG-FIX 4: api-endpoints.php was never required, so no REST routes existed.
require_once NEOWEAVER_PLUGIN_DIR . 'includes/api-endpoints.php';

// ─── Enqueue shared public assets ──────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style(
		'neoweaver-public',
		NEOWEAVER_PLUGIN_URL . 'assets/css/neoweaver-public.css',
		[],
		NEOWEAVER_VERSION
	);
	wp_enqueue_style(
		'neoweaver',
		NEOWEAVER_PLUGIN_URL . 'assets/css/neoweaver.css',
		[],
		NEOWEAVER_VERSION
	);
	wp_enqueue_script(
		'neoweaver-public',
		NEOWEAVER_PLUGIN_URL . 'assets/js/neoweaver-public.js',
		[ 'jquery' ],
		NEOWEAVER_VERSION,
		true
	);
	    if ( is_page( 2857 ) ) {
        wp_enqueue_script(
            'nw-panel-tactical-left',
            plugin_dir_url( __FILE__ ) . 'assets/js/panel-tactical-left.js',
            [], '1.0.0', true
        );
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
	} );
} );

// ─── Bootstrap game classes ────────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
	$repo                = new Neoweaver_Agents_Repository();
	$list                = new Neoweaver_Agents_List( $repo );
	$creator             = new Neoweaver_Agents_Creator();
	$deployments_creator = new Neoweaver_Deployments_Creator();
	$nodes_creator       = new Neoweaver_Nodes_Creator();

	// Neoweaver_Public registers all shortcodes on construction.
	new Neoweaver_Public( $list, $creator, $deployments_creator, $nodes_creator );
} );

function neoweaver_enqueue_frontend_styles() {
  // Ładuj tylko na stronie gry – dopasuj do siebie:
  if ( is_page_template( 'adventure.php' ) || is_page( 'terminal' ) ) {

    $base = plugin_dir_url( __FILE__ ) . 'assets/css/';

    wp_enqueue_style(
      'neoweaver-tw-core',
      $base . 'tw-core.css',
      [],
      '1.0.0'
    );

    wp_enqueue_style(
      'neoweaver-tw-chat',
      $base . 'tw-chat.css',
      [ 'neoweaver-tw-core' ],
      '1.0.0'
    );

    wp_enqueue_style(
      'neoweaver-tw-deck',
      $base . 'tw-deck.css',
      [ 'neoweaver-tw-core' ],
      '1.0.0'
    );
  }
}
add_action( 'wp_enqueue_scripts', 'neoweaver_enqueue_frontend_styles' );
