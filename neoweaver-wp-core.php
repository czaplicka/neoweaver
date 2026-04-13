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

// ─── Constants ────────────────────────────────────────────────────────────────
define( 'NEOWEAVER_VERSION',    '0.0.7' );
define( 'NEOWEAVER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEOWEAVER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ─── Helpers & core includes ──────────────────────────────────────────────────
require_once NEOWEAVER_PLUGIN_DIR . 'includes/supabase-config.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/supabase-helpers.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/game-data.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/ajax-public-profile.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/head-injection.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/lexicon-shortcodes.php';

// ─── AJAX handlers ────────────────────────────────────────────────────────────
require_once NEOWEAVER_PLUGIN_DIR . 'includes/ajax.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/ajax-handlers.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/ajax-lobby-heartbeat.php';

// ─── Game page scripts (wp_footer, adventure template only) ──────────────────
require_once NEOWEAVER_PLUGIN_DIR . 'includes/chat-realtime.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/char-panel.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/scenarios-loader.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/deck-core.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/skills-loader.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/inventory-system.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/quick-actions.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/ajax-deck-scenarios.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/ajax-buffer.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/quest-helpers.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/shortcodes-tags.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/ajax-save-player-notes.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/ajax/tw-update-vehicle-module.php';

// ─── Other ───────────────────────────────────────────────────────────
require_once NEOWEAVER_PLUGIN_DIR . 'includes/checkout-block.php';

// ─── Class autoload ───────────────────────────────────────────────────────────
require_once NEOWEAVER_PLUGIN_DIR . 'includes/classes/class-neoweaver-agents-repository.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/classes/class-neoweaver-agents-list.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/classes/class-neoweaver-agents-creator.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/classes/class-neoweaver-deployments-creator.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/classes/class-neoweaver-nodes-creator.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/classes/class-neoweaver-checkout-block.php';

// ─── Wizard shortcode functions (must load before class-neoweaver-public.php)
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-achivments.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-active-id.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-campaign-creator.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-character-creator.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-character-echo.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-compas.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-cyber-hud.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-deck-panel.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-fate-of-loom.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-foundry.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-join-terminal.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-kingdom-info.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-library.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-lobby.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-map.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-neoweave_my_world_archive.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-quests.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-quick-actions-cmd-center.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-services.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-signal-quality.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-time-wheel.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-tw-connect-character-campaign.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-tw-connect-campaign-world.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-tw-essence.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-tw-list-campaigns.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-tw-list-worlds.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-vehicle-panel.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-weaver-list.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-world-creator.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/shortcodes/shortcode-world-news.php';
require_once NEOWEAVER_PLUGIN_DIR . 'public/class-neoweaver-public.php';

// ─── REST API endpoints ───────────────────────────────────────────────────────
require_once NEOWEAVER_PLUGIN_DIR . 'includes/api-endpoints.php';
require_once NEOWEAVER_PLUGIN_DIR . 'includes/api-endpoints-character-data.php';

// ─── Enqueue shared public assets ─────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'neoweaver-public', NEOWEAVER_PLUGIN_URL . 'assets/css/neoweaver-public.css', [], NEOWEAVER_VERSION );
	wp_enqueue_script( 'neoweaver-public', NEOWEAVER_PLUGIN_URL . 'assets/js/neoweaver-public.js', [ 'jquery' ], NEOWEAVER_VERSION, true );

	// Buffer/Foundry — registered once, no duplicate enqueue or localize.
	wp_enqueue_style(  'neoweaver-buffer', NEOWEAVER_PLUGIN_URL . 'assets/css/buffer.css', [], NEOWEAVER_VERSION );
	wp_enqueue_script( 'neoweaver-buffer', NEOWEAVER_PLUGIN_URL . 'assets/js/buffer.js', [ 'jquery' ], NEOWEAVER_VERSION, true );
	wp_localize_script( 'neoweaver-buffer', 'nwApiData', [
		'ajaxurl' => admin_url( 'admin-ajax.php' ),
		'nonces'  => [
			'use_card'  => wp_create_nonce( 'use_card_nonce' ),
			'deck_sync' => wp_create_nonce( 'cyber_deck_nonce' ),
			'foundry'   => wp_create_nonce( 'foundry_nonce' ),
		],
	] );

	wp_enqueue_script( 'chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true );

	if ( is_page_template( 'templates/adventure.php' ) ) {
		wp_enqueue_script( 'nw-panel-tactical-left',  NEOWEAVER_PLUGIN_URL . 'assets/js/panel-tactical-left.js',     [],           '1.0.0',           true );
		wp_enqueue_script( 'neoweaver-interference',  NEOWEAVER_PLUGIN_URL . 'assets/js/neoweave-interference.js',   [ 'jquery' ], NEOWEAVER_VERSION,  true );
		wp_enqueue_style(  'neoweaver-interference',  NEOWEAVER_PLUGIN_URL . 'assets/css/neoweaver-interference.css', [],           NEOWEAVER_VERSION );
		wp_enqueue_style(  'world-news',              NEOWEAVER_PLUGIN_URL . 'assets/css/world-news.css',             [],           NEOWEAVER_VERSION );
		wp_enqueue_script( 'world-news',              NEOWEAVER_PLUGIN_URL . 'assets/js/world-news.js',               [ 'jquery' ], NEOWEAVER_VERSION,  true );

		wp_enqueue_script( 'nw-deck-panel',    NEOWEAVER_PLUGIN_URL . 'public/assets/js/deck-panel.js',     [ 'jquery' ], NEOWEAVER_VERSION, true );
		wp_enqueue_script( 'nw-vehicle-panel', NEOWEAVER_PLUGIN_URL . 'public/assets/js/vehicle-panel.js',  [ 'jquery' ], NEOWEAVER_VERSION, true );
		wp_enqueue_script( 'nw-services',      NEOWEAVER_PLUGIN_URL . 'public/assets/js/services.js',       [ 'jquery' ], NEOWEAVER_VERSION, true );
		wp_enqueue_script( 'nw-time-wheel',    NEOWEAVER_PLUGIN_URL . 'public/assets/js/tw-time-wheel.js',  [ 'jquery' ], NEOWEAVER_VERSION, true );
	}
} );
wp_enqueue_script( 'nw-list-worlds',   NEOWEAVER_PLUGIN_URL . 'public/assets/js/tw-list-worlds.js', [ 'jquery' ], NEOWEAVER_VERSION, true );
wp_enqueue_style( 'nw-character-css',   NEOWEAVER_PLUGIN_URL . 'assets/js/tw-character-creator.css', [], NEOWEAVER_VERSION, true );
add_action( 'wp_enqueue_scripts', function() {
    if ( ! is_checkout() ) return;

    wp_enqueue_script(
        'neoweaver-checkout-block',
        NEOWEAVER_PLUGIN_URL . 'assets/js/checkout-block.js',
        [ 'wp-plugins', 'wp-element', 'wp-components', 'wc-blocks-checkout' ],
        NEOWEAVER_VERSION,
        true
    );

    $characters    = neoweaver_get_player_characters( get_current_user_id() );
    $has_neoweaver = false;

    if ( WC()->cart ) {
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( $cart_item['data']->get_attribute( 'neoweaver_item_id' ) ) {
                $has_neoweaver = true;
                break;
            }
        }
    }

    wp_localize_script( 'neoweaver-checkout-block', 'neoweaverCheckout', [
        'characters'   => $characters ?: [],
        'hasNeoweaver' => $has_neoweaver,
        'createUrl'    => '/new-agent/',
    ] );
}, 20 );

// ─── Register plugin page templates ──────────────────────────────────────────
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

// ─── Bootstrap game classes ───────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
	$repo                = new Neoweaver_Agents_Repository();
	$list                = new Neoweaver_Agents_List( $repo );
	$creator             = new Neoweaver_Agents_Creator();
	$deployments_creator = new Neoweaver_Deployments_Creator();
	$nodes_creator       = new Neoweaver_Nodes_Creator();
	new Neoweaver_Public( $list, $creator, $deployments_creator, $nodes_creator );
} );
add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() ) return;
	$url = defined( 'NEOWEAVER_SUPA_URL' ) ? NEOWEAVER_SUPA_URL : '';
	$key = defined( 'NEOWEAVER_SUPA_KEY' ) ? NEOWEAVER_SUPA_KEY : '';
	if ( ! $url || ! $key ) return;
	?>
	<script>
	if (!window.twSupabase && window.supabase) {
	    window.twSupabase = window.supabase.createClient('<?= esc_js( $url ) ?>', '<?= esc_js( $key ) ?>');
	}
	</script>
	<?php
}, 5 );

// ─── Enqueue game page CSS (adventure template only) ─────────────────────────
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_page_template( 'templates/adventure.php' ) ) return;

	$base = NEOWEAVER_PLUGIN_URL . 'assets/css/';
	$dir  = NEOWEAVER_PLUGIN_DIR . 'assets/css/';

	wp_enqueue_style( 'neoweaver-tw-core',  $base . 'tw-core.css',            [], '1.0.0' );
	wp_enqueue_style( 'neoweaver-tw-chat',  $base . 'tw-chat.css',            [ 'neoweaver-tw-core' ], '1.0.0' );
	wp_enqueue_style( 'neoweaver-tw-deck',  $base . 'tw-deck.css',            [ 'neoweaver-tw-core' ], '1.0.0' );
	wp_enqueue_style( 'neoweaver-terminal', $base . 'neoweaver-terminal.css', [], NEOWEAVER_VERSION );

	$char_panel_css = $dir . 'tw-char-panel.css';
	wp_enqueue_style(
		'neoweaver-tw-char-panel',
		$base . 'tw-char-panel.css',
		[ 'neoweaver-tw-core' ],
		file_exists( $char_panel_css ) ? (string) filemtime( $char_panel_css ) : '1.0.0'
	);

	wp_enqueue_script(
		'neoweaver-header-node',
		NEOWEAVER_PLUGIN_URL . 'assets/js/neoweaver-header-node.js',
		[],
		'1.0.0',
		true
	);

	$uploads = wp_upload_dir();
	wp_localize_script( 'neoweaver-header-node', 'twNeoWeaverData', [
		'supabaseUrl' => tw_supabase_url(),
		'supabaseKey' => tw_supabase_anon_key(),
		'soundsUrl'   => trailingslashit( $uploads['baseurl'] ),
	] );
} );
