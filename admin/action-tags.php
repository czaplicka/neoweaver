<?php
/**
 * NeoWeaver Admin — Action Tags & Tag Categories
 * Dwie tabele na jednym ekranie (taby).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class NW_Action_Tags_Admin {

    private $table_tags = 'cyber_action_tags';
    private $table_cats = 'cyber_action_tag_categories';
    private $nonce_key  = 'nw_action_tags_nonce';

    public function __construct() {
        add_action( 'admin_menu',           [ $this, 'register_menu' ], 12 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX — categories
        add_action( 'wp_ajax_nw_acats_load',      [ $this, 'ajax_cats_load' ] );
        add_action( 'wp_ajax_nw_acats_save',      [ $this, 'ajax_cats_save' ] );
        add_action( 'wp_ajax_nw_acats_delete',    [ $this, 'ajax_cats_delete' ] );
        add_action( 'wp_ajax_nw_acats_duplicate', [ $this, 'ajax_cats_duplicate' ] );

        // AJAX — tags
        add_action( 'wp_ajax_nw_atags_load',      [ $this, 'ajax_tags_load' ] );
        add_action( 'wp_ajax_nw_atags_save',      [ $this, 'ajax_tags_save' ] );
        add_action( 'wp_ajax_nw_atags_delete',    [ $this, 'ajax_tags_delete' ] );
        add_action( 'wp_ajax_nw_atags_duplicate', [ $this, 'ajax_tags_duplicate' ] );

        // HUD groups helper
        add_action( 'wp_ajax_nw_hud_groups_load', [ $this, 'ajax_hud_groups_load' ] );
        add_action( 'wp_ajax_nw_hud_save',        [ $this, 'ajax_hud_save' ] );
        add_action( 'wp_ajax_nw_hud_delete',      [ $this, 'ajax_hud_delete' ] );
        add_action( 'wp_ajax_nw_hud_duplicate',   [ $this, 'ajax_hud_duplicate' ] );
    }

    /* ── Menu ─────────────────────────────────────────────── */

    public function register_menu() {
        add_submenu_page(
            'neoweaver',
            __( 'Action Tags', 'neoweaver' ),
            __( '<span data-lucide-menu="activity"></span> Action Tags', 'neoweaver' ),
            'manage_options',
            'nw-action-tags',
            [ $this, 'render_page' ]
        );
    }

    /* ── Assets ───────────────────────────────────────────── */

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'nw-action-tags' ) === false ) return;

        $base = plugin_dir_url( __FILE__ );

        wp_enqueue_style(
            'nw-admin-core',
            NW_PLUGIN_URL . 'assets/css/admin/admin-core.css',
            [],
            NW_VERSION
        );
        wp_enqueue_style(
            'nw-action-tags',
           NW_PLUGIN_URL . 'assets/css/admin/action-tags.css',
            [ 'nw-admin-core' ],
            NW_VERSION
        );

        wp_enqueue_script( 'jquery' );
        wp_enqueue_script(
            'lucide',
            'https://unpkg.com/lucide@latest/dist/umd/lucide.min.js',
            [],
            null,
            true
        );
        wp_enqueue_script(
            'nw-action-tags',
            NW_PLUGIN_URL . 'assets/js/admin/action-tags.js',
            [ 'jquery', 'lucide' ],
            NW_VERSION,
            true
        );
        wp_localize_script( 'nw-action-tags', 'NWActionTags', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( $this->nonce_key ),
        ] );
    }

    /* ── Page HTML ────────────────────────────────────────── */

    public function render_page() { ?>
<div class="nw-admin-wrap">

    <div class="nw-admin-header">
        <div class="nw-admin-header-left">
            <i data-lucide="tags" style="width:20px;height:20px;color:#adff00;flex-shrink:0;"></i>
            <h1 class="nw-admin-title">Action Tags</h1>
        </div>
        <div class="nw-admin-header-actions">
            <button id="nw-tags-add-btn"  class="nw-btn nw-btn-primary" data-tab-add="tags">
                <i data-lucide="plus" style="width:14px;height:14px;"></i> New Tag
            </button>
            <button id="nw-cats-add-btn"  class="nw-btn nw-btn-secondary" data-tab-add="cats">
                <i data-lucide="folder-plus" style="width:14px;height:14px;"></i> New Category
            </button>
            <button id="nw-hud-add-btn" class="nw-btn nw-btn-ghost" data-tab-add="hud">
                <i data-lucide="layout-dashboard" style="width:14px;height:14px;"></i> New HUD Group
            </button>
        </div>
    </div>

    <div id="nw-at-notice" class="nw-notice" style="display:none;"></div>

    <!-- Stats bar — Tags -->
    <div class="nw-stats-bar" id="nw-tags-stats-bar">
        <div class="nw-stat-item">
            <span class="nw-stat-label">Total Tags</span>
            <span class="nw-stat-value" id="nw-tags-total">—</span>
        </div>
        <div class="nw-stat-divider"></div>
        <div class="nw-stat-item">
            <span class="nw-stat-label">Active</span>
            <span class="nw-stat-value nw-stat-green" id="nw-tags-active">—</span>
        </div>
        <div class="nw-stat-divider"></div>
        <div class="nw-stat-item">
            <span class="nw-stat-label">Positive</span>
            <span class="nw-stat-value" style="color:#adff00;" id="nw-tags-pos">—</span>
        </div>
        <div class="nw-stat-divider"></div>
        <div class="nw-stat-item">
            <span class="nw-stat-label">Negative</span>
            <span class="nw-stat-value" style="color:#ff5050;" id="nw-tags-neg">—</span>
        </div>
        <div class="nw-stat-divider"></div>
        <div class="nw-stat-item">
            <span class="nw-stat-label">Neutral</span>
            <span class="nw-stat-value" style="color:#888;" id="nw-tags-neu">—</span>
        </div>
        <div class="nw-stat-divider"></div>
        <div class="nw-stat-item">
            <span class="nw-stat-label">Categories</span>
            <span class="nw-stat-value" id="nw-cats-total">—</span>
        </div>
        <div class="nw-stat-divider"></div>
        <div class="nw-stat-item">
            <span class="nw-stat-label">HUD Groups</span>
            <span class="nw-stat-value" style="color:#44aaff;" id="nw-hud-total">—</span>
        </div>
    </div>

    <!-- Tabs -->
    <div class="nw-admin-card" style="padding:0;">
        <div class="nw-tab-nav">
            <button class="nw-tab-btn nw-tab-active" data-tab="tags">
                <i data-lucide="tag" style="width:13px;height:13px;"></i>
                Tags <span class="nw-tab-count" id="nw-tab-count-tags">—</span>
            </button>
            <button class="nw-tab-btn" data-tab="cats">
                <i data-lucide="folder" style="width:13px;height:13px;"></i>
                Categories <span class="nw-tab-count" id="nw-tab-count-cats">—</span>
            </button>
            <button class="nw-tab-btn" data-tab="hud">
                <i data-lucide="layout-dashboard" style="width:13px;height:13px;"></i>
                HUD Groups <span class="nw-tab-count" id="nw-tab-count-hud">—</span>
            </button>
        </div>

        <!-- ======= TAB: TAGS ======= -->
        <div id="nw-tab-tags" class="nw-tab-panel" style="padding:20px;">
            <div class="nw-table-controls">
                <div class="nw-search-wrap">
                    <i data-lucide="search" class="nw-search-icon"></i>
                    <input type="text" id="nw-tags-search" class="nw-search-input" placeholder="Search tags…">
                </div>
                <div class="nw-filter-group">
                    <select id="nw-tags-filter-cat" class="nw-select">
                        <option value="">All categories</option>
                    </select>
                    <select id="nw-tags-filter-sentiment" class="nw-select">
                        <option value="">All sentiments</option>
                        <option value="positive">Positive</option>
                        <option value="negative">Negative</option>
                        <option value="neutral">Neutral</option>
                    </select>
                    <button id="nw-tags-refresh-btn" class="nw-btn nw-btn-ghost nw-btn-sm">
                        <i data-lucide="refresh-cw" style="width:12px;height:12px;"></i>
                    </button>
                </div>
            </div>

            <div class="nw-table-wrap">
                <table class="nw-table">
                    <thead>
                        <tr>
                            <th style="width:36px;">Color</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th style="width:90px;">Sentiment</th>
                            <th style="width:80px;">Impact</th>
                            <th style="width:60px;">Active</th>
                            <th style="width:100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="nw-tags-tbody">
                        <tr class="nw-loading-row"><td colspan="7"><span class="nw-spinner"></span> Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div><!-- /tab-tags -->

        <!-- ======= TAB: CATEGORIES ======= -->
        <div id="nw-tab-cats" class="nw-tab-panel nw-hidden" style="padding:20px;">
            <div class="nw-table-controls">
                <div class="nw-search-wrap">
                    <i data-lucide="search" class="nw-search-icon"></i>
                    <input type="text" id="nw-cats-search" class="nw-search-input" placeholder="Search categories…">
                </div>
                <div class="nw-filter-group">
                    <button id="nw-cats-refresh-btn" class="nw-btn nw-btn-ghost nw-btn-sm">
                        <i data-lucide="refresh-cw" style="width:12px;height:12px;"></i>
                    </button>
                </div>
            </div>

            <div class="nw-table-wrap">
                <table class="nw-table">
                    <thead>
                        <tr>
                            <th style="width:36px;">Color</th>
                            <th>Internal Name</th>
                            <th>Display Name</th>
                            <th style="width:60px;">Sort</th>
                            <th>HUD Group</th>
                            <th style="width:100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="nw-cats-tbody">
                        <tr class="nw-loading-row"><td colspan="6"><span class="nw-spinner"></span> Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div><!-- /tab-cats -->
        <!-- ======= TAB: HUD GROUPS ======= -->
        <div id="nw-tab-hud" class="nw-tab-panel nw-hidden" style="padding:20px;">
            <div class="nw-table-controls">
                <div class="nw-search-wrap">
                    <i data-lucide="search" class="nw-search-icon"></i>
                    <input type="text" id="nw-hud-search" class="nw-search-input" placeholder="Search HUD groups…">
                </div>
                <div class="nw-filter-group">
                    <button id="nw-hud-refresh-btn" class="nw-btn nw-btn-ghost nw-btn-sm">
                        <i data-lucide="refresh-cw" style="width:12px;height:12px;"></i>
                    </button>
                </div>
            </div>
            <div class="nw-table-wrap">
                <table class="nw-table">
                    <thead>
                        <tr>
                            <th style="width:36px;">Color</th>
                            <th style="width:36px;">Icon</th>
                            <th>Slug</th>
                            <th>Label</th>
                            <th style="width:60px;">Sort</th>
                            <th style="width:100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="nw-hud-tbody">
                        <tr class="nw-loading-row"><td colspan="6"><span class="nw-spinner"></span> Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div><!-- /tab-hud -->

    </div><!-- /.nw-admin-card -->

</div><!-- /.nw-admin-wrap -->

<!-- ============================================================ -->
<!-- MODAL: TAG                                                    -->
<!-- ============================================================ -->
<div id="nw-tag-modal-overlay" class="nw-modal-overlay" style="display:none;">
    <div class="nw-modal">
        <div class="nw-modal-header">
            <h2 class="nw-modal-title" id="nw-tag-modal-title">New Tag</h2>
            <button class="nw-modal-close" data-modal="nw-tag-modal-overlay">
                <i data-lucide="x" style="width:16px;height:16px;"></i>
            </button>
        </div>
        <div class="nw-modal-body">
            <form id="nw-tag-form" autocomplete="off">
                <input type="hidden" id="nw-tag-field-id">

                <div class="nw-field-grid nw-field-grid-2">
                    <div class="nw-field">
                        <label class="nw-label">Name <span class="nw-required">*</span></label>
                        <input type="text" id="nw-tag-field-name" class="nw-input" placeholder="e.g. aggressive_action">
                        <p class="nw-field-hint">Will be auto-normalised (lowercase, underscores) by the DB trigger.</p>
                    </div>
                    <div class="nw-field">
                        <label class="nw-label">Category <span class="nw-required">*</span></label>
                        <select id="nw-tag-field-category" class="nw-select">
                            <option value="">— select —</option>
                        </select>
                    </div>
                </div>

                <div class="nw-field-grid nw-field-grid-3">
                    <div class="nw-field">
                        <label class="nw-label">Sentiment</label>
                        <select id="nw-tag-field-sentiment" class="nw-select">
                            <option value="neutral">Neutral</option>
                            <option value="positive">Positive</option>
                            <option value="negative">Negative</option>
                        </select>
                    </div>
                    <div class="nw-field">
                        <label class="nw-label">Impact</label>
                        <input type="number" id="nw-tag-field-impact" class="nw-input" step="0.01" placeholder="0.00">
                        <p class="nw-field-hint">Negative values allowed.</p>
                    </div>
                    <div class="nw-field">
                        <label class="nw-label">Color</label>
                        <div class="nw-color-input-wrap">
                            <input type="color" id="nw-tag-field-color-picker" value="#adff00">
                            <input type="text" id="nw-tag-field-color" class="nw-input" maxlength="7" placeholder="#adff00">
                        </div>
                    </div>
                </div>

                <div class="nw-field">
                    <label class="nw-label">Description</label>
                    <textarea id="nw-tag-field-description" class="nw-input nw-textarea" rows="3" placeholder="Optional description…"></textarea>
                </div>

                <div class="nw-field nw-field-toggle">
                    <label class="nw-label">Active</label>
                    <label class="nw-toggle-wrap">
                        <input type="checkbox" id="nw-tag-field-is-active" checked>
                        <span class="nw-toggle-slider"></span>
                    </label>
                </div>
            </form>
        </div>
        <div class="nw-modal-footer">
            <button id="nw-tag-delete-btn" class="nw-btn nw-btn-danger" style="display:none;">Delete</button>
            <button class="nw-btn nw-btn-ghost" data-modal-close="nw-tag-modal-overlay">Cancel</button>
            <button id="nw-tag-save-btn" class="nw-btn nw-btn-primary">
                <span id="nw-tag-save-label">Create Tag</span>
            </button>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: CATEGORY                                               -->
<!-- ============================================================ -->
<div id="nw-cat-modal-overlay" class="nw-modal-overlay" style="display:none;">
    <div class="nw-modal">
        <div class="nw-modal-header">
            <h2 class="nw-modal-title" id="nw-cat-modal-title">New Category</h2>
            <button class="nw-modal-close" data-modal="nw-cat-modal-overlay">
                <i data-lucide="x" style="width:16px;height:16px;"></i>
            </button>
        </div>
        <div class="nw-modal-body">
            <form id="nw-cat-form" autocomplete="off">
                <input type="hidden" id="nw-cat-field-id">

                <div class="nw-field-grid nw-field-grid-2">
                    <div class="nw-field">
                        <label class="nw-label">Internal Name <span class="nw-required">*</span></label>
                        <input type="text" id="nw-cat-field-internal" class="nw-input" placeholder="e.g. combat">
                    </div>
                    <div class="nw-field">
                        <label class="nw-label">Display Name <span class="nw-required">*</span></label>
                        <input type="text" id="nw-cat-field-display" class="nw-input" placeholder="e.g. Combat Actions">
                    </div>
                </div>

                <div class="nw-field-grid nw-field-grid-3">
                    <div class="nw-field">
                        <label class="nw-label">UI Color</label>
                        <div class="nw-color-input-wrap">
                            <input type="color" id="nw-cat-field-color-picker" value="#adff00">
                            <input type="text" id="nw-cat-field-ui-color" class="nw-input" maxlength="7" placeholder="#adff00">
                        </div>
                    </div>
                    <div class="nw-field">
                        <label class="nw-label">Sort Order</label>
                        <input type="number" id="nw-cat-field-sort" class="nw-input" value="0" min="0">
                    </div>
                    <div class="nw-field">
                        <label class="nw-label">HUD Group <span class="nw-required">*</span></label>
                        <select id="nw-cat-field-hud" class="nw-select">
                            <option value="">— select —</option>
                        </select>
                    </div>
                </div>

                <div class="nw-field">
                    <label class="nw-label">Description</label>
                    <textarea id="nw-cat-field-description" class="nw-input nw-textarea" rows="3" placeholder="Optional…"></textarea>
                </div>
            </form>
        </div>
        <div class="nw-modal-footer">
            <button id="nw-cat-delete-btn" class="nw-btn nw-btn-danger" style="display:none;">Delete</button>
            <button class="nw-btn nw-btn-ghost" data-modal-close="nw-cat-modal-overlay">Cancel</button>
            <button id="nw-cat-save-btn" class="nw-btn nw-btn-primary">
                <span id="nw-cat-save-label">Create Category</span>
            </button>
        </div>
    </div>
</div>


<!-- ============================================================ -->
<!-- MODAL: HUD GROUP                                              -->
<!-- ============================================================ -->
<div id="nw-hud-modal-overlay" class="nw-modal-overlay" style="display:none;">
    <div class="nw-modal">
        <div class="nw-modal-header">
            <h2 class="nw-modal-title" id="nw-hud-modal-title">New HUD Group</h2>
            <button class="nw-modal-close" data-modal="nw-hud-modal-overlay">
                <i data-lucide="x" style="width:16px;height:16px;"></i>
            </button>
        </div>
        <div class="nw-modal-body">
            <form id="nw-hud-form" autocomplete="off">
                <input type="hidden" id="nw-hud-field-id">
                <div class="nw-field-grid nw-field-grid-2">
                    <div class="nw-field">
                        <label class="nw-label">Slug <span class="nw-required">*</span></label>
                        <input type="text" id="nw-hud-field-slug" class="nw-input" placeholder="e.g. combat">
                        <p class="nw-field-hint">Lowercase, underscores only.</p>
                    </div>
                    <div class="nw-field">
                        <label class="nw-label">Display Label <span class="nw-required">*</span></label>
                        <input type="text" id="nw-hud-field-label" class="nw-input" placeholder="e.g. Combat">
                    </div>
                </div>
                <div class="nw-field-grid nw-field-grid-3">
                    <div class="nw-field">
                        <label class="nw-label">Base Color</label>
                        <div class="nw-color-input-wrap">
                            <input type="color" id="nw-hud-field-color-picker" value="#adff00">
                            <input type="text" id="nw-hud-field-color" class="nw-input" maxlength="7" placeholder="#adff00">
                        </div>
                    </div>
                    <div class="nw-field">
                        <label class="nw-label">Icon <span class="nw-field-hint-inline">(Lucide slug)</span></label>
                        <div class="nw-icon-input-wrap">
                            <input type="text" id="nw-hud-field-icon" class="nw-input" placeholder="e.g. sword">
                            <span id="nw-hud-icon-preview" class="nw-icon-preview"></span>
                        </div>
                    </div>
                    <div class="nw-field">
                        <label class="nw-label">Sort Order</label>
                        <input type="number" id="nw-hud-field-sort" class="nw-input" value="0" min="0">
                    </div>
                </div>
            </form>
        </div>
        <div class="nw-modal-footer">
            <button id="nw-hud-delete-btn" class="nw-btn nw-btn-danger" style="display:none;">Delete</button>
            <button class="nw-btn nw-btn-ghost" data-modal-close="nw-hud-modal-overlay">Cancel</button>
            <button id="nw-hud-save-btn" class="nw-btn nw-btn-primary">
                <span id="nw-hud-save-label">Create HUD Group</span>
            </button>
        </div>
    </div>
</div>

<script>
// Init Lucide icons in admin
document.addEventListener('DOMContentLoaded', function() {
    if (window.lucide) lucide.createIcons();
});
// Tab: header buttons jump to correct tab
jQuery(function($){
    $('[data-tab-add]').on('click', function(){
        var tab = $(this).data('tab-add');
        $('.nw-tab-btn[data-tab="' + tab + '"]').trigger('click');
    });
});
</script>
<?php
    }

    /* ── Security helper ──────────────────────────────────── */

    private function verify_nonce() {
        if ( ! check_ajax_referer( $this->nonce_key, 'nonce', false ) ) {
            wp_send_json_error( 'Invalid nonce', 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }
    }

    private function error_message( $result, string $fallback = 'Supabase error' ): string {
        if ( is_wp_error( $result ) ) {
            return $result->get_error_message();
        }
        return $fallback;
    }

    /* ── HUD Groups ──────────────────────────────────────── */

    public function ajax_hud_groups_load() {
        $this->verify_nonce();

        $rows = tw_supabase_get_admin(
            'cyber_hud_groups',
            [
                'select' => 'id,slug,display_label,base_color,icon,sort_order',
                'order'  => 'sort_order.asc,id.asc',
            ]
        );

        if ( is_wp_error( $rows ) ) {
            wp_send_json_error( $this->error_message( $rows ), 500 );
        }

        wp_send_json_success( is_array( $rows ) ? $rows : [] );
    }

    public function ajax_hud_save() {
        $this->verify_nonce();

        $id         = absint( $_POST['id'] ?? 0 );
        $slug       = sanitize_key( $_POST['slug'] ?? '' );
        $label      = sanitize_text_field( $_POST['display_label'] ?? '' );
        $base_color = sanitize_hex_color( $_POST['base_color'] ?? '#adff00' ) ?: '#adff00';
        $icon       = sanitize_text_field( $_POST['icon'] ?? '' );
        $sort_order = intval( $_POST['sort_order'] ?? 0 );

        if ( ! $slug || ! $label ) {
            wp_send_json_error( 'Slug and label are required.' );
        }

        $payload = [
            'slug'          => $slug,
            'display_label' => $label,
            'base_color'    => $base_color,
            'icon'          => $icon ?: null,
            'sort_order'    => $sort_order,
        ];

        if ( $id ) {
            $res = tw_supabase_request(
                'PATCH',
                'cyber_hud_groups',
                [ 'id' => 'eq.' . $id ],
                $payload,
                [
                    'headers' => [
                        'Prefer' => 'return=representation',
                    ],
                ]
            );
        } else {
            $res = tw_supabase_request(
                'POST',
                'cyber_hud_groups',
                [],
                $payload,
                [
                    'headers' => [
                        'Prefer' => 'return=representation',
                    ],
                ]
            );
        }

        if ( is_wp_error( $res ) ) {
            wp_send_json_error( $this->error_message( $res ), 500 );
        }

        $row = $res['data'][0] ?? [];
        wp_send_json_success( [ 'id' => $row['id'] ?? $id ] );
    }

    public function ajax_hud_delete() {
        $this->verify_nonce();

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'Invalid ID.' );
        }

        $res = tw_supabase_request(
            'DELETE',
            'cyber_hud_groups',
            [ 'id' => 'eq.' . $id ],
            null,
            [
                'headers' => [
                    'Prefer' => 'return=representation',
                ],
            ]
        );

        if ( is_wp_error( $res ) ) {
            wp_send_json_error( $this->error_message( $res ), 500 );
        }

        wp_send_json_success();
    }

    public function ajax_hud_duplicate() {
        $this->verify_nonce();

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'Invalid ID.' );
        }

        $rows = tw_supabase_get_admin(
            'cyber_hud_groups',
            [
                'select' => 'id,slug,display_label,base_color,icon,sort_order',
                'id'     => 'eq.' . $id,
                'limit'  => 1,
            ]
        );

        if ( is_wp_error( $rows ) || empty( $rows[0] ) ) {
            wp_send_json_error( 'HUD Group not found.' );
        }

        $row  = $rows[0];
        $base = rtrim( $row['slug'], '_' );
        $try  = $base . '_copy';
        $i    = 2;

        while ( true ) {
            $exists = tw_supabase_get_admin(
                'cyber_hud_groups',
                [
                    'select' => 'id',
                    'slug'   => 'eq.' . $try,
                    'limit'  => 1,
                ]
            );

            if ( ! is_wp_error( $exists ) && empty( $exists ) ) {
                break;
            }

            $try = $base . '_copy' . $i;
            $i++;
        }

        $new_row = [
            'slug'          => $try,
            'display_label' => $row['display_label'] . ' (copy)',
            'base_color'    => $row['base_color'],
            'icon'          => $row['icon'],
            'sort_order'    => intval( $row['sort_order'] ) + 1,
        ];

        $insert = tw_supabase_request(
            'POST',
            'cyber_hud_groups',
            [],
            $new_row,
            [
                'headers' => [
                    'Prefer' => 'return=representation',
                ],
            ]
        );

        if ( is_wp_error( $insert ) ) {
            wp_send_json_error( $this->error_message( $insert ), 500 );
        }

        wp_send_json_success( [ 'id' => $insert['data'][0]['id'] ?? 0 ] );
    }

    /* ================================================================ */
    /* CATEGORIES AJAX                                                  */
    /* ================================================================ */

    public function ajax_cats_load() {
        $this->verify_nonce();

        $rows = tw_supabase_get_admin(
            'cyber_action_tag_categories',
            [
                'select' => 'id,internal_name,display_name,description,ui_color,sort_order,hud_group_id',
                'order'  => 'sort_order.asc,id.asc',
            ]
        );

        if ( is_wp_error( $rows ) ) {
            wp_send_json_error( $this->error_message( $rows ), 500 );
        }

        wp_send_json_success( is_array( $rows ) ? $rows : [] );
    }

    public function ajax_cats_save() {
        $this->verify_nonce();

        $id            = absint( $_POST['id'] ?? 0 );
        $internal_name = sanitize_key( $_POST['internal_name'] ?? '' );
        $display_name  = sanitize_text_field( $_POST['display_name'] ?? '' );
        $description   = sanitize_textarea_field( $_POST['description'] ?? '' );
        $ui_color      = sanitize_hex_color( $_POST['ui_color'] ?? '#adff00' ) ?: '#adff00';
        $sort_order    = intval( $_POST['sort_order'] ?? 0 );
        $hud_group_id  = absint( $_POST['hud_group_id'] ?? 0 );

        if ( ! $internal_name || ! $display_name || ! $hud_group_id ) {
            wp_send_json_error( 'Required fields missing.' );
        }

        $payload = [
            'internal_name' => $internal_name,
            'display_name'  => $display_name,
            'description'   => $description ?: null,
            'ui_color'      => $ui_color,
            'sort_order'    => $sort_order,
            'hud_group_id'  => $hud_group_id,
        ];

        if ( $id ) {
            $res = tw_supabase_request(
                'PATCH',
                'cyber_action_tag_categories',
                [ 'id' => 'eq.' . $id ],
                $payload,
                [
                    'headers' => [
                        'Prefer' => 'return=representation',
                    ],
                ]
            );
        } else {
            $res = tw_supabase_request(
                'POST',
                'cyber_action_tag_categories',
                [],
                $payload,
                [
                    'headers' => [
                        'Prefer' => 'return=representation',
                    ],
                ]
            );
        }

        if ( is_wp_error( $res ) ) {
            wp_send_json_error( $this->error_message( $res ), 500 );
        }

        $row = $res['data'][0] ?? [];
        wp_send_json_success( [ 'id' => $row['id'] ?? $id ] );
    }

    public function ajax_cats_delete() {
        $this->verify_nonce();

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'Invalid ID.' );
        }

        $res = tw_supabase_request(
            'DELETE',
            'cyber_action_tag_categories',
            [ 'id' => 'eq.' . $id ],
            null,
            [
                'headers' => [
                    'Prefer' => 'return=representation',
                ],
            ]
        );

        if ( is_wp_error( $res ) ) {
            wp_send_json_error( $this->error_message( $res ), 500 );
        }

        wp_send_json_success();
    }

    public function ajax_cats_duplicate() {
        $this->verify_nonce();

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'Invalid ID.' );
        }

        $rows = tw_supabase_get_admin(
            'cyber_action_tag_categories',
            [
                'select' => 'id,internal_name,display_name,description,ui_color,sort_order,hud_group_id',
                'id'     => 'eq.' . $id,
                'limit'  => 1,
            ]
        );

        if ( is_wp_error( $rows ) || empty( $rows[0] ) ) {
            wp_send_json_error( 'Category not found.' );
        }

        $row  = $rows[0];
        $base = rtrim( $row['internal_name'], '_' );
        $try  = $base . '_copy';
        $i    = 2;

        while ( true ) {
            $exists = tw_supabase_get_admin(
                'cyber_action_tag_categories',
                [
                    'select'        => 'id',
                    'internal_name' => 'eq.' . $try,
                    'limit'         => 1,
                ]
            );

            if ( ! is_wp_error( $exists ) && empty( $exists ) ) {
                break;
            }

            $try = $base . '_copy' . $i;
            $i++;
        }

        $new_row = [
            'internal_name' => $try,
            'display_name'  => $row['display_name'] . ' (copy)',
            'description'   => $row['description'],
            'ui_color'      => $row['ui_color'],
            'sort_order'    => intval( $row['sort_order'] ) + 1,
            'hud_group_id'  => intval( $row['hud_group_id'] ),
        ];

        $insert = tw_supabase_request(
            'POST',
            'cyber_action_tag_categories',
            [],
            $new_row,
            [
                'headers' => [
                    'Prefer' => 'return=representation',
                ],
            ]
        );

        if ( is_wp_error( $insert ) ) {
            wp_send_json_error( $this->error_message( $insert ), 500 );
        }

        wp_send_json_success( [ 'id' => $insert['data'][0]['id'] ?? 0 ] );
    }

    /* ================================================================ */
    /* TAGS AJAX                                                        */
    /* ================================================================ */

    public function ajax_tags_load() {
        $this->verify_nonce();

        $rows = tw_supabase_get_admin(
            'cyber_action_tags',
            [
                'select' => 'id,name,color,sentiment,impact,description,category_id,is_active',
                'order'  => 'name.asc',
            ]
        );

        if ( is_wp_error( $rows ) ) {
            wp_send_json_error( $this->error_message( $rows ), 500 );
        }

        wp_send_json_success( is_array( $rows ) ? $rows : [] );
    }

    public function ajax_tags_save() {
        $this->verify_nonce();

        $id          = absint( $_POST['id'] ?? 0 );
        $name        = sanitize_text_field( $_POST['name'] ?? '' );
        $color       = sanitize_hex_color( $_POST['color'] ?? '#adff00' ) ?: '#adff00';
        $sentiment   = sanitize_text_field( $_POST['sentiment'] ?? 'neutral' );
        $impact      = round( floatval( $_POST['impact'] ?? 0 ), 2 );
        $description = sanitize_textarea_field( $_POST['description'] ?? '' );
        $category_id = absint( $_POST['category_id'] ?? 0 );
        $is_active   = isset( $_POST['is_active'] )
            ? ( filter_var( $_POST['is_active'], FILTER_VALIDATE_BOOLEAN ) || intval( $_POST['is_active'] ) === 1 )
            : true;

        if ( ! $name || ! $category_id ) {
            wp_send_json_error( 'Name and category are required.' );
        }

        $valid_sentiments = [ 'positive', 'negative', 'neutral' ];
        if ( ! in_array( $sentiment, $valid_sentiments, true ) ) {
            $sentiment = 'neutral';
        }

        $payload = [
            'name'        => $name,
            'color'       => $color,
            'sentiment'   => $sentiment,
            'impact'      => $impact,
            'description' => $description ?: null,
            'category_id' => $category_id,
            'is_active'   => (bool) $is_active,
        ];

        if ( $id ) {
            $res = tw_supabase_request(
                'PATCH',
                'cyber_action_tags',
                [ 'id' => 'eq.' . $id ],
                $payload,
                [
                    'headers' => [
                        'Prefer' => 'return=representation',
                    ],
                ]
            );
        } else {
            $res = tw_supabase_request(
                'POST',
                'cyber_action_tags',
                [],
                $payload,
                [
                    'headers' => [
                        'Prefer' => 'return=representation',
                    ],
                ]
            );
        }

        if ( is_wp_error( $res ) ) {
            wp_send_json_error( $this->error_message( $res ), 500 );
        }

        $row = $res['data'][0] ?? [];
        wp_send_json_success( [ 'id' => $row['id'] ?? $id ] );
    }

    public function ajax_tags_delete() {
        $this->verify_nonce();

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'Invalid ID.' );
        }

        $res = tw_supabase_request(
            'DELETE',
            'cyber_action_tags',
            [ 'id' => 'eq.' . $id ],
            null,
            [
                'headers' => [
                    'Prefer' => 'return=representation',
                ],
            ]
        );

        if ( is_wp_error( $res ) ) {
            wp_send_json_error( $this->error_message( $res ), 500 );
        }

        wp_send_json_success();
    }

    public function ajax_tags_duplicate() {
        $this->verify_nonce();

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'Invalid ID.' );
        }

        $rows = tw_supabase_get_admin(
            'cyber_action_tags',
            [
                'select' => 'id,name,color,sentiment,impact,description,category_id,is_active',
                'id'     => 'eq.' . $id,
                'limit'  => 1,
            ]
        );

        if ( is_wp_error( $rows ) || empty( $rows[0] ) ) {
            wp_send_json_error( 'Tag not found.' );
        }

        $row  = $rows[0];
        $base = rtrim( $row['name'], '_' );
        $try  = $base . '_copy';
        $i    = 2;

        while ( true ) {
            $exists = tw_supabase_get_admin(
                'cyber_action_tags',
                [
                    'select' => 'id',
                    'name'   => 'eq.' . $try,
                    'limit'  => 1,
                ]
            );

            if ( ! is_wp_error( $exists ) && empty( $exists ) ) {
                break;
            }

            $try = $base . '_copy' . $i;
            $i++;
        }

        $new_row = [
            'name'        => $try,
            'color'       => $row['color'],
            'sentiment'   => $row['sentiment'],
            'impact'      => $row['impact'],
            'description' => $row['description'],
            'category_id' => intval( $row['category_id'] ),
            'is_active'   => false,
        ];

        $insert = tw_supabase_request(
            'POST',
            'cyber_action_tags',
            [],
            $new_row,
            [
                'headers' => [
                    'Prefer' => 'return=representation',
                ],
            ]
        );

        if ( is_wp_error( $insert ) ) {
            wp_send_json_error( $this->error_message( $insert ), 500 );
        }

        wp_send_json_success( [ 'id' => $insert['data'][0]['id'] ?? 0 ] );
    }
}
