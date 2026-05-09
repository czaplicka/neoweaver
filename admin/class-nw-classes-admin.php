<?php
/**
 * NeoWeaver Admin Panel — Classes (cyber_classes)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Classes_Admin {

    private string $table = 'cyber_classes';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_nw_classes_get_all', [ $this, 'ajax_get_all' ] );
        add_action( 'wp_ajax_nw_classes_save',    [ $this, 'ajax_save'    ] );
        add_action( 'wp_ajax_nw_classes_delete',  [ $this, 'ajax_delete'  ] );
    }

    public function register_menu(): void {
        add_submenu_page(
            'neoweaver', 'Classes', 'Classes', 'manage_options',
            'nw-classes', [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, 'nw-classes' ) ) return;
        wp_enqueue_style( 'nw-classes-css', plugin_dir_url( __FILE__ ) . '../assets/css/nw-admin-tables.css', [], '1.0' );
        wp_enqueue_script( 'nw-classes-js', plugin_dir_url( __FILE__ ) . '../assets/js/classes-admin.js', [ 'jquery' ], '1.0', true );
        wp_localize_script( 'nw-classes-js', 'NWClasses', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'nw_classes_nonce' ),
        ] );
    }

    public function render_page(): void { ?>
        <div class="wrap nw-admin-wrap">
        <h1 class="nw-admin-heading">🏰 Classes</h1>
        <div id="nw-notice" class="nw-notice" style="display:none"></div>
        <div class="nw-toolbar">
            <button id="nw-add-btn" class="nw-action-btn">+ Add Class</button>
            <button id="nw-refresh-btn" class="nw-action-btn nw-action-btn--secondary">↺ Refresh</button>
            <input type="text" id="nw-search" placeholder="Search classes…" />
        </div>
        <table class="nw-table" id="nw-classes-table">
            <thead><tr>
                <th>Name</th><th>Description</th><th>Tags</th><th>Actions</th>
            </tr></thead>
            <tbody id="nw-classes-tbody"></tbody>
        </table>
        <div id="nw-modal-overlay" class="nw-modal-overlay" style="display:none">
            <div class="nw-modal">
                <div class="nw-modal-header">
                    <h2 id="nw-modal-title">Class</h2>
                    <button id="nw-modal-close" class="nw-modal-close">✕</button>
                </div>
                <form id="nw-class-form">
                <input type="hidden" name="class_id" id="nw-field-id" />
                <div class="nw-form-grid">
                    <label>Name *<input type="text" name="name" id="nw-field-name" required /></label>
                    <label class="nw-span-2">Description<textarea name="description" id="nw-field-desc" rows="3"></textarea></label>
                    <label>Tags (comma-separated)<input type="text" name="tags" id="nw-field-tags" /></label>
                </div>
                </form>
                <div class="nw-modal-footer">
                    <button id="nw-save-btn" class="nw-action-btn">Save</button>
                    <button id="nw-cancel-btn" class="nw-action-btn nw-action-btn--secondary">Cancel</button>
                    <button id="nw-delete-btn" class="nw-action-btn nw-action-btn--danger" style="display:none">Delete</button>
                </div>
            </div>
        </div>
        </div><?php
    }

    private function supa( string $method, string $path, array $body = [], array $extra = [] ): array {
        return nw_supabase_request( $method, $path, $body, $extra );
    }

    public function ajax_get_all(): void {
        check_ajax_referer( 'nw_classes_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

        $rows = $this->supa( 'GET', $this->table . '?order=name.asc&select=*' );

        if ( isset( $rows['error'] ) ) {
            wp_send_json_error( $rows['error'] );
            return;
        }

        wp_send_json_success( $rows['data'] ?? [] );
    }

    public function ajax_save(): void {
        check_ajax_referer( 'nw_classes_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

        $name = sanitize_text_field( $_POST['name'] ?? '' );
        if ( ! $name ) { wp_send_json_error( 'Name is required' ); return; }

        $tags = array_values( array_filter( array_map(
            'trim', explode( ',', sanitize_text_field( $_POST['tags'] ?? '' ) )
        ) ) );

        $payload = [
            'name'        => $name,
            'description' => sanitize_textarea_field( $_POST['description'] ?? '' ),
            'tags'        => $tags,
        ];

        $id = sanitize_text_field( $_POST['class_id'] ?? '' );

        if ( $id ) {
            $res = $this->supa( 'PATCH', $this->table . '?id=eq.' . rawurlencode( $id ), $payload,
                [ 'Prefer' => 'return=representation' ] );
        } else {
            $res = $this->supa( 'POST', $this->table, $payload,
                [ 'Prefer' => 'return=representation' ] );
        }

        if ( isset( $res['error'] ) ) { wp_send_json_error( $res['error'] ); return; }

        $code = $res['code'] ?? 0;
        $data = $res['data'] ?? [];
        $item = is_array( $data ) && isset( $data[0] ) ? $data[0] : $data;

        $code >= 200 && $code < 300
            ? wp_send_json_success( $item )
            : wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code );
    }

    public function ajax_delete(): void {
        check_ajax_referer( 'nw_classes_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

        $id = sanitize_text_field( $_POST['class_id'] ?? '' );
        if ( ! $id ) { wp_send_json_error( 'Missing ID' ); return; }

        $res = $this->supa( 'DELETE', $this->table . '?id=eq.' . rawurlencode( $id ), [], [ 'Prefer' => '' ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( 'deleted' );
    }
}

new NeoWeaver_Classes_Admin();
