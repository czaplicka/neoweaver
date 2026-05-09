<?php
/**
 * NeoWeaver Admin Panel — Containers (cyber_containers)
 * Columns: id, name, description, max_slots, tag_ids, created_at
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Containers_Admin {

    private string $supabase_url;
    private string $supabase_key;
    private string $page_slug = 'neoweaver-containers';
    private string $menu_slug = 'neoweaver';

    public function __construct() {
        $this->supabase_url = rtrim( tw_supabase_url(), '/' );
        $this->supabase_key = tw_supabase_anon_key();

        add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_nw_containers_get_all', [ $this, 'ajax_get_all' ] );
        add_action( 'wp_ajax_nw_containers_save',    [ $this, 'ajax_save'    ] );
        add_action( 'wp_ajax_nw_containers_delete',  [ $this, 'ajax_delete'  ] );
    }

    /* ================================================================ */
    /*  MENU                                                              */
    /* ================================================================ */

    public function register_menu(): void {
        add_submenu_page(
            $this->menu_slug,
            'NeoWeaver — Containers',
            '📦 Containers',
            'manage_options',
            $this->page_slug,
            [ $this, 'render_page' ]
        );
    }