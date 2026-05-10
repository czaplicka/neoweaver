<?php
/**
 * NeoWeaver Admin Panel — Deck Cards (cyber_deck)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Deck_Admin {

    private string $supabase_url;
    private string $supabase_key;
    private string $page_slug   = 'neoweaver-deck';
    private string $parent_slug = 'neoweaver';

    public function __construct() {
        $this->supabase_url = rtrim( tw_supabase_url(), '/' );
        $this->supabase_key = tw_supabase_anon_key();

        add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_nw_deck_get_all', [ $this, 'ajax_get_all' ] );
        add_action( 'wp_ajax_nw_deck_save',    [ $this, 'ajax_save'    ] );
        add_action( 'wp_ajax_nw_deck_toggle',  [ $this, 'ajax_toggle'  ] );
        add_action( 'wp_ajax_nw_deck_delete',  [ $this, 'ajax_delete'  ] );
    }

    /* ---------------------------------------------------------------- */
    /*  MENU                                                              */
    /* ---------------------------------------------------------------- */

    public function register_menu(): void {
        add_submenu_page(
            $this->parent_slug,
            'NeoWeaver \u2014 Deck Cards',
            '🃏 Deck Cards',
            'manage_options',
            $this->page_slug,
            [ $this, 'render_page' ]
        );
    }

    /* ---------------------------------------------------------------- */
    /*  ASSETS                                                            */
    /* ---------------------------------------------------------------- */

    public function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, $this->page_slug ) ) return;

        wp_enqueue_style(
            'chakra-petch',
            'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
            [],
            null
        );

        wp_enqueue_style(
            'nw-deck-admin-style',
            plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/deck-admin.css',
            [ 'chakra-petch' ],
            NEOWEAVER_VERSION
        );

        wp_enqueue_script(
            'nw-deck-admin-script',
            plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/deck-admin.js',
            [ 'jquery' ],
            NEOWEAVER_VERSION,
            true
        );

        wp_localize_script( 'nw-deck-admin-script', 'NW_Deck', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'neoweaver_deck' ),
        ] );
    }
