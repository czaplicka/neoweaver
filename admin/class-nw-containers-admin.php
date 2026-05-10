<?php
/**
 * NeoWeaver Admin Panel — Containers (cyber_containers)
 * Columns: id, name, description, total_slots, allowed_sizes,
 *          img_url, rarity, is_active, created_at, parent_id
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
        add_action( 'wp_ajax_nw_containers_toggle',  [ $this, 'ajax_toggle'  ] );
    }

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

    public function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, $this->page_slug ) ) return;

        $plugin_url = plugin_dir_url( dirname( __FILE__ ) );

        if ( ! wp_style_is( 'chakra-petch', 'enqueued' ) ) {
            wp_enqueue_style(
                'chakra-petch',
                'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
                [],
                null
            );
        }

        wp_enqueue_style(
            'nw-admin-core',
            $plugin_url . 'assets/css/nw-admin-core.css',
            [ 'chakra-petch' ],
            NEOWEAVER_VERSION
        );

        wp_enqueue_style(
            'nw-containers-style',
            $plugin_url . 'assets/css/containers-admin.css',
            [ 'chakra-petch', 'nw-admin-core' ],
            NEOWEAVER_VERSION
        );

        wp_enqueue_script(
            'nw-containers-script',
            $plugin_url . 'assets/js/containers-admin.js',
            [ 'jquery' ],
            NEOWEAVER_VERSION,
            true
        );

        wp_localize_script(
            'nw-containers-script',
            'NWContainers',
            [
                'ajaxurl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'neoweaver_containers' ),
                'supa_url' => $this->supabase_url,
                'supa_key' => $this->supabase_key,
            ]
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Supabase helper                                                    */
    /* ------------------------------------------------------------------ */

    private function supa( string $method, string $endpoint, array $body = [] ): array {
        $url  = $this->supabase_url . '/rest/v1/' . $endpoint;
        $args = [
            'method'  => $method,
            'headers' => [
                'apikey'        => $this->supabase_key,
                'Authorization' => 'Bearer ' . $this->supabase_key,
                'Content-Type'  => 'application/json',
                'Prefer'        => 'return=representation',
            ],
        ];
        if ( ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }
        $response = wp_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) {
            return [ 'error' => $response->get_error_message() ];
        }
        return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX — get all                                                     */
    /* ------------------------------------------------------------------ */

    public function ajax_get_all(): void {
        check_ajax_referer( 'neoweaver_containers', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $rows = $this->supa( 'GET', 'cyber_containers?select=*&order=name.asc' );
        isset( $rows['error'] ) ? wp_send_json_error( $rows['error'] ) : wp_send_json_success( $rows );
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX — save (insert or update)                                     */
    /* ------------------------------------------------------------------ */

    public function ajax_save(): void {
        check_ajax_referer( 'neoweaver_containers', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $data = [
            'name'         => sanitize_text_field(     $_POST['name']         ?? '' ),
            'description'  => sanitize_textarea_field( $_POST['description']  ?? '' ),
            'total_slots'  => absint(                  $_POST['total_slots']  ?? 0  ),
            'allowed_sizes'=> sanitize_text_field(     $_POST['allowed_sizes']?? '' ),
            'img_url'      => esc_url_raw(             $_POST['img_url']      ?? '' ),
            'rarity'       => sanitize_text_field(     $_POST['rarity']       ?? 'common' ),
            'is_active'    => filter_var( $_POST['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN ),
        ];

        if ( $id ) {
            $res = $this->supa( 'PATCH', 'cyber_containers?id=eq.' . $id, $data );
        } else {
            $res = $this->supa( 'POST',  'cyber_containers', $data );
        }

        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( $res );
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX — toggle active                                               */
    /* ------------------------------------------------------------------ */

    public function ajax_toggle(): void {
        check_ajax_referer( 'neoweaver_containers', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $id    = absint( $_POST['id'] ?? 0 );
        $state = filter_var( $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );
        if ( ! $id ) { wp_send_json_error( 'Missing ID' ); return; }
        $res = $this->supa( 'PATCH', 'cyber_containers?id=eq.' . urlencode( $id ), [ 'is_active' => $state ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( [ 'is_active' => $state ] );
    }

}

new NeoWeaver_Containers_Admin();
