<?php
/**
 * NeoWeaver Admin Panel — Style Dictionary (cyber_style_dictionary)
 *
 * Columns: id, tag_name, category, interpretation_en, is_active, created_at
 * Categories: behavior | visuals | vibe | general
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Style_Dictionary_Admin {

    private string $supabase_url;
    private string $supabase_key;
    private string $page_slug   = 'neoweaver-style-dictionary';
    private string $parent_slug = 'neoweaver';

    private array $categories = [ 'behavior', 'visuals', 'vibe', 'general' ];

    public function __construct() {
        $this->supabase_url = rtrim( tw_supabase_url(), '/' );
        $this->supabase_key = tw_supabase_anon_key();

        add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_nw_sd_get_all', [ $this, 'ajax_get_all' ] );
        add_action( 'wp_ajax_nw_sd_save',    [ $this, 'ajax_save'    ] );
        add_action( 'wp_ajax_nw_sd_toggle',  [ $this, 'ajax_toggle'  ] );
        add_action( 'wp_ajax_nw_sd_delete',  [ $this, 'ajax_delete'  ] );
    }

    public function register_menu(): void {
        add_submenu_page(
            $this->parent_slug,
            'NeoWeaver — Style Dictionary',
            '🔤 Style Dictionary',
            'manage_options',
            $this->page_slug,
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, $this->page_slug ) ) return;
        $base = plugin_dir_url( dirname( __FILE__ ) ) . 'admin/';
        $ver  = '1.0.0';
        wp_enqueue_style( 'chakra-petch', 'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap', [], null );
        wp_enqueue_style( 'nw-style-dictionary-admin', $base . 'css/style-dictionary-admin.css', [ 'chakra-petch' ], $ver );
        wp_enqueue_script( 'nw-style-dictionary-admin', $base . 'js/style-dictionary-admin.js', [ 'jquery' ], $ver, true );
        wp_localize_script( 'nw-style-dictionary-admin', 'NW_SD', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'neoweaver_sd' ),
        ] );
    }

    public function render_page(): void { ?>
        <div class="wrap nw-panel" id="nw-sd-panel">
            <div class="nw-panel-header">
                <h1 class="nw-panel-title"><span class="nw-accent">Neo</span>Weaver <span class="nw-panel-subtitle">/ Style Dictionary</span></h1>
                <div class="nw-header-actions">
                    <button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">↻ Refresh</button>
                    <button class="nw-btn nw-btn-primary" id="nw-add-btn">+ New Tag</button>
                </div>
            </div>
            <div id="nw-notice" class="nw-notice" style="display:none;"></div>
            <div class="nw-stats-bar">
                <span class="nw-stat-pill">Total: <strong id="nw-total">—</strong></span>
                <span class="nw-stat-pill nw-pill-active">Active: <strong id="nw-active">—</strong></span>
                <span class="nw-stat-pill nw-pill-inactive">Inactive: <strong id="nw-inactive">—</strong></span>
                <?php foreach ( $this->categories as $cat ) : ?>
                    <span class="nw-stat-pill nw-pill-cat-<?php echo esc_attr( $cat ); ?>">
                        <?php echo esc_html( ucfirst( $cat ) ); ?>: <strong class="nw-cat-count" data-cat="<?php echo esc_attr( $cat ); ?>">—</strong>
                    </span>
                <?php endforeach; ?>
            </div>
            <div class="nw-filter-bar">
                <select id="nw-filter-category" class="nw-select nw-filter-select">
                    <option value="">All Categories</option>
                    <?php foreach ( $this->categories as $c ) : ?>
                        <option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( ucfirst( $c ) ); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="nw-filter-active" class="nw-select nw-filter-select">
                    <option value="">Active &amp; Inactive</option>
                    <option value="1">Active only</option>
                    <option value="0">Inactive only</option>
                </select>
                <input type="text" id="nw-filter-search" class="nw-filter-input" placeholder="Search tag name or interpretation…">
            </div>
            <div class="nw-table-wrap">
                <table class="nw-table">
                    <thead><tr>
                        <th>Tag Name</th><th>Category</th><th>Interpretation (EN)</th><th>Active</th><th>Actions</th>
                    </tr></thead>
                    <tbody id="nw-sd-tbody">
                        <tr class="nw-loading-row"><td colspan="5"><div class="nw-spinner"></div> Loading tags…</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
                <div class="nw-modal">
                    <div class="nw-modal-header">
                        <h2 id="nw-modal-title">Edit Style Tag</h2>
                        <button class="nw-modal-close" id="nw-modal-close">✕</button>
                    </div>
                    <div class="nw-modal-body">
                        <form id="nw-sd-form">
                            <input type="hidden" id="nw-field-id" name="id">
                            <div class="nw-section-label">Identity</div>
                            <div class="nw-form-grid">
                                <div class="nw-field">
                                    <label>Tag Name <span class="nw-req">*</span></label>
                                    <input type="text" id="nw-field-tag_name" name="tag_name" required placeholder="e.g. neon-shadow">
                                </div>
                                <div class="nw-field">
                                    <label>Category <span class="nw-req">*</span></label>
                                    <select id="nw-field-category" name="category" class="nw-select">
                                        <?php foreach ( $this->categories as $c ) : ?>
                                            <option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( ucfirst( $c ) ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Interpretation (EN) <span class="nw-req">*</span></label>
                                    <textarea id="nw-field-interpretation_en" name="interpretation_en" rows="4" required placeholder="Describe what this style tag means in the game world…"></textarea>
                                </div>
                            </div>
                            <div class="nw-section-label">Visibility</div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-center">
                                    <label>Active</label>
                                    <label class="nw-toggle"><input type="checkbox" id="nw-field-is_active" name="is_active"><span class="nw-toggle-slider"></span></label>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="nw-modal-footer">
                        <button class="nw-btn nw-btn-danger" id="nw-delete-btn" style="display:none;margin-right:auto;">🗑 Delete</button>
                        <button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
                        <button class="nw-btn nw-btn-primary" id="nw-save-btn"><span id="nw-save-label">Save Tag</span></button>
                    </div>
                </div>
            </div>
        </div>
    <?php }

    private function supa( string $method, string $endpoint, array $body = [], array $extra = [] ): array {
        $args = [
            'method'  => $method,
            'timeout' => 10,
            'headers' => array_merge( [
                'apikey'        => $this->supabase_key,
                'Authorization' => 'Bearer ' . $this->supabase_key,
                'Content-Type'  => 'application/json',
                'Prefer'        => 'return=representation',
            ], $extra ),
        ];
        if ( $body ) $args['body'] = wp_json_encode( $body );
        $res = wp_remote_request( $this->supabase_url . '/rest/v1/' . $endpoint, $args );
        if ( is_wp_error( $res ) ) return [ 'error' => $res->get_error_message() ];
        return [ 'code' => wp_remote_retrieve_response_code( $res ), 'data' => json_decode( wp_remote_retrieve_body( $res ), true ) ];
    }

    public function ajax_get_all(): void {
        check_ajax_referer( 'neoweaver_sd', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }
        $res = $this->supa( 'GET', 'cyber_style_dictionary?select=id,tag_name,category,interpretation_en,is_active,created_at&order=tag_name.asc' );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( $res['data'] );
    }

    public function ajax_save(): void {
        check_ajax_referer( 'neoweaver_sd', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }
        $raw      = $_POST['tag'] ?? [];
        $id       = sanitize_text_field( $raw['id'] ?? '' );
        $category = sanitize_text_field( $raw['category'] ?? 'general' );
        $payload = [
            'tag_name'          => sanitize_text_field(     $raw['tag_name']           ?? '' ),
            'category'          => in_array( $category, $this->categories, true ) ? $category : 'general',
            'interpretation_en' => sanitize_textarea_field( $raw['interpretation_en']  ?? '' ),
            'is_active'         => filter_var( $raw['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN ),
        ];
        if ( empty( $payload['tag_name'] ) ) {
            wp_send_json_error( 'Tag name is required.' );
            return;
        }
        if ( empty( $payload['interpretation_en'] ) ) {
            wp_send_json_error( 'Interpretation is required.' );
            return;
        }
        $res = $id
            ? $this->supa( 'PATCH', 'cyber_style_dictionary?id=eq.' . rawurlencode( $id ), $payload )
            : $this->supa( 'POST',  'cyber_style_dictionary', $payload );
        if ( isset( $res['error'] ) ) {
            wp_send_json_error( $res['error'] );
            return;
        }
        $code = $res['code'] ?? 0;
        ( $code >= 200 && $code < 300 )
            ? wp_send_json_success( $res['data'][0] ?? $res['data'] )
            : wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code );
    }

    public function ajax_toggle(): void {
        check_ajax_referer( 'neoweaver_sd', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }
        $id    = sanitize_text_field( $_POST['tag_id']   ?? '' );
        $state = filter_var(           $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );
        if ( ! $id ) {
            wp_send_json_error( 'Missing ID' );
            return;
        }
        $res = $this->supa( 'PATCH', 'cyber_style_dictionary?id=eq.' . rawurlencode( $id ), [ 'is_active' => $state ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( [ 'is_active' => $state ] );
    }

    public function ajax_delete(): void {
        check_ajax_referer( 'neoweaver_sd', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }
        $id = sanitize_text_field( $_POST['tag_id'] ?? '' );
        if ( ! $id ) {
            wp_send_json_error( 'Missing ID' );
            return;
        }
        $res = $this->supa( 'DELETE', 'cyber_style_dictionary?id=eq.' . rawurlencode( $id ), [], [ 'Prefer' => '' ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( 'deleted' );
    }
}

new NeoWeaver_Style_Dictionary_Admin();
