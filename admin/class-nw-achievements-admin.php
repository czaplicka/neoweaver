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

    /* ---------------------------------------------------------------- */
    /*  CSS                                                               */
    /* ---------------------------------------------------------------- */

    private function get_css(): string { return <<<'CSS'
.nw-panel{font-family:'Chakra Petch',monospace;color:#e0e0e0}.nw-panel *{box-sizing:border-box}
.nw-panel-header{display:flex;align-items:center;justify-content:space-between;padding:20px 0 16px;border-bottom:1px solid #2a2a2a;margin-bottom:16px;flex-wrap:wrap;gap:10px}
.nw-panel-title{font-size:22px;font-weight:700;color:#fff;margin:0;font-family:'Chakra Petch',monospace}
.nw-accent{color:#adff00}.nw-panel-subtitle{color:#555;font-weight:400;font-size:18px;margin-left:4px}
.nw-header-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.nw-select-filter,.nw-search-input{font-family:'Chakra Petch',monospace;font-size:12px;background:#0d0d0d;border:1px solid #2a2a2a;border-radius:5px;color:#ccc;padding:6px 10px;cursor:pointer}
.nw-search-input{color:#e0e0e0;width:160px;cursor:text}
.nw-select-filter:focus,.nw-search-input:focus{outline:none;border-color:#adff00}
.nw-btn{font-family:'Chakra Petch',monospace;font-size:12px;font-weight:600;padding:7px 16px;border-radius:5px;border:1px solid transparent;cursor:pointer;transition:all .15s;text-transform:uppercase;letter-spacing:.5px}
.nw-btn-primary{background:#adff00;color:#0a0a0a;border-color:#adff00}.nw-btn-primary:hover{background:#c8ff40}
.nw-btn-ghost{background:transparent;color:#adff00;border-color:#2e2e2e}.nw-btn-ghost:hover{border-color:#adff00}
.nw-btn-danger{background:transparent;color:#ff4444;border-color:#3a1111}.nw-btn-danger:hover{background:#2a0000;border-color:#ff4444}
.nw-stats-bar{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.nw-stat-pill{font-size:12px;padding:4px 12px;border-radius:20px;background:#1a1a1a;border:1px solid #2e2e2e;color:#aaa}
.nw-stat-pill strong{color:#fff}
.nw-pill-active{border-color:#adff00}.nw-pill-active strong{color:#adff00}
.nw-pill-inactive strong{color:#ff6b35}
.nw-pill-account{border-color:#44aaff}.nw-pill-account strong{color:#44aaff}
.nw-pill-character{border-color:#cc88ff}.nw-pill-character strong{color:#cc88ff}
.nw-pill-hidden{border-color:#ff9f00}.nw-pill-hidden strong{color:#ff9f00}
.nw-notice{padding:10px 16px;border-radius:6px;margin-bottom:14px;font-size:13px;border-left:3px solid}
.nw-notice-success{background:#0a2800;border-color:#adff00;color:#adff00}.nw-notice-error{background:#2a0000;border-color:#ff4444;color:#ff4444}
.nw-table-wrap{background:#111;border:1px solid #222;border-radius:8px;overflow:hidden}
.nw-table{width:100%;border-collapse:collapse;font-size:13px}
.nw-table thead tr{background:#1a1a1a;border-bottom:1px solid #2a2a2a}
.nw-table th{padding:10px 14px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#666;font-weight:600}
.nw-table tbody tr{border-bottom:1px solid #1e1e1e;transition:background .12s}
.nw-table tbody tr:last-child{border-bottom:none}.nw-table tbody tr:hover{background:#161616}
.nw-table td{padding:10px 14px;vertical-align:middle}
.nw-col-icon{width:64px;text-align:center!important}
.nw-ach-badge{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;margin:0 auto;border:1px solid rgba(255,255,255,.1)}
.nw-ach-badge svg{width:20px;height:20px;stroke:#fff;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.nw-ach-id{font-size:10px;color:#555;font-family:monospace;margin-top:2px}
.nw-ach-title{font-weight:600;color:#fff}
.nw-cat-badge,.nw-scope-badge{display:inline-block;font-size:10px;padding:2px 8px;border-radius:3px;text-transform:uppercase;letter-spacing:.4px;font-weight:600;border:1px solid}
.nw-cat-badge{background:#1e1e1e;border-color:#2e2e2e;color:#aaa}
.nw-cat-badge-exploration{color:#44ffbb;border-color:#0a3a28;background:#021a12}
.nw-cat-badge-social{color:#ff88cc;border-color:#4a1a30;background:#200a18}
.nw-cat-badge-progression{color:#adff00;border-color:#2a3a00;background:#121800}
.nw-cat-badge-mission{color:#ffdd44;border-color:#3a3000;background:#1a1600}
.nw-cat-badge-loot{color:#ff9944;border-color:#3a2000;background:#1a0e00}
.nw-cat-badge-secret{color:#aa88ff;border-color:#2a1a4a;background:#110920}
.nw-cat-badge-system{color:#aaa;border-color:#2e2e2e;background:#1a1a1a}
.nw-scope-badge{background:#001a2e;border-color:#1a3a5e;color:#5599ff}
.nw-scope-badge-character{background:#1a0a2e;border-color:#3a1a5e;color:#cc88ff}
.nw-goal-val{font-size:13px;font-weight:700;color:#adff00}
.nw-hidden-yes{font-size:11px;color:#ff9f00}.nw-hidden-no{font-size:11px;color:#444}
.nw-row-inactive td:not(:last-child):not(:first-child){opacity:.4}
.nw-toggle{position:relative;display:inline-block;width:40px;height:22px}
.nw-toggle input{opacity:0;width:0;height:0}
.nw-toggle-slider{position:absolute;inset:0;background:#2a2a2a;border-radius:22px;cursor:pointer;transition:background .2s;border:1px solid #3a3a3a}
.nw-toggle-slider::before{content:'';position:absolute;width:16px;height:16px;left:2px;top:2px;background:#555;border-radius:50%;transition:all .2s}
.nw-toggle input:checked+.nw-toggle-slider{background:#1a3300;border-color:#adff00}
.nw-toggle input:checked+.nw-toggle-slider::before{background:#adff00;transform:translateX(18px)}
.nw-toggle-orange{background:#2a2a2a}
.nw-toggle input:checked+.nw-toggle-orange{background:#2a1800;border-color:#ff9f00}
.nw-toggle input:checked+.nw-toggle-orange::before{background:#ff9f00;transform:translateX(18px)}
.nw-row-actions{display:flex;gap:6px}
.nw-action-btn{font-family:'Chakra Petch',monospace;font-size:11px;padding:4px 10px;border-radius:4px;border:1px solid #2e2e2e;background:transparent;color:#aaa;cursor:pointer;transition:all .15s;text-transform:uppercase}
.nw-action-btn:hover{border-color:#adff00;color:#adff00}
.nw-spinner{display:inline-block;width:16px;height:16px;border:2px solid #333;border-top-color:#adff00;border-radius:50%;animation:nw-spin .6s linear infinite;vertical-align:middle;margin-right:8px}
@keyframes nw-spin{to{transform:rotate(360deg)}}
.nw-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:99998;display:flex;align-items:center;justify-content:center;padding:20px}
.nw-modal{background:#111;border:1px solid #2e2e2e;border-radius:10px;width:100%;max-width:700px;max-height:92vh;overflow-y:auto;display:flex;flex-direction:column;font-family:'Chakra Petch',monospace}
.nw-modal-header{display:flex;align-items:center;justify-content:space-between;padding:18px 24px 14px;border-bottom:1px solid #1e1e1e;position:sticky;top:0;background:#111;z-index:1}
.nw-modal-header h2{margin:0;font-size:16px;color:#fff}
.nw-modal-close{background:none;border:none;color:#666;font-size:18px;cursor:pointer;padding:2px 6px;border-radius:4px}
.nw-modal-close:hover{color:#fff;background:#222}
.nw-modal-body{padding:20px 24px;flex:1}
.nw-modal-footer{padding:14px 24px;border-top:1px solid #1e1e1e;display:flex;justify-content:flex-end;align-items:center;gap:10px;position:sticky;bottom:0;background:#111}
.nw-section-label{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#adff00;font-weight:700;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #1e2e00}
.nw-section-label:first-child{margin-top:0}
.nw-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.nw-field{display:flex;flex-direction:column;gap:5px}.nw-field-full{grid-column:1/-1}.nw-field-toggles{grid-column:1/-1}
.nw-field label{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#666;font-weight:600}
.nw-req{color:#ff4444}.nw-hint{font-size:10px;color:#444;text-transform:none;letter-spacing:0;font-weight:400}
.nw-field input[type="text"],.nw-field input[type="number"],.nw-field textarea,.nw-select{background:#0d0d0d;border:1px solid #2a2a2a;border-radius:5px;color:#e0e0e0;padding:8px 10px;font-family:'Chakra Petch',monospace;font-size:13px;transition:border-color .15s;width:100%}
.nw-field input:focus,.nw-field textarea:focus,.nw-select:focus{outline:none;border-color:#adff00;box-shadow:0 0 0 2px rgba(173,255,0,.08)}
.nw-field textarea{resize:vertical}.nw-select option{background:#111}
.nw-color-row{display:flex;gap:8px;align-items:center}
.nw-color-picker{width:44px;height:36px;padding:2px;border:1px solid #2a2a2a;border-radius:5px;background:#0d0d0d;cursor:pointer;flex-shrink:0}
.nw-color-text{flex:1}
.nw-icon-input-row{display:flex;align-items:center;gap:8px}
.nw-icon-preview{font-size:22px;line-height:1;width:42px;height:42px;display:flex;align-items:center;justify-content:center;border-radius:8px;border:1px solid #2a2a2a;background:#0d0d0d;flex-shrink:0;color:#fff}
.nw-icon-preview svg{width:22px;height:22px;stroke:#fff;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.nw-icon-input-row input{flex:1}
.nw-toggle-row{display:flex;gap:20px;align-items:center;padding-top:4px}
.nw-toggle-label{display:flex;align-items:center;gap:8px;cursor:pointer;font-size:12px;color:#aaa}
.nw-badge-preview{display:flex;align-items:center;gap:14px;padding:14px;background:#0d0d0d;border:1px solid #2a2a2a;border-radius:8px}
.nw-badge-icon{width:52px;height:52px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;background:#2c3e50;flex-shrink:0;transition:background .2s}
.nw-badge-icon svg{width:24px;height:24px;stroke:#fff;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.nw-badge-title{font-weight:700;color:#fff;font-size:14px}.nw-badge-desc{font-size:12px;color:#888;margin-top:3px}
CSS;
    }

    /* ---------------------------------------------------------------- */
    /*  JS                                                                */
    /* ---------------------------------------------------------------- */

    private function get_js(): string { return <<<'JS'
jQuery(function($){
    var ajaxurl=NWAch.ajaxurl, nonce=NWAch.nonce;
    var all=[], filtered=[];

    function esc(s){return $('<span>').text(s||'').html();}
    function notice(msg,type){var el=$('#nw-notice');el.attr('class','nw-notice nw-notice-'+type).text(msg).show();setTimeout(function(){el.fadeOut(300);},3500);}

    /**
     * isEmoji: true if first codepoint is outside ASCII.
     * Uses codepoint comparison for broad browser compat (avoids \p{Emoji} regex).
     */
    function isEmoji(s){
        if(!s) return false;
        var cp=s.codePointAt(0);
        return cp>127;
    }

    function renderIcon(slug){
        if(!slug||slug==='default_icon'||slug==='trophy') return '<i data-lucide="trophy"></i>';
        if(isEmoji(slug)) return '<span style="font-size:22px;line-height:1;">'+esc(slug)+'</span>';
        return '<i data-lucide="'+esc(slug)+'"></i>';
    }

    function renderIconPreview(slug){
        if(!slug||slug==='default_icon'||slug==='trophy') return '<i data-lucide="trophy"></i>';
        if(isEmoji(slug)) return '<span style="font-size:26px;line-height:1;">'+esc(slug)+'</span>';
        return '<i data-lucide="'+esc(slug)+'"></i>';
    }

    /** Call after injecting data-lucide elements into a container */
    function initLucide(container){
        if(typeof lucide !== 'undefined'){
            lucide.createIcons({attrs:{class:'lucide-icon'},nameAttr:'data-lucide',nodes: container ? Array.from(container.querySelectorAll('[data-lucide]')) : undefined});
        }
    }

    /* ---- stats ---- */
    function updateStats(data){
        var active=0,inactive=0,account=0,character=0,hidden=0;
        $.each(data,function(_,a){
            if(a.is_active!==false) active++; else inactive++;
            if(a.scope==='account') account++;
            if(a.scope==='character') character++;
            if(a.hidden_until_earned===true) hidden++;
        });
        $('#nw-total').text(data.length);
        $('#nw-active').text(active);
        $('#nw-inactive').text(inactive);
        $('#nw-count-account').text(account);
        $('#nw-count-character').text(character);
        $('#nw-count-hidden').text(hidden);
    }

    /* ---- category badge colour ---- */
    var catClass={exploration:'nw-cat-badge-exploration',social:'nw-cat-badge-social',progression:'nw-cat-badge-progression',
        mission:'nw-cat-badge-mission',loot:'nw-cat-badge-loot',secret:'nw-cat-badge-secret',system:'nw-cat-badge-system'};

    function catBadge(cat){
        if(!cat) return '<span style="color:#555">\u2014</span>';
        var cls=catClass[cat]||'';
        return '<span class="nw-cat-badge '+cls+'">'+esc(cat)+'</span>';
    }
    function scopeBadge(scope){
        var cls=scope==='character'?'nw-scope-badge-character':'';
        return '<span class="nw-scope-badge '+cls+'">'+esc(scope||'\u2014')+'</span>';
    }

    /* ---- render table ---- */
    function renderTable(data){
        var tbody=$('#nw-achievements-tbody');
        if(!data.length){tbody.html('<tr><td colspan="8" style="text-align:center;padding:32px;color:#555;">No achievements found.</td></tr>');return;}
        tbody.html(data.map(function(a){
            var active=a.is_active!==false;
            var bg=esc(a.bg_color||'#2c3e50');
            var badgeHtml='<div class="nw-ach-badge" style="background:'+bg+'">'+renderIcon(a.icon_slug)+'</div>';
            var hiddenHtml=a.hidden_until_earned
                ?'<span class="nw-hidden-yes" title="Hidden until earned">&#128065; hidden</span>'
                :'<span class="nw-hidden-no">visible</span>';
            return '<tr data-id="'+esc(a.id)+'" class="'+((!active)?'nw-row-inactive':'')+'">'\
                +'<td>'+badgeHtml+'</td>'\
                +'<td><div class="nw-ach-title">'+esc(a.title)+'</div><div class="nw-ach-id">'+esc(a.id)+'</div></td>'\
                +'<td>'+catBadge(a.category)+'</td>'\
                +'<td>'+scopeBadge(a.scope)+'</td>'\
                +'<td><span class="nw-goal-val">\u00d7'+esc(String(a.goal||1))+'</span></td>'\
                +'<td>'+hiddenHtml+'</td>'\
                +'<td><label class="nw-toggle"><input type="checkbox" class="nw-active-toggle" data-id="'+esc(a.id)+'" '+(active?'checked':'')+'><span class="nw-toggle-slider"></span></label></td>'\
                +'<td><div class="nw-row-actions"><button class="nw-action-btn nw-edit-btn" data-id="'+esc(a.id)+'">Edit</button></div></td>'\
                +'</tr>';
        }).join(''));
        initLucide(document.getElementById('nw-achievements-tbody'));
    }

    /* ---- apply filters (client-side) ---- */
    function applyFilters(){
        var scopeF=$('#nw-filter-scope').val();
        var catF=$('#nw-filter-category').val();
        var actF=$('#nw-filter-active').val();
        var hidF=$('#nw-filter-hidden').val();
        var q=$('#nw-search').val().toLowerCase().trim();
        var shown=all.filter(function(a){
            if(scopeF&&a.scope!==scopeF) return false;
            if(catF&&a.category!==catF) return false;
            if(actF==='1'&&a.is_active===false) return false;
            if(actF==='0'&&a.is_active!==false) return false;
            if(hidF==='1'&&!a.hidden_until_earned) return false;
            if(hidF==='0'&&a.hidden_until_earned) return false;
            if(q&&!(a.id.toLowerCase().includes(q)||(a.title||'').toLowerCase().includes(q))) return false;
            return true;
        });
        renderTable(shown);
    }
    $('#nw-filter-scope,#nw-filter-category,#nw-filter-active,#nw-filter-hidden').on('change',applyFilters);
    $('#nw-search').on('input',applyFilters);

    /* ---- load ---- */
    function loadAll(){
        $('#nw-achievements-tbody').html('<tr><td colspan="8" style="text-align:center;padding:32px;color:#555;"><div class="nw-spinner"></div> Loading\u2026</td></tr>');
        $.post(ajaxurl,{action:'nw_achievements_get_all',nonce:nonce},function(res){
            if(!res.success){notice('Error: '+res.data,'error');return;}
            all=res.data||[];
            updateStats(all);
            applyFilters();
        }).fail(function(){notice('Request failed.','error');});
    }

    /* ---- toggle active ---- */
    $(document).on('change','.nw-active-toggle',function(){
        var id=$(this).data('id'),val=$(this).is(':checked'),row=$(this).closest('tr');
        $.post(ajaxurl,{action:'nw_achievements_toggle',nonce:nonce,achievement_id:id,is_active:val?1:0},function(res){
            if(res.success){
                row.toggleClass('nw-row-inactive',!val);
                $.each(all,function(_,a){if(a.id===id){a.is_active=val;return false;}});
                updateStats(all);
                notice((val?'Activated':'Deactivated')+'.','success');
            } else {
                notice('Toggle failed: '+res.data,'error');
                row.find('.nw-active-toggle').prop('checked',!val);
            }
        });
    });

    /* ---- preview ---- */
    function updatePreview(){
        var title=$('#nw-field-title').val()||'Achievement Title';
        var desc=$('#nw-field-description').val()||'Description\u2026';
        var slug=$('#nw-field-icon_slug').val()||'trophy';
        var bg=$('#nw-field-bg_color').val()||'#2c3e50';
        $('#nw-preview-title').text(title);
        $('#nw-preview-desc').text(desc);
        $('#nw-badge-icon').html(renderIcon(slug)).css('background',bg);
        $('#nw-icon-preview').html(renderIconPreview(slug));
        initLucide(document.getElementById('nw-badge-icon'));
        initLucide(document.getElementById('nw-icon-preview'));
    }
    $(document).on('input','#nw-field-title,#nw-field-description,#nw-field-icon_slug,#nw-field-bg_color',updatePreview);
    $('#nw-field-bg_color_picker').on('input',function(){$('#nw-field-bg_color').val($(this).val());updatePreview();});
    $('#nw-field-bg_color').on('input',function(){
        var v=$(this).val();
        if(/^#[0-9a-fA-F]{6}$/.test(v))$('#nw-field-bg_color_picker').val(v);
        updatePreview();
    });
    $('#nw-field-icon_slug').on('input',updatePreview);

    /* ---- modal ---- */
    function openModal(id){
        $('#nw-achievement-form')[0].reset();
        $('#nw-field-original_id').val('');
        $('#nw-field-bg_color_picker').val('#2c3e50');
        $('#nw-field-bg_color').val('#2c3e50');
        $('#nw-icon-preview').html('<i data-lucide="trophy"></i>');
        $('#nw-badge-icon').html('<i data-lucide="trophy"></i>').css('background','#2c3e50');
        initLucide(document.getElementById('nw-icon-preview'));
        initLucide(document.getElementById('nw-badge-icon'));
        updatePreview();
        if(id){
            var a=null;
            $.each(all,function(_,x){if(x.id===id){a=x;return false;}});
            if(a){
                $('#nw-field-original_id').val(a.id);
                $('#nw-field-id').val(a.id);
                $('#nw-field-title').val(a.title||'');
                $('#nw-field-description').val(a.description||'');
                var slug=a.icon_slug||'trophy';
                $('#nw-field-icon_slug').val(slug);
                $('#nw-icon-preview').html(renderIconPreview(slug));
                initLucide(document.getElementById('nw-icon-preview'));
                var bg=a.bg_color||'#2c3e50';
                $('#nw-field-bg_color').val(bg);
                if(/^#[0-9a-fA-F]{6}$/.test(bg))$('#nw-field-bg_color_picker').val(bg);
                $('#nw-field-scope').val(a.scope||'account');
                $('#nw-field-category').val(a.category||'');
                $('#nw-field-goal').val(a.goal||1);
                $('#nw-field-hidden_until_earned').prop('checked',a.hidden_until_earned===true);
                $('#nw-field-is_active').prop('checked',a.is_active!==false);
                updatePreview();
            }
            $('#nw-modal-title').text('Edit Achievement');
            $('#nw-save-label').text('Save Changes');
            $('#nw-delete-btn').show().data('id',id);
        } else {
            $('#nw-modal-title').text('New Achievement');
            $('#nw-save-label').text('Create Achievement');
            $('#nw-delete-btn').hide();
        }
        $('#nw-modal-overlay').fadeIn(150);
    }
    function closeModal(){$('#nw-modal-overlay').fadeOut(150);}
    $('#nw-modal-close,#nw-cancel-btn').on('click',closeModal);
    $('#nw-modal-overlay').on('click',function(e){if($(e.target).is('#nw-modal-overlay'))closeModal();});
    $(document).on('click','.nw-edit-btn',function(){openModal($(this).data('id'));});
    $('#nw-add-btn').on('click',function(){openModal(null);});
    $('#nw-refresh-btn').on('click',loadAll);

    /* ---- save ---- */
    $('#nw-save-btn').on('click',function(){
        if(!$('#nw-field-id').val().trim()){notice('ID (slug) is required.','error');return;}
        if(!$('#nw-field-title').val().trim()){notice('Title is required.','error');return;}
        var btn=$(this); btn.prop('disabled',true);
        $('#nw-save-label').text('Saving\u2026');
        var fd={action:'nw_achievements_save',nonce:nonce,achievement:{}};
        $('#nw-achievement-form').serializeArray().forEach(function(f){
            if(f.name!=='is_active'&&f.name!=='hidden_until_earned') fd.achievement[f.name]=f.value;
        });
        fd.achievement.is_active=$('#nw-field-is_active').is(':checked')?1:0;
        fd.achievement.hidden_until_earned=$('#nw-field-hidden_until_earned').is(':checked')?1:0;
        $.post(ajaxurl,fd,function(res){
            btn.prop('disabled',false);
            $('#nw-save-label').text('Save Changes');
            if(res.success){notice('Achievement saved!','success');closeModal();loadAll();}
            else notice('Error: '+(res.data||'Unknown'),'error');
        }).fail(function(){btn.prop('disabled',false);$('#nw-save-label').text('Save Changes');notice('Request failed.','error');});
    });

    /* ---- delete ---- */
    $('#nw-delete-btn').on('click',function(){
        var id=$(this).data('id');
        if(!id||!confirm('Delete achievement \''+id+'\' permanently?')) return;
        $.post(ajaxurl,{action:'nw_achievements_delete',nonce:nonce,achievement_id:id},function(res){
            if(res.success){notice('Deleted.','success');closeModal();loadAll();}
            else notice('Delete failed: '+res.data,'error');
        });
    });

    loadAll();
});
JS;
    }
}

new NeoWeaver_Achievements_Admin();
