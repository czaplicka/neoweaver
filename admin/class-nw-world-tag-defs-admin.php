<?php
/**
 * NeoWeaver Admin Panel — World Tag Definitions (cyber_world_tag_defs)
 *
 * Columns: id, code, label, icon, color, description, category,
 *          source, sort_order, is_active, created_at, impact
 */

if ( ! defined( 'ABSPATH' ) ) exit;
class NeoWeaver_World_Tag_Defs_Admin {

    private string $supabase_url;
    private string $supabase_key;
    private string $page_slug   = 'neoweaver-world-tag-defs';
    private string $parent_slug = 'neoweaver';

    private array $sources = [ 'system', 'custom', 'imported' ];

    public function __construct() {
        $this->supabase_url = rtrim( tw_supabase_url(), '/' );
        $this->supabase_key = tw_supabase_anon_key();

        add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_nw_wtd_get_all', [ $this, 'ajax_get_all' ] );
        add_action( 'wp_ajax_nw_wtd_save',    [ $this, 'ajax_save'    ] );
        add_action( 'wp_ajax_nw_wtd_toggle',  [ $this, 'ajax_toggle'  ] );
        add_action( 'wp_ajax_nw_wtd_delete',  [ $this, 'ajax_delete'  ] );
    }

    public function register_menu(): void {
        add_submenu_page(
            $this->parent_slug,
            'NeoWeaver — World Tag Defs',
            '🏷️ World Tag Defs',
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
        wp_enqueue_style( 'nw-world-tag-defs-admin', $base . 'css/world-tag-defs-admin.css', [ 'chakra-petch' ], $ver );
        wp_enqueue_script( 'nw-world-tag-defs-admin', $base . 'js/world-tag-defs-admin.js', [ 'jquery' ], $ver, true );
        wp_localize_script( 'nw-world-tag-defs-admin', 'NW_WTD', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'neoweaver_wtd' ),
        ] );
    }

    public function render_page(): void { ?>
        <div class="wrap nw-panel" id="nw-wtd-panel">
            <div class="nw-panel-header">
                <h1 class="nw-panel-title"><span class="nw-accent">Neo</span>Weaver <span class="nw-panel-subtitle">/ World Tag Defs</span></h1>
                <div class="nw-header-actions">
                    <button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">↻ Refresh</button>
                    <button class="nw-btn nw-btn-primary" id="nw-add-btn">+ New Tag Def</button>
                </div>
            </div>
            <div id="nw-notice" class="nw-notice" style="display:none;"></div>
            <div class="nw-stats-bar">
                <span class="nw-stat-pill">Total: <strong id="nw-total">—</strong></span>
                <span class="nw-stat-pill nw-pill-active">Active: <strong id="nw-active">—</strong></span>
                <span class="nw-stat-pill nw-pill-inactive">Inactive: <strong id="nw-inactive">—</strong></span>
                <span class="nw-stat-pill nw-pill-system">System: <strong id="nw-count-system">—</strong></span>
                <span class="nw-stat-pill nw-pill-custom">Custom: <strong id="nw-count-custom">—</strong></span>
            </div>
            <div class="nw-filter-bar">
                <select id="nw-filter-category" class="nw-select nw-filter-select"><option value="">All Categories</option></select>
                <select id="nw-filter-source" class="nw-select nw-filter-select">
                    <option value="">All Sources</option>
                    <?php foreach ( $this->sources as $s ) : ?>
                        <option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( ucfirst( $s ) ); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="nw-filter-active" class="nw-select nw-filter-select">
                    <option value="">Active &amp; Inactive</option>
                    <option value="1">Active only</option>
                    <option value="0">Inactive only</option>
                </select>
                <input type="text" id="nw-filter-search" class="nw-filter-input" placeholder="Search code, label or description…">
            </div>
            <div class="nw-table-wrap">
                <table class="nw-table">
                    <thead><tr>
                        <th>Code</th><th>Label</th><th>Icon / Color</th><th>Category</th>
                        <th>Source</th><th>Impact</th><th>Order</th><th>Active</th><th>Actions</th>
                    </tr></thead>
                    <tbody id="nw-wtd-tbody">
                        <tr class="nw-loading-row"><td colspan="9"><div class="nw-spinner"></div> Loading tag defs…</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
                <div class="nw-modal">
                    <div class="nw-modal-header">
                        <h2 id="nw-modal-title">Edit World Tag Def</h2>
                        <button class="nw-modal-close" id="nw-modal-close">✕</button>
                    </div>
                    <div class="nw-modal-body">
                        <form id="nw-wtd-form">
                            <input type="hidden" id="nw-field-id" name="id">
                            <div class="nw-section-label">Identity</div>
                            <div class="nw-form-grid">
                                <div class="nw-field"><label>Code <span class="nw-req">*</span></label><input type="text" id="nw-field-code" name="code" required placeholder="e.g. URBAN_DECAY"></div>
                                <div class="nw-field"><label>Label <span class="nw-req">*</span></label><input type="text" id="nw-field-label" name="label" required placeholder="e.g. Urban Decay"></div>
                                <div class="nw-field nw-field-full"><label>Description</label><textarea id="nw-field-description" name="description" rows="3" placeholder="What this tag means in the world…"></textarea></div>
                            </div>
                            <div class="nw-section-label">Appearance</div>
                            <div class="nw-form-grid">
                                <div class="nw-field"><label>Icon (emoji or class)</label><input type="text" id="nw-field-icon" name="icon" placeholder="e.g. 🏙️ or lucide:building"></div>
                                <div class="nw-field">
                                    <label>Color</label>
                                    <div class="nw-color-row">
                                        <input type="color" id="nw-field-color-picker" value="#adff00">
                                        <input type="text"  id="nw-field-color" name="color" value="#adff00" placeholder="#adff00" maxlength="20">
                                    </div>
                                </div>
                            </div>
                            <div class="nw-section-label">Classification</div>
                            <div class="nw-form-grid">
                                <div class="nw-field"><label>Category</label><input type="text" id="nw-field-category" name="category" placeholder="e.g. environment, social, tech"></div>
                                <div class="nw-field">
                                    <label>Source</label>
                                    <select id="nw-field-source" name="source" class="nw-select">
                                        <?php foreach ( $this->sources as $s ) : ?>
                                            <option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( ucfirst( $s ) ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="nw-field"><label>Sort Order</label><input type="number" id="nw-field-sort_order" name="sort_order" min="0" max="32767" placeholder="0"></div>
                                <div class="nw-field"><label>Impact (numeric)</label><input type="number" id="nw-field-impact" name="impact" step="0.01" placeholder="0"></div>
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
                        <button class="nw-btn nw-btn-primary" id="nw-save-btn"><span id="nw-save-label">Save Tag Def</span></button>
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
        check_ajax_referer( 'neoweaver_wtd', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }
        $res = $this->supa( 'GET',
            'cyber_world_tag_defs?select=id,code,label,icon,color,description,category,source,sort_order,is_active,created_at,impact&order=sort_order.asc,code.asc'
        );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( $res['data'] );
    }

    public function ajax_save(): void {
        check_ajax_referer( 'neoweaver_wtd', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }
        $raw    = $_POST['tag'] ?? [];
        $id     = sanitize_text_field( $raw['id'] ?? '' );
        $source = sanitize_text_field( $raw['source'] ?? 'system' );
        $payload = [
            'code'        => strtoupper( sanitize_text_field(     $raw['code']        ?? '' ) ),
            'label'       =>             sanitize_text_field(     $raw['label']       ?? '' ),
            'icon'        =>             sanitize_text_field(     $raw['icon']        ?? '' ),
            'color'       => sanitize_hex_color(                  $raw['color']       ?? '#adff00' ) ?: '#adff00',
            'description' =>             sanitize_textarea_field( $raw['description'] ?? '' ),
            'category'    =>             sanitize_text_field(     $raw['category']    ?? '' ),
            'source'      => in_array( $source, $this->sources, true ) ? $source : 'system',
            'sort_order'  => is_numeric( $raw['sort_order'] ?? '' ) ? (int) $raw['sort_order'] : null,
            'impact'      => is_numeric( $raw['impact']     ?? '' ) ? (float) $raw['impact']   : 0,
            'is_active'   => filter_var( $raw['is_active']  ?? true, FILTER_VALIDATE_BOOLEAN ),
        ];
        foreach ( [ 'icon', 'description', 'category' ] as $f ) {
            if ( $payload[ $f ] === '' ) $payload[ $f ] = null;
        }
        if ( $payload['sort_order'] === null ) unset( $payload['sort_order'] );
        if ( empty( $payload['code'] ) ) {
            wp_send_json_error( 'Code is required.' );
            return;
        }
        if ( empty( $payload['label'] ) ) {
            wp_send_json_error( 'Label is required.' );
            return;
        }
        $res = $id
            ? $this->supa( 'PATCH', 'cyber_world_tag_defs?id=eq.' . rawrawurlencode( $id ), $payload )
            : $this->supa( 'POST',  'cyber_world_tag_defs', $payload );
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
        check_ajax_referer( 'neoweaver_wtd', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }
        $id    = sanitize_text_field( $_POST['tag_id']    ?? '' );
        $state = filter_var(           $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );
        if ( ! $id ) {
            wp_send_json_error( 'Missing ID' );
            return;
        }
        $res = $this->supa( 'PATCH', 'cyber_world_tag_defs?id=eq.' . rawrawurlencode( $id ), [ 'is_active' => $state ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( [ 'is_active' => $state ] );
    }

    public function ajax_delete(): void {
        check_ajax_referer( 'neoweaver_wtd', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }
        $id = sanitize_text_field( $_POST['tag_id'] ?? '' );
        if ( ! $id ) {
            wp_send_json_error( 'Missing ID' );
            return;
        }
        $res = $this->supa( 'DELETE', 'cyber_world_tag_defs?id=eq.' . rawrawurlencode( $id ), [], [ 'Prefer' => '' ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( 'deleted' );
    }
}

new NeoWeaver_World_Tag_Defs_Admin();
