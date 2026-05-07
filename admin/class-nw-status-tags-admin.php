<?php
/**
 * NeoWeaver Admin Panel — Status Tags (cyber_status_tags)
 *
 * Columns: id, label, category, effect_description, mechanic_modifier,
 *          duration, is_stackable, is_debuff, source, color_hex,
 *          is_active
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Status_Tags_Admin {

    private string $supabase_url;
    private string $supabase_key;
    private string $page_slug   = 'neoweaver-status-tags';
    private string $parent_slug = 'neoweaver';

    private array $categories = [ 'Physical', 'Condition', 'Tech', 'Buff', 'Glitch' ];
    private array $durations  = [ 'permanent', 'scene', 'encounter', 'turn', 'custom' ];

    public function __construct() {
        $this->supabase_url = rtrim( tw_supabase_url(), '/' );
        $this->supabase_key = tw_supabase_anon_key();

        add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_nw_st_get_all', [ $this, 'ajax_get_all' ] );
        add_action( 'wp_ajax_nw_st_save',    [ $this, 'ajax_save'    ] );
        add_action( 'wp_ajax_nw_st_toggle',  [ $this, 'ajax_toggle'  ] );
        add_action( 'wp_ajax_nw_st_delete',  [ $this, 'ajax_delete'  ] );
    }

    /* ---------------------------------------------------------------- */
    /*  MENU                                                              */
    /* ---------------------------------------------------------------- */

    public function register_menu(): void {
        add_submenu_page(
            $this->parent_slug,
            'NeoWeaver — Status Tags',
            '🏷 Status Tags',
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
        wp_enqueue_style( 'chakra-petch',
            'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap', [], null );
        wp_add_inline_style( 'chakra-petch', $this->get_css() );
        wp_add_inline_script( 'jquery', $this->get_js() );
    }

    /* ---------------------------------------------------------------- */
    /*  RENDER                                                            */
    /* ---------------------------------------------------------------- */

    public function render_page(): void {
        $cats      = wp_json_encode( $this->categories );
        $durs      = wp_json_encode( $this->durations );
        ?>
        <div class="wrap nw-panel" id="nw-st-panel">

            <div class="nw-panel-header">
                <h1 class="nw-panel-title">
                    <span class="nw-accent">Neo</span>Weaver
                    <span class="nw-panel-subtitle">/ Status Tags</span>
                </h1>
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
                <span class="nw-stat-pill nw-pill-debuff">Debuffs: <strong id="nw-debuffs">—</strong></span>
                <span class="nw-stat-pill nw-pill-buff">Buffs: <strong id="nw-buffs">—</strong></span>
            </div>

            <!-- Filter bar -->
            <div class="nw-filter-bar">
                <select id="nw-filter-category" class="nw-select nw-filter-select">
                    <option value="">All Categories</option>
                    <?php foreach ( $this->categories as $c ) : ?>
                        <option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( $c ); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="nw-filter-duration" class="nw-select nw-filter-select">
                    <option value="">All Durations</option>
                    <?php foreach ( $this->durations as $d ) : ?>
                        <option value="<?php echo esc_attr( $d ); ?>"><?php echo esc_html( $d ); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="nw-filter-type" class="nw-select nw-filter-select">
                    <option value="">Buff & Debuff</option>
                    <option value="debuff">Debuff only</option>
                    <option value="buff">Buff only</option>
                </select>
                <input type="text" id="nw-filter-search" class="nw-filter-input" placeholder="Search label / source…">
            </div>

            <div class="nw-table-wrap">
                <table class="nw-table">
                    <thead><tr>
                        <th>Color</th>
                        <th>Label</th>
                        <th>Category</th>
                        <th>Duration</th>
                        <th>Type</th>
                        <th>Stackable</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody id="nw-st-tbody">
                        <tr class="nw-loading-row"><td colspan="8"><div class="nw-spinner"></div> Loading tags…</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- ==================== MODAL ==================== -->
            <div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
                <div class="nw-modal">
                    <div class="nw-modal-header">
                        <h2 id="nw-modal-title">Edit Status Tag</h2>
                        <button class="nw-modal-close" id="nw-modal-close">✕</button>
                    </div>
                    <div class="nw-modal-body">
                        <form id="nw-st-form">
                            <input type="hidden" id="nw-field-id" name="id">

                            <!-- Identity -->
                            <div class="nw-section-label">Identity</div>
                            <div class="nw-form-grid">
                                <div class="nw-field">
                                    <label>Label <span class="nw-req">*</span></label>
                                    <input type="text" id="nw-field-label" name="label" required placeholder="e.g. Stunned">
                                </div>
                                <div class="nw-field">
                                    <label>Color</label>
                                    <div class="nw-color-wrap">
                                        <input type="color" id="nw-field-color_hex" name="color_hex" value="#ff0000">
                                        <input type="text"  id="nw-field-color_hex_text" placeholder="#ff0000" maxlength="7">
                                    </div>
                                </div>
                                <div class="nw-field">
                                    <label>Category</label>
                                    <select id="nw-field-category" name="category" class="nw-select">
                                        <option value="">— none —</option>
                                        <?php foreach ( $this->categories as $c ) : ?>
                                            <option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( $c ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="nw-field">
                                    <label>Duration</label>
                                    <select id="nw-field-duration" name="duration" class="nw-select">
                                        <?php foreach ( $this->durations as $d ) : ?>
                                            <option value="<?php echo esc_attr( $d ); ?>"><?php echo esc_html( $d ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="nw-field">
                                    <label>Source <span class="nw-hint">(item, ability, environment…)</span></label>
                                    <input type="text" id="nw-field-source" name="source" placeholder="e.g. Neural Disruptor">
                                </div>
                            </div>

                            <!-- Effects -->
                            <div class="nw-section-label">Effects</div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-full">
                                    <label>Effect Description</label>
                                    <textarea id="nw-field-effect_description" name="effect_description" rows="3" placeholder="What happens when this tag is applied…"></textarea>
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Mechanic Modifier <span class="nw-hint">(e.g. -2 DEF, skip turn, +1 ATK)</span></label>
                                    <input type="text" id="nw-field-mechanic_modifier" name="mechanic_modifier" placeholder="e.g. -2 to all defense rolls">
                                </div>
                            </div>

                            <!-- Flags -->
                            <div class="nw-section-label">Flags</div>
                            <div class="nw-form-grid nw-flags-grid">
                                <div class="nw-field nw-field-center">
                                    <label>Is Debuff</label>
                                    <label class="nw-toggle">
                                        <input type="checkbox" id="nw-field-is_debuff" name="is_debuff">
                                        <span class="nw-toggle-slider nw-toggle-debuff"></span>
                                    </label>
                                </div>
                                <div class="nw-field nw-field-center">
                                    <label>Stackable</label>
                                    <label class="nw-toggle">
                                        <input type="checkbox" id="nw-field-is_stackable" name="is_stackable">
                                        <span class="nw-toggle-slider"></span>
                                    </label>
                                </div>
                                <div class="nw-field nw-field-center">
                                    <label>Active</label>
                                    <label class="nw-toggle">
                                        <input type="checkbox" id="nw-field-is_active" name="is_active">
                                        <span class="nw-toggle-slider"></span>
                                    </label>
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
            </div><!-- .nw-modal-overlay -->

            <input type="hidden" id="nw-nonce" value="<?php echo esc_attr( wp_create_nonce( 'neoweaver_st' ) ); ?>">
        </div>
        <script>
        var NW_ST_CATS  = <?php echo $cats; ?>;
        var NW_ST_DURS  = <?php echo $durs; ?>;
        </script>
    <?php }

    /* ---------------------------------------------------------------- */
    /*  SUPABASE                                                          */
    /* ---------------------------------------------------------------- */

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

    /* ---------------------------------------------------------------- */
    /*  AJAX: GET ALL                                                     */
    /* ---------------------------------------------------------------- */

    public function ajax_get_all(): void {
        check_ajax_referer( 'neoweaver_st', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $qs = 'cyber_status_tags?select=id,label,category,effect_description,mechanic_modifier,duration,is_stackable,is_debuff,source,color_hex,is_active&order=label.asc';
        $res = $this->supa( 'GET', $qs );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( $res['data'] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: SAVE                                                        */
    /* ---------------------------------------------------------------- */

    public function ajax_save(): void {
        check_ajax_referer( 'neoweaver_st', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $raw = $_POST['tag'] ?? [];
        $id  = sanitize_text_field( $raw['id'] ?? '' );

        $valid_cats = $this->categories;
        $valid_durs = $this->durations;

        $category = sanitize_text_field( $raw['category'] ?? '' );
        $duration = sanitize_text_field( $raw['duration']  ?? 'scene' );
        $color    = sanitize_text_field( $raw['color_hex'] ?? '#ff0000' );

        $payload = [
            'label'               => sanitize_text_field(     $raw['label']              ?? '' ),
            'category'            => in_array( $category, $valid_cats, true ) ? $category : null,
            'effect_description'  => sanitize_textarea_field( $raw['effect_description'] ?? '' ) ?: null,
            'mechanic_modifier'   => sanitize_text_field(     $raw['mechanic_modifier']  ?? '' ) ?: null,
            'duration'            => in_array( $duration, $valid_durs, true ) ? $duration : 'scene',
            'is_stackable'        => filter_var( $raw['is_stackable'] ?? false, FILTER_VALIDATE_BOOLEAN ),
            'is_debuff'           => filter_var( $raw['is_debuff']    ?? true,  FILTER_VALIDATE_BOOLEAN ),
            'is_active'           => filter_var( $raw['is_active']    ?? true,  FILTER_VALIDATE_BOOLEAN ),
            'source'              => sanitize_text_field( $raw['source'] ?? '' ) ?: null,
            'color_hex'           => preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ? $color : '#ff0000',
        ];

        $res = $id
            ? $this->supa( 'PATCH', 'cyber_status_tags?id=eq.' . (int) $id, $payload )
            : $this->supa( 'POST',  'cyber_status_tags', $payload );

        if ( isset( $res['error'] ) ) { wp_send_json_error( $res['error'] ); }
        $code = $res['code'] ?? 0;
        ( $code >= 200 && $code < 300 )
            ? wp_send_json_success( $res['data'][0] ?? $res['data'] )
            : wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: TOGGLE is_active                                            */
    /* ---------------------------------------------------------------- */

    public function ajax_toggle(): void {
        check_ajax_referer( 'neoweaver_st', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $id    = (int) ( $_POST['tag_id'] ?? 0 );
        $state = filter_var( $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );
        $res = $this->supa( 'PATCH', 'cyber_status_tags?id=eq.' . $id, [ 'is_active' => $state ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( [ 'is_active' => $state ] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: DELETE                                                      */
    /* ---------------------------------------------------------------- */

    public function ajax_delete(): void {
        check_ajax_referer( 'neoweaver_st', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $id = (int) ( $_POST['tag_id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );
        $res = $this->supa( 'DELETE', 'cyber_status_tags?id=eq.' . $id, [], [ 'Prefer' => '' ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( 'deleted' );
    }

    /* ---------------------------------------------------------------- */
    /*  CSS                                                               */
    /* ---------------------------------------------------------------- */

    private function get_css(): string { return <<<'CSS'
.nw-panel{font-family:'Chakra Petch',monospace;color:#e0e0e0}.nw-panel *{box-sizing:border-box}
.nw-panel-header{display:flex;align-items:center;justify-content:space-between;padding:20px 0 16px;border-bottom:1px solid #2a2a2a;margin-bottom:16px}
.nw-panel-title{font-size:22px;font-weight:700;color:#fff;margin:0;font-family:'Chakra Petch',monospace}
.nw-accent{color:#adff00}.nw-panel-subtitle{color:#555;font-weight:400;font-size:18px;margin-left:4px}
.nw-header-actions{display:flex;align-items:center;gap:8px}
.nw-btn{font-family:'Chakra Petch',monospace;font-size:12px;font-weight:600;padding:7px 16px;border-radius:5px;border:1px solid transparent;cursor:pointer;transition:all .15s;text-transform:uppercase;letter-spacing:.5px}
.nw-btn-primary{background:#adff00;color:#0a0a0a;border-color:#adff00}.nw-btn-primary:hover{background:#c8ff40}
.nw-btn-ghost{background:transparent;color:#adff00;border-color:#2e2e2e}.nw-btn-ghost:hover{border-color:#adff00}
.nw-btn-danger{background:transparent;color:#ff4444;border-color:#3a1111}.nw-btn-danger:hover{background:#2a0000;border-color:#ff4444}
.nw-stats-bar{display:flex;gap:10px;margin-bottom:12px;flex-wrap:wrap}
.nw-stat-pill{font-size:12px;padding:4px 12px;border-radius:20px;background:#1a1a1a;border:1px solid #2e2e2e;color:#aaa}
.nw-stat-pill strong{color:#fff}.nw-pill-active{border-color:#adff00}.nw-pill-active strong{color:#adff00}
.nw-pill-inactive strong{color:#ff6b35}.nw-pill-debuff strong{color:#ff4444}.nw-pill-buff strong{color:#4af}
.nw-filter-bar{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.nw-filter-select{min-width:140px}.nw-filter-input{background:#0d0d0d;border:1px solid #2a2a2a;border-radius:5px;color:#e0e0e0;padding:7px 10px;font-family:'Chakra Petch',monospace;font-size:12px;min-width:200px}
.nw-filter-input:focus{outline:none;border-color:#adff00}
.nw-notice{padding:10px 16px;border-radius:6px;margin-bottom:14px;font-size:13px;border-left:3px solid}
.nw-notice-success{background:#0a2800;border-color:#adff00;color:#adff00}.nw-notice-error{background:#2a0000;border-color:#ff4444;color:#ff4444}
.nw-table-wrap{background:#111;border:1px solid #222;border-radius:8px;overflow:hidden}
.nw-table{width:100%;border-collapse:collapse;font-size:13px}
.nw-table thead tr{background:#1a1a1a;border-bottom:1px solid #2a2a2a}
.nw-table th{padding:10px 14px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#666;font-weight:600}
.nw-table tbody tr{border-bottom:1px solid #1e1e1e;transition:background .12s}
.nw-table tbody tr:last-child{border-bottom:none}.nw-table tbody tr:hover{background:#161616}
.nw-table td{padding:10px 14px;vertical-align:middle}
.nw-color-dot{width:22px;height:22px;border-radius:50%;border:2px solid rgba(255,255,255,.15);display:inline-block;vertical-align:middle}
.nw-label-wrap{display:flex;align-items:center;gap:8px}
.nw-label-text{font-weight:600;color:#fff}
.nw-badge{font-size:10px;padding:2px 8px;border-radius:3px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;border:1px solid}
.nw-badge-Physical{color:#ff9944;border-color:#4a2a00;background:#1e1000}
.nw-badge-Condition{color:#c88fff;border-color:#3a1a5e;background:#1a0a2e}
.nw-badge-Tech{color:#44aaff;border-color:#0a2a4a;background:#021020}
.nw-badge-Buff{color:#adff00;border-color:#2e4400;background:#0a1800}
.nw-badge-Glitch{color:#ff4488;border-color:#4a0a22;background:#1e0010}
.nw-dur-chip{font-size:10px;padding:2px 7px;background:#1e1e1e;border:1px solid #2e2e2e;border-radius:3px;color:#888;text-transform:uppercase;letter-spacing:.5px}
.nw-type-chip{font-size:10px;padding:2px 7px;border-radius:3px;font-weight:700;text-transform:uppercase;letter-spacing:.5px}
.nw-type-debuff{background:#2a0000;border:1px solid #5a1111;color:#ff4444}
.nw-type-buff{background:#001020;border:1px solid #114466;color:#44aaff}
.nw-toggle{position:relative;display:inline-block;width:40px;height:22px}
.nw-toggle input{opacity:0;width:0;height:0}
.nw-toggle-slider{position:absolute;inset:0;background:#2a2a2a;border-radius:22px;cursor:pointer;transition:background .2s;border:1px solid #3a3a3a}
.nw-toggle-slider::before{content:'';position:absolute;width:16px;height:16px;left:2px;top:2px;background:#555;border-radius:50%;transition:all .2s}
.nw-toggle input:checked+.nw-toggle-slider{background:#1a3300;border-color:#adff00}
.nw-toggle input:checked+.nw-toggle-slider::before{background:#adff00;transform:translateX(18px)}
.nw-toggle input:checked+.nw-toggle-debuff{background:#2a0000;border-color:#ff4444}
.nw-toggle input:checked+.nw-toggle-debuff::before{background:#ff4444;transform:translateX(18px)}
.nw-row-actions{display:flex;gap:6px}
.nw-action-btn{font-family:'Chakra Petch',monospace;font-size:11px;padding:4px 10px;border-radius:4px;border:1px solid #2e2e2e;background:transparent;color:#aaa;cursor:pointer;transition:all .15s;text-transform:uppercase}
.nw-action-btn:hover{border-color:#adff00;color:#adff00}
.nw-loading-row td{text-align:center;padding:32px;color:#555}
.nw-spinner{display:inline-block;width:16px;height:16px;border:2px solid #333;border-top-color:#adff00;border-radius:50%;animation:nw-spin .6s linear infinite;vertical-align:middle;margin-right:8px}
@keyframes nw-spin{to{transform:rotate(360deg)}}
.nw-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:99998;display:flex;align-items:center;justify-content:center;padding:20px}
.nw-modal{background:#111;border:1px solid #2e2e2e;border-radius:10px;width:100%;max-width:680px;max-height:92vh;overflow-y:auto;display:flex;flex-direction:column;font-family:'Chakra Petch',monospace}
.nw-modal-header{display:flex;align-items:center;justify-content:space-between;padding:18px 24px 14px;border-bottom:1px solid #1e1e1e;position:sticky;top:0;background:#111;z-index:1}
.nw-modal-header h2{margin:0;font-size:16px;color:#fff;font-family:'Chakra Petch',monospace}
.nw-modal-close{background:none;border:none;color:#666;font-size:18px;cursor:pointer;padding:2px 6px;border-radius:4px}
.nw-modal-close:hover{color:#fff;background:#222}
.nw-modal-body{padding:20px 24px;flex:1}
.nw-modal-footer{padding:14px 24px;border-top:1px solid #1e1e1e;display:flex;justify-content:flex-end;align-items:center;gap:10px;position:sticky;bottom:0;background:#111}
.nw-section-label{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#adff00;font-weight:700;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #1e2e00}
.nw-section-label:first-child{margin-top:0}
.nw-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.nw-flags-grid{grid-template-columns:repeat(3,1fr)}
.nw-field{display:flex;flex-direction:column;gap:5px}.nw-field-full{grid-column:1/-1}.nw-field-center{align-items:flex-start}
.nw-field label{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#666;font-weight:600}
.nw-req{color:#ff4444}.nw-hint{font-size:10px;color:#444;text-transform:none;letter-spacing:0;font-weight:400}
.nw-field input[type="text"],.nw-field textarea,.nw-select{background:#0d0d0d;border:1px solid #2a2a2a;border-radius:5px;color:#e0e0e0;padding:8px 10px;font-family:'Chakra Petch',monospace;font-size:13px;transition:border-color .15s;width:100%}
.nw-field input:focus,.nw-field textarea:focus,.nw-select:focus{outline:none;border-color:#adff00;box-shadow:0 0 0 2px rgba(173,255,0,.08)}
.nw-field textarea{resize:vertical}
.nw-select option{background:#111}
.nw-color-wrap{display:flex;gap:8px;align-items:center}
.nw-color-wrap input[type="color"]{width:44px;height:36px;padding:2px;background:#0d0d0d;border:1px solid #2a2a2a;border-radius:5px;cursor:pointer}
.nw-color-wrap input[type="text"]{flex:1}
CSS;
    }

    /* ---------------------------------------------------------------- */
    /*  JS                                                                */
    /* ---------------------------------------------------------------- */

    private function get_js(): string { return <<<'JS'
jQuery(function($){
    var nonce  = $('#nw-nonce').val();
    var editId = null;
    var allTags = [];

    /* -------- helpers -------- */
    function escH(s){return $('<div>').text(String(s||'')).html();}
    function badgeHtml(cat){
        if(!cat) return '<span style="color:#333">—</span>';
        return '<span class="nw-badge nw-badge-'+escH(cat)+'">'+escH(cat)+'</span>';
    }

    /* -------- load -------- */
    function loadTags(){
        $('#nw-st-tbody').html('<tr class="nw-loading-row"><td colspan="8"><div class="nw-spinner"></div> Loading tags…</td></tr>');
        $.post(ajaxurl,{action:'nw_st_get_all',nonce:nonce},function(r){
            if(!r.success){showNotice('error',r.data);return;}
            allTags=r.data||[];
            renderFiltered();
        });
    }

    function renderFiltered(){
        var catF=$('#nw-filter-category').val();
        var durF=$('#nw-filter-duration').val();
        var typeF=$('#nw-filter-type').val();
        var search=$('#nw-filter-search').val().toLowerCase();
        var rows=allTags.filter(function(t){
            if(catF&&t.category!==catF) return false;
            if(durF&&t.duration!==durF) return false;
            if(typeF==='debuff'&&!t.is_debuff) return false;
            if(typeF==='buff'&&t.is_debuff) return false;
            if(search&&!(t.label.toLowerCase().includes(search)||(t.source||'').toLowerCase().includes(search))) return false;
            return true;
        });
        renderTable(rows);
    }

    function renderTable(rows){
        var total=allTags.length,active=0,inactive=0,debuffs=0,buffs=0,html='';
        $.each(allTags,function(_,t){
            if(t.is_active) active++; else inactive++;
            if(t.is_debuff) debuffs++; else buffs++;
        });
        if(!rows.length){html='<tr><td colspan="8" style="text-align:center;padding:32px;color:#555;">No tags found.</td></tr>';}
        $.each(rows,function(_,t){
            var typeChip=t.is_debuff
                ?'<span class="nw-type-chip nw-type-debuff">Debuff</span>'
                :'<span class="nw-type-chip nw-type-buff">Buff</span>';
            html+='<tr data-id="'+escH(t.id)+'">'
                +'<td><span class="nw-color-dot" style="background:'+escH(t.color_hex||'#ff0000')+';" title="'+escH(t.color_hex)+'"></span></td>'
                +'<td><div class="nw-label-wrap"><span class="nw-label-text">'+escH(t.label)+'</span>'+(t.source?'<span style="font-size:10px;color:#555;">'+escH(t.source)+'</span>':'')+'</div>'+(t.effect_description?'<div style="font-size:11px;color:#555;margin-top:2px;">'+escH(t.effect_description.substring(0,60))+(t.effect_description.length>60?'…':'')+'</div>':'')+'</td>'
                +'<td>'+badgeHtml(t.category)+'</td>'
                +'<td><span class="nw-dur-chip">'+escH(t.duration)+'</span></td>'
                +'<td>'+typeChip+'</td>'
                +'<td>'+(t.is_stackable?'<span style="color:#adff00;font-size:11px;">✓ Yes</span>':'<span style="color:#333;font-size:11px;">No</span>')+'</td>'
                +'<td><label class="nw-toggle"><input type="checkbox" class="nw-toggle-active" data-id="'+escH(t.id)+'"'+(t.is_active?' checked':'')+'><span class="nw-toggle-slider"></span></label></td>'
                +'<td><div class="nw-row-actions"><button class="nw-action-btn nw-edit-btn" data-id="'+escH(t.id)+'">Edit</button></div></td>'
                +'</tr>';
        });
        $('#nw-st-tbody').html(html);
        $('#nw-total').text(total);$('#nw-active').text(active);$('#nw-inactive').text(inactive);
        $('#nw-debuffs').text(debuffs);$('#nw-buffs').text(buffs);
    }

    /* -------- filters -------- */
    $('#nw-filter-category,#nw-filter-duration,#nw-filter-type').on('change',renderFiltered);
    $('#nw-filter-search').on('input',renderFiltered);

    /* -------- modal -------- */
    function openModal(tag){
        editId=tag?tag.id:null;
        $('#nw-modal-title').text(tag?'Edit Status Tag':'New Status Tag');
        $('#nw-save-label').text(tag?'Save Tag':'Create Tag');
        $('#nw-delete-btn').toggle(!!tag);
        $('#nw-field-id').val(tag?tag.id:'');
        $('#nw-field-label').val(tag?tag.label:'');
        $('#nw-field-category').val(tag?tag.category||'':'');
        $('#nw-field-duration').val(tag?tag.duration:'scene');
        $('#nw-field-source').val(tag?tag.source||'':'');
        $('#nw-field-effect_description').val(tag?tag.effect_description||'':'');
        $('#nw-field-mechanic_modifier').val(tag?tag.mechanic_modifier||'':'');
        var col=tag?tag.color_hex||'#ff0000':'#ff0000';
        $('#nw-field-color_hex').val(col);
        $('#nw-field-color_hex_text').val(col);
        $('#nw-field-is_debuff').prop('checked',tag?tag.is_debuff:true);
        $('#nw-field-is_stackable').prop('checked',tag?tag.is_stackable:false);
        $('#nw-field-is_active').prop('checked',tag?tag.is_active:true);
        $('#nw-modal-overlay').show();
    }
    function closeModal(){ $('#nw-modal-overlay').hide(); editId=null; }

    /* color sync */
    $('#nw-field-color_hex').on('input',function(){$('#nw-field-color_hex_text').val($(this).val());});
    $('#nw-field-color_hex_text').on('input',function(){
        var v=$(this).val().trim();
        if(/^#[0-9a-fA-F]{6}$/.test(v)) $('#nw-field-color_hex').val(v);
    });

    /* -------- save -------- */
    function saveTag(){
        var data={action:'nw_st_save',nonce:nonce,tag:{}};
        $('#nw-st-form').serializeArray().forEach(function(f){data.tag[f.name]=f.value;});
        data.tag.is_debuff=$('#nw-field-is_debuff').is(':checked')?'1':'0';
        data.tag.is_stackable=$('#nw-field-is_stackable').is(':checked')?'1':'0';
        data.tag.is_active=$('#nw-field-is_active').is(':checked')?'1':'0';
        data.tag.color_hex=$('#nw-field-color_hex').val();
        $('#nw-save-btn').prop('disabled',true).text('Saving…');
        $.post(ajaxurl,data,function(r){
            $('#nw-save-btn').prop('disabled',false);
            $('#nw-save-label').text(editId?'Save Tag':'Create Tag');
            if(!r.success){showNotice('error',r.data);return;}
            showNotice('success',editId?'Tag updated.':'Tag created.');
            closeModal(); loadTags();
        });
    }

    /* -------- toggle active -------- */
    $(document).on('change','.nw-toggle-active',function(){
        var id=$(this).data('id'), state=$(this).is(':checked');
        $.post(ajaxurl,{action:'nw_st_toggle',nonce:nonce,tag_id:id,is_active:state?1:0},function(r){
            if(!r.success){showNotice('error',r.data);loadTags();}
        });
    });

    /* -------- delete -------- */
    $('#nw-delete-btn').on('click',function(){
        if(!editId||!confirm('Delete this status tag? This cannot be undone.')) return;
        $.post(ajaxurl,{action:'nw_st_delete',nonce:nonce,tag_id:editId},function(r){
            if(!r.success){showNotice('error',r.data);return;}
            showNotice('success','Tag deleted.');
            closeModal(); loadTags();
        });
    });

    /* -------- events -------- */
    $('#nw-add-btn').on('click',function(){openModal(null);});
    $('#nw-refresh-btn').on('click',loadTags);
    $('#nw-modal-close,#nw-cancel-btn').on('click',closeModal);
    $('#nw-modal-overlay').on('click',function(e){if($(e.target).is('#nw-modal-overlay'))closeModal();});
    $('#nw-save-btn').on('click',saveTag);
    $(document).on('click','.nw-edit-btn',function(){
        var id=$(this).data('id');
        var tag=null; $.each(allTags,function(_,t){if(String(t.id)===String(id)){tag=t;return false;}});
        if(tag) openModal(tag);
    });

    function showNotice(type,msg){
        var $n=$('#nw-notice');
        $n.removeClass('nw-notice-success nw-notice-error').addClass('nw-notice-'+type).text(msg).show();
        setTimeout(function(){$n.fadeOut();},4000);
    }

    loadTags();
});
JS;
    }
}

new NeoWeaver_Status_Tags_Admin();
