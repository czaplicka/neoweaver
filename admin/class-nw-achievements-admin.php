<?php
/**
 * NeoWeaver Admin Panel — Achievements (cyber_achievements)
 *
 * Columns: id (text PK), title, description, icon_slug, bg_color,
 *          scope (account|character), goal, hidden_until_earned,
 *          category (system|exploration|social|progression|mission|loot|secret|null),
 *          is_active, created_at
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Achievements_Admin {

    private string $supabase_url;
    private string $supabase_key;
    private string $page_slug   = 'neoweaver-achievements';
    private string $parent_slug = 'neoweaver';

    /** Exact values from DB constraint */
    private array $scopes     = [ 'account', 'character' ];
    private array $categories = [ 'system', 'exploration', 'social', 'progression', 'mission', 'loot', 'secret' ];

    public function __construct() {
        $this->supabase_url = rtrim( tw_supabase_url(), '/' );
        $this->supabase_key = tw_supabase_anon_key();
        add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_nw_achievements_get_all', [ $this, 'ajax_get_all' ] );
        add_action( 'wp_ajax_nw_achievements_save',    [ $this, 'ajax_save'    ] );
        add_action( 'wp_ajax_nw_achievements_toggle',  [ $this, 'ajax_toggle'  ] );
        add_action( 'wp_ajax_nw_achievements_delete',  [ $this, 'ajax_delete'  ] );
    }

    public function register_menu(): void {
        add_submenu_page(
            $this->parent_slug, 'NeoWeaver — Achievements', '🏆 Achievements',
            'manage_options', $this->page_slug, [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, $this->page_slug ) ) return;
        
        wp_enqueue_style( 'chakra-petch',
            'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap', [], null );
        
        // Lucide icons — UMD build loaded in footer
        wp_enqueue_script( 'lucide',
            'https://cdn.jsdelivr.net/npm/lucide@latest/dist/umd/lucide.min.js', [], null, true );
            
        wp_add_inline_style( 'chakra-petch', $this->get_css() );
        
        wp_register_script( 'nw-achievements-admin', false, [ 'jquery', 'lucide' ], null, true );
        wp_enqueue_script( 'nw-achievements-admin' );
        wp_localize_script( 'nw-achievements-admin', 'NWAch', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'neoweaver_achievements' ),
        ] );
        
        wp_add_inline_script( 'nw-achievements-admin', $this->get_js() );
    }

    /* ---------------------------------------------------------------- */
    /*  CSS & JS HELPERS (DODANE, ABY UNIKNĄĆ FATAL ERROR)              */
    /* ---------------------------------------------------------------- */

    private function get_css(): string {
        // Podstawowy CSS zachowujący branding NeoWeaver (Chakra Petch + dominujący #adff00)
        // Możesz tu wkleić resztę swoich stylów dla panelu
        return "
            .nw-panel { font-family: 'Chakra Petch', sans-serif; }
            .nw-accent { color: #adff00; font-weight: bold; }
        ";
    }

    private function get_js(): string {
        // Inicjalizacja ikon oraz miejsce na Twoją logikę interfejsu (AJAX, modale)
        return "
            jQuery(document).ready(function($) {
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
                // Tutaj dodaj kod nasłuchujący na kliknięcia (nw-add-btn, nw-save-btn, itp.)
            });
        ";
    }

    /* ---------------------------------------------------------------- */
    /*  SUPABASE                                                          */
    /* ---------------------------------------------------------------- */

    private function supa( string $method, string $endpoint, array $body = [], array $extra = [] ): array {
        $args = [
            'method'  => $method, 'timeout' => 10,
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
        return [
            'code' => wp_remote_retrieve_response_code( $res ),
            'data' => json_decode( wp_remote_retrieve_body( $res ), true ),
        ];
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX                                                              */
    /* ---------------------------------------------------------------- */

    public function ajax_get_all(): void {
        check_ajax_referer( 'neoweaver_achievements', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $cat = sanitize_text_field( $_POST['filter_category'] ?? '' );
        $sc  = sanitize_text_field( $_POST['filter_scope']    ?? '' );
        $qs  = 'cyber_achievements?select=id,title,description,icon_slug,bg_color,scope,goal,hidden_until_earned,category,is_active&order=category.asc,title.asc';
        if ( $cat ) $qs .= '&category=eq.' . urlencode( $cat );
        if ( $sc  ) $qs .= '&scope=eq.'    . urlencode( $sc );
        $res = $this->supa( 'GET', $qs );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( $res['data'] );
    }

    public function ajax_save(): void {
        check_ajax_referer( 'neoweaver_achievements', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $raw     = $_POST['achievement'] ?? [];
        $orig_id = sanitize_text_field( $raw['original_id'] ?? '' );
        $new_id  = sanitize_text_field( $raw['id']          ?? '' );
        if ( ! $new_id ) wp_send_json_error( 'ID (slug) is required.' );

        $scope = sanitize_text_field( $raw['scope'] ?? 'account' );
        $cat   = sanitize_text_field( $raw['category'] ?? '' );

        $payload = [
            'id'                  => $new_id,
            'title'               => sanitize_text_field(     $raw['title']       ?? '' ),
            'description'         => sanitize_textarea_field( $raw['description'] ?? '' ) ?: null,
            'icon_slug'           => sanitize_text_field(     $raw['icon_slug']   ?? 'trophy' ) ?: 'trophy',
            'bg_color'            => sanitize_hex_color(      $raw['bg_color']    ?? '#2c3e50' ) ?: '#2c3e50',
            'scope'               => in_array( $scope, $this->scopes, true ) ? $scope : 'account',
            'goal'                => max( 1, (int)( $raw['goal'] ?? 1 ) ),
            'hidden_until_earned' => filter_var( $raw['hidden_until_earned'] ?? false, FILTER_VALIDATE_BOOLEAN ),
            'category'            => ( $cat && in_array( $cat, $this->categories, true ) ) ? $cat : null,
            'is_active'           => filter_var( $raw['is_active'] ?? true,  FILTER_VALIDATE_BOOLEAN ),
        ];

        if ( empty( $payload['title'] ) ) wp_send_json_error( 'Title is required.' );

        $res = $orig_id
            ? $this->supa( 'PATCH', 'cyber_achievements?id=eq.' . urlencode( $orig_id ), $payload )
            : $this->supa( 'POST',  'cyber_achievements', $payload );
        if ( isset( $res['error'] ) ) wp_send_json_error( $res['error'] );
        $code = $res['code'] ?? 0;
        ( $code >= 200 && $code < 300 )
            ? wp_send_json_success( $res['data'][0] ?? $res['data'] )
            : wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code );
    }

    public function ajax_toggle(): void {
        check_ajax_referer( 'neoweaver_achievements', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $id    = sanitize_text_field( $_POST['achievement_id'] ?? '' );
        $state = filter_var( $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );
        $res = $this->supa( 'PATCH', 'cyber_achievements?id=eq.' . urlencode( $id ), [ 'is_active' => $state ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( [ 'is_active' => $state ] );
    }

    public function ajax_delete(): void {
        check_ajax_referer( 'neoweaver_achievements', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $id = sanitize_text_field( $_POST['achievement_id'] ?? '' );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );
        $res = $this->supa( 'DELETE', 'cyber_achievements?id=eq.' . urlencode( $id ), [], [ 'Prefer' => '' ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( 'deleted' );
    }

    /* ---------------------------------------------------------------- */
    /*  RENDER                                                            */
    /* ---------------------------------------------------------------- */

    public function render_page(): void { ?>
        <div class="wrap nw-panel" id="nw-achievements-panel">
            <div class="nw-panel-header">
                <h1 class="nw-panel-title"><span class="nw-accent">Neo</span>Weaver <span class="nw-panel-subtitle">/ Achievements</span></h1>
                <div class="nw-header-actions">
                    <select id="nw-filter-category" class="nw-select-filter">
                        <option value="">All categories</option>
                        <?php foreach ( $this->categories as $c ) : ?>
                            <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html(ucfirst($c)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="nw-filter-scope" class="nw-select-filter">
                        <option value="">All scopes</option>
                        <?php foreach ( $this->scopes as $s ) : ?>
                            <option value="<?php echo esc_attr($s); ?>"><?php echo esc_html(ucfirst($s)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="nw-filter-active" class="nw-select-filter">
                        <option value="">Active &amp; Inactive</option>
                        <option value="1">Active only</option>
                        <option value="0">Inactive only</option>
                    </select>
                    <select id="nw-filter-hidden" class="nw-select-filter">
                        <option value="">All visibility</option>
                        <option value="1">Hidden until earned</option>
                        <option value="0">Always visible</option>
                    </select>
                    <input type="text" id="nw-search" class="nw-search-input" placeholder="Search id or title&hellip;">
                    <button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">&#8635; Refresh</button>
                    <button class="nw-btn nw-btn-primary" id="nw-add-btn">+ New Achievement</button>
                </div>
            </div>

            <div id="nw-notice" class="nw-notice" style="display:none;"></div>

            <div class="nw-stats-bar">
                <span class="nw-stat-pill">Total: <strong id="nw-total">&mdash;</strong></span>
                <span class="nw-stat-pill nw-pill-active">Active: <strong id="nw-active">&mdash;</strong></span>
                <span class="nw-stat-pill nw-pill-inactive">Inactive: <strong id="nw-inactive">&mdash;</strong></span>
                <span class="nw-stat-pill nw-pill-account">Account: <strong id="nw-count-account">&mdash;</strong></span>
                <span class="nw-stat-pill nw-pill-character">Character: <strong id="nw-count-character">&mdash;</strong></span>
                <span class="nw-stat-pill nw-pill-hidden">Hidden: <strong id="nw-count-hidden">&mdash;</strong></span>
            </div>

            <div class="nw-table-wrap">
                <table class="nw-table">
                    <thead><tr>
                        <th class="nw-col-icon">Icon</th>
                        <th>ID / Title</th>
                        <th>Category</th>
                        <th>Scope</th>
                        <th>Goal</th>
                        <th>Hidden</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody id="nw-achievements-tbody">
                        <tr><td colspan="8" style="text-align:center;padding:32px;color:#555;"><div class="nw-spinner"></div> Loading&hellip;</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- MODAL -->
            <div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
                <div class="nw-modal">
                    <div class="nw-modal-header">
                        <h2 id="nw-modal-title">Edit Achievement</h2>
                        <button class="nw-modal-close" id="nw-modal-close">&#x2715;</button>
                    </div>
                    <div class="nw-modal-body">
                        <form id="nw-achievement-form">
                            <input type="hidden" id="nw-field-original_id" name="original_id">

                            <div class="nw-section-label">Identity</div>
                            <div class="nw-form-grid">
                                <div class="nw-field">
                                    <label>ID (slug) <span class="nw-req">*</span> <span class="nw-hint">primary key</span></label>
                                    <input type="text" id="nw-field-id" name="id" required placeholder="e.g. first_login">
                                </div>
                                <div class="nw-field">
                                    <label>Title <span class="nw-req">*</span></label>
                                    <input type="text" id="nw-field-title" name="title" required placeholder="e.g. The Pioneer">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Description</label>
                                    <textarea id="nw-field-description" name="description" rows="3" placeholder="Shown to player when earned&hellip;"></textarea>
                                </div>
                            </div>

                            <div class="nw-section-label">Appearance</div>
                            <div class="nw-form-grid">
                                <div class="nw-field">
                                    <label>Icon Slug <span class="nw-hint">(emoji or Lucide slug e.g. trophy, compass, zap)</span></label>
                                    <div class="nw-icon-input-row">
                                        <span id="nw-icon-preview" class="nw-icon-preview"><i data-lucide="trophy"></i></span>
                                        <input type="text" id="nw-field-icon_slug" name="icon_slug" placeholder="e.g. trophy">
                                    </div>
                                </div>
                                <div class="nw-field">
                                    <label>Background Color</label>
                                    <div class="nw-color-row">
                                        <input type="color" id="nw-field-bg_color_picker" class="nw-color-picker" value="#2c3e50">
                                        <input type="text" id="nw-field-bg_color" name="bg_color" placeholder="#2c3e50" class="nw-color-text">
                                    </div>
                                </div>
                            </div>

                            <div class="nw-section-label">Classification</div>
                            <div class="nw-form-grid">
                                <div class="nw-field">
                                    <label>Scope <span class="nw-req">*</span></label>
                                    <select id="nw-field-scope" name="scope" class="nw-select">
                                        <?php foreach ( $this->scopes as $s ) : ?>
                                            <option value="<?php echo esc_attr($s); ?>"><?php echo esc_html(ucfirst($s)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="nw-field">
                                    <label>Category</label>
                                    <select id="nw-field-category" name="category" class="nw-select">
                                        <option value="">&mdash; None &mdash;</option>
                                        <?php foreach ( $this->categories as $c ) : ?>
                                            <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html(ucfirst($c)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="nw-field">
                                    <label>Goal <span class="nw-hint">(threshold count)</span></label>
                                    <input type="number" id="nw-field-goal" name="goal" min="1" value="1">
                                </div>
                            </div>

                            <div class="nw-section-label">Visibility &amp; Status</div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-toggles">
                                    <div class="nw-toggle-row">
                                        <label class="nw-toggle-label">
                                            <label class="nw-toggle">
                                                <input type="checkbox" id="nw-field-hidden_until_earned" name="hidden_until_earned">
                                                <span class="nw-toggle-slider nw-toggle-orange"></span>
                                            </label>
                                            <span>Hidden until earned</span>
                                        </label>
                                        <label class="nw-toggle-label">
                                            <label class="nw-toggle">
                                                <input type="checkbox" id="nw-field-is_active" name="is_active" checked>
                                                <span class="nw-toggle-slider"></span>
                                            </label>
                                            <span>Active</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="nw-section-label">Badge Preview</div>
                            <div class="nw-badge-preview">
                                <div class="nw-badge-icon" id="nw-badge-icon"><i data-lucide="trophy"></i></div>
                                <div>
                                    <div class="nw-badge-title" id="nw-preview-title">Achievement Title</div>
                                    <div class="nw-badge-desc"  id="nw-preview-desc">Description&hellip;</div>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="nw-modal-footer">
                        <button class="nw-btn nw-btn-danger" id="nw-delete-btn" style="display:none;margin-right:auto;">&#128465; Delete</button>
                        <button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
                        <button class="nw-btn nw-btn-primary" id="nw-save-btn"><span id="nw-save-label">Save Achievement</span></button>
                    </div>
                </div>
            </div>
        </div>
    <?php } 
    
} // <-- TUTAJ DODAŁEM BRAKUJĄCĄ KLAMRĘ ZAMYKAJĄCĄ KLASĘ

new NeoWeaver_Achievements_Admin();
