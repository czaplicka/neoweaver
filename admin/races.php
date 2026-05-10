<?php
/**
 * NeoWeaver Admin — Races (cyber_races)
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Delegate to the shared, full-featured races admin that already exists.
// This file is preserved so legacy menu registrations and AJAX hooks remain.

if ( ! class_exists( 'NeoWeaver_Races_Admin' ) ) {

    class NeoWeaver_Races_Admin {

        use NW_Transient_Cache;

        private string $table        = 'cyber_races';
        private string $nonce_action = 'nw_races_nonce';

        public function __construct() {
            add_action( 'admin_menu',            [ $this, 'register_menu' ] );
            add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
            add_action( 'wp_ajax_nw_races_get_all',    [ $this, 'ajax_get_all' ] );
            add_action( 'wp_ajax_nw_races_get_one',    [ $this, 'ajax_get_one' ] );
            add_action( 'wp_ajax_nw_races_save',       [ $this, 'ajax_save' ] );
            add_action( 'wp_ajax_nw_races_toggle',     [ $this, 'ajax_toggle' ] );
            add_action( 'wp_ajax_nw_races_delete',     [ $this, 'ajax_delete' ] );
        }

        public function register_menu(): void {
            add_submenu_page(
                'neoweaver', 'Races', 'Races', 'manage_options',
                'nw-races', [ $this, 'render_page' ]
            );
        }

        public function enqueue( string $hook ): void {
            if ( ! str_contains( $hook, 'nw-races' ) ) return;
            wp_enqueue_style( 'nw-admin-shared', NW_PLUGIN_URL . 'admin/css/nw-admin-shared.css', [], NW_VERSION );
            wp_enqueue_script( 'nw-races', NW_PLUGIN_URL . 'admin/js/nw-races.js', [ 'jquery' ], NW_VERSION, true );
            wp_localize_script( 'nw-races', 'NW_RC', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( $this->nonce_action ),
            ] );
        }

        public function render_page(): void {
            echo '<div class="wrap nw-admin-wrap"><h1>Races</h1><div id="nw-races-root"><p>Loading…</p></div></div>';
        }

        /* AJAX -------------------------------------------------------- */

        public function ajax_get_all(): void {
            check_ajax_referer( $this->nonce_action, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

            $rows = $this->cached_get_all( $this->table, 'name' );
            isset( $rows['error'] ) ? wp_send_json_error( $rows['error'] ) : wp_send_json_success( $rows );
        }

        public function ajax_get_one(): void {
            check_ajax_referer( $this->nonce_action, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

            $id = sanitize_text_field( $_POST['id'] ?? '' );
            if ( ! $id ) { wp_send_json_error( 'Missing ID' ); return; }

            $res = NW_Supabase::get_one( $this->table, $id );
            isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( $res['data'][0] ?? null );
        }

        public function ajax_save(): void {
            check_ajax_referer( $this->nonce_action, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

            $id   = sanitize_text_field( $_POST['id']   ?? '' );
            $name = sanitize_text_field( $_POST['name'] ?? '' );
            if ( ! $name ) { wp_send_json_error( 'Name is required' ); return; }

            $payload = [
                'name'        => $name,
                'description' => sanitize_textarea_field( $_POST['description'] ?? '' ),
                'bonuses'     => sanitize_textarea_field( $_POST['bonuses']     ?? '' ),
                'lore'        => sanitize_textarea_field( $_POST['lore']        ?? '' ),
                'is_active'   => ! empty( $_POST['is_active'] ),
            ];

            $res  = $id ? NW_Supabase::patch( $this->table, $id, $payload ) : NW_Supabase::insert( $this->table, $payload );
            $item = $res['data'][0] ?? null;

            if ( isset( $res['error'] ) ) { wp_send_json_error( $res['error'] ); return; }

            $code = $res['code'] ?? 0;
            if ( $code >= 400 ) { wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code ); return; }

            $this->bust_cache( $this->table );
            wp_send_json_success( $item );
        }

        public function ajax_toggle(): void {
            check_ajax_referer( $this->nonce_action, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

            $id    = sanitize_text_field( $_POST['id']    ?? '' );
            $state = filter_var( $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );
            if ( ! $id ) { wp_send_json_error( 'Missing ID' ); return; }

            $res = NW_Supabase::patch( $this->table, $id, [ 'is_active' => $state ] );
            if ( isset( $res['error'] ) ) { wp_send_json_error( $res['error'] ); return; }

            $this->bust_cache( $this->table );
            wp_send_json_success( [ 'is_active' => $state ] );
        }

        public function ajax_delete(): void {
            check_ajax_referer( $this->nonce_action, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

            $id = sanitize_text_field( $_POST['id'] ?? '' );
            if ( ! $id ) { wp_send_json_error( 'Missing ID' ); return; }

            $res = NW_Supabase::delete( $this->table, $id );
            if ( isset( $res['error'] ) ) { wp_send_json_error( $res['error'] ); return; }

            $this->bust_cache( $this->table );
            wp_send_json_success( 'deleted' );
        }
    }

}
