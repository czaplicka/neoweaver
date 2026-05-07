<?php
/**
 * NeoWeaver Admin Panel — Starting Packages (cyber_starting_packages)
 *
 * Columns: id, package_name, description, items_list, compatibility_tags,
 *          attack_cards_pool, defense_cards_pool, base_armor,
 *          is_player_selectable, head_item_id, torso_item_id,
 *          hand_r_item_id, hand_l_item_id, belt_item_id,
 *          compatible_class_ids, created_at
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Starting_Packages_Admin {

    private string $supabase_url;
    private string $supabase_key;
    private string $page_slug   = 'neoweaver-starting-packages';
    private string $parent_slug = 'neoweaver';

    public function __construct() {
        $this->supabase_url = rtrim( tw_supabase_url(), '/' );
        $this->supabase_key = tw_supabase_anon_key();

        add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_nw_sp_get_all',    [ $this, 'ajax_get_all'    ] );
        add_action( 'wp_ajax_nw_sp_get_items',  [ $this, 'ajax_get_items'  ] );
        add_action( 'wp_ajax_nw_sp_save',       [ $this, 'ajax_save'       ] );
        add_action( 'wp_ajax_nw_sp_toggle',     [ $this, 'ajax_toggle'     ] );
        add_action( 'wp_ajax_nw_sp_delete',     [ $this, 'ajax_delete'     ] );
    }

    /* ---------------------------------------------------------------- */
    /*  MENU                                                              */
    /* ---------------------------------------------------------------- */

    public function register_menu(): void {
        add_submenu_page(
            $this->parent_slug,
            'NeoWeaver — Starting Packages',
            '🎒 Starting Packages',
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

    public function render_page(): void { ?>
        <div class="wrap nw-panel" id="nw-sp-panel">

            <div class="nw-panel-header">
                <h1 class="nw-panel-title">
                    <span class="nw-accent">Neo</span>Weaver
                    <span class="nw-panel-subtitle">/ Starting Packages</span>
                </h1>
                <div class="nw-header-actions">
                    <button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">↻ Refresh</button>
                    <button class="nw-btn nw-btn-primary" id="nw-add-btn">+ New Package</button>
                </div>
            </div>

            <div id="nw-notice" class="nw-notice" style="display:none;"></div>

            <div class="nw-stats-bar">
                <span class="nw-stat-pill">Total: <strong id="nw-total">—</strong></span>
                <span class="nw-stat-pill nw-pill-active">Player Selectable: <strong id="nw-selectable">—</strong></span>
                <span class="nw-stat-pill nw-pill-inactive">Hidden: <strong id="nw-hidden">—</strong></span>
            </div>

            <div class="nw-table-wrap">
                <table class="nw-table">
                    <thead><tr>
                        <th>Package Name</th>
                        <th>Base Armor</th>
                        <th>Slots</th>
                        <th>Compat. Tags</th>
                        <th>Class IDs</th>
                        <th>Player Selectable</th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody id="nw-sp-tbody">
                        <tr class="nw-loading-row"><td colspan="7"><div class="nw-spinner"></div> Loading packages…</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- ==================== MODAL ==================== -->
            <div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
                <div class="nw-modal">
                    <div class="nw-modal-header">
                        <h2 id="nw-modal-title">Edit Package</h2>
                        <button class="nw-modal-close" id="nw-modal-close">✕</button>
                    </div>
                    <div class="nw-modal-body">
                        <form id="nw-sp-form">
                            <input type="hidden" id="nw-field-id" name="id">

                            <!-- Identity -->
                            <div class="nw-section-label">Identity</div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-full">
                                    <label>Package Name <span class="nw-req">*</span></label>
                                    <input type="text" id="nw-field-package_name" name="package_name" required placeholder="e.g. Street Runner Starter">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Description</label>
                                    <textarea id="nw-field-description" name="description" rows="3" placeholder="Brief description of this starting package…"></textarea>
                                </div>
                            </div>

                            <!-- Equipment Slots -->
                            <div class="nw-section-label">Equipment Slots <span class="nw-hint">(pick from cyber_items)</span></div>
                            <div class="nw-form-grid">
                                <div class="nw-field">
                                    <label>Head Slot</label>
                                    <select id="nw-field-head_item_id" name="head_item_id" class="nw-select nw-item-select">
                                        <option value="">— none —</option>
                                    </select>
                                </div>
                                <div class="nw-field">
                                    <label>Torso Slot</label>
                                    <select id="nw-field-torso_item_id" name="torso_item_id" class="nw-select nw-item-select">
                                        <option value="">— none —</option>
                                    </select>
                                </div>
                                <div class="nw-field">
                                    <label>Right Hand Slot</label>
                                    <select id="nw-field-hand_r_item_id" name="hand_r_item_id" class="nw-select nw-item-select">
                                        <option value="">— none —</option>
                                    </select>
                                </div>
                                <div class="nw-field">
                                    <label>Left Hand Slot</label>
                                    <select id="nw-field-hand_l_item_id" name="hand_l_item_id" class="nw-select nw-item-select">
                                        <option value="">— none —</option>
                                    </select>
                                </div>
                                <div class="nw-field">
                                    <label>Belt Slot</label>
                                    <select id="nw-field-belt_item_id" name="belt_item_id" class="nw-select nw-item-select">
                                        <option value="">— none —</option>
                                    </select>
                                </div>
                                <div class="nw-field">
                                    <label>Base Armor <span class="nw-hint">(≥ 0)</span></label>
                                    <input type="number" id="nw-field-base_armor" name="base_armor" min="0" value="0">
                                </div>
                            </div>

                            <!-- JSON Pools -->
                            <div class="nw-section-label">Card Pools &amp; Lists <span class="nw-hint">(comma-separated → JSON array)</span></div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-full">
                                    <label>Items List <span class="nw-hint">(additional item IDs / names beyond slots)</span></label>
                                    <input type="text" id="nw-field-items_list" name="items_list" placeholder="item-uuid-1, item-uuid-2">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Attack Cards Pool</label>
                                    <input type="text" id="nw-field-attack_cards_pool" name="attack_cards_pool" placeholder="card-id-1, card-id-2">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Defense Cards Pool</label>
                                    <input type="text" id="nw-field-defense_cards_pool" name="defense_cards_pool" placeholder="card-id-1, card-id-2">
                                </div>
                            </div>

                            <!-- Compatibility -->
                            <div class="nw-section-label">Compatibility <span class="nw-hint">(comma-separated → JSON array)</span></div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-full">
                                    <label>Compatibility Tags</label>
                                    <input type="text" id="nw-field-compatibility_tags" name="compatibility_tags" placeholder="e.g. melee, urban, stealth">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Compatible Class IDs</label>
                                    <input type="text" id="nw-field-compatible_class_ids" name="compatible_class_ids" placeholder="class-uuid-1, class-uuid-2">
                                </div>
                            </div>

                            <!-- Visibility -->
                            <div class="nw-section-label">Visibility</div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-center">
                                    <label>Player Selectable (visible on character creation)</label>
                                    <label class="nw-toggle">
                                        <input type="checkbox" id="nw-field-is_player_selectable" name="is_player_selectable">
                                        <span class="nw-toggle-slider"></span>
                                    </label>
                                </div>
                            </div>

                        </form>
                    </div><!-- .nw-modal-body -->
                    <div class="nw-modal-footer">
                        <button class="nw-btn nw-btn-danger" id="nw-delete-btn" style="display:none;margin-right:auto;">🗑 Delete</button>
                        <button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
                        <button class="nw-btn nw-btn-primary" id="nw-save-btn"><span id="nw-save-label">Save Package</span></button>
                    </div>
                </div>
            </div><!-- .nw-modal-overlay -->

            <input type="hidden" id="nw-nonce" value="<?php echo esc_attr( wp_create_nonce( 'neoweaver_sp' ) ); ?>">
        </div>
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
    /*  AJAX: GET ALL PACKAGES                                            */
    /* ---------------------------------------------------------------- */

    public function ajax_get_all(): void {
        check_ajax_referer( 'neoweaver_sp', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $qs = 'cyber_starting_packages?select=id,package_name,description,items_list,compatibility_tags,attack_cards_pool,defense_cards_pool,base_armor,is_player_selectable,head_item_id,torso_item_id,hand_r_item_id,hand_l_item_id,belt_item_id,compatible_class_ids,created_at&order=package_name.asc';
        $res = $this->supa( 'GET', $qs );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( $res['data'] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: GET ITEMS (for slot selects)                                */
    /* ---------------------------------------------------------------- */

    public function ajax_get_items(): void {
        check_ajax_referer( 'neoweaver_sp', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $res = $this->supa( 'GET', 'cyber_items?select=id,name,slot,type&order=name.asc&is_active=eq.true' );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( $res['data'] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: SAVE                                                        */
    /* ---------------------------------------------------------------- */

    public function ajax_save(): void {
        check_ajax_referer( 'neoweaver_sp', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $raw = $_POST['pkg'] ?? [];
        $id  = sanitize_text_field( $raw['id'] ?? '' );

        $csv_to_arr = function( string $val ): array {
            return array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $val ) ) ) ) );
        };
        $uuid_or_null = function( string $val ): ?string {
            $v = sanitize_text_field( $val );
            return ( $v && preg_match( '/^[0-9a-f\-]{36}$/i', $v ) ) ? $v : null;
        };

        $payload = [
            'package_name'         => sanitize_text_field(     $raw['package_name']  ?? '' ),
            'description'          => sanitize_textarea_field( $raw['description']   ?? '' ) ?: null,
            'base_armor'           => max( 0, (int) ( $raw['base_armor'] ?? 0 ) ),
            'is_player_selectable' => filter_var( $raw['is_player_selectable'] ?? false, FILTER_VALIDATE_BOOLEAN ),
            'head_item_id'         => $uuid_or_null( $raw['head_item_id']  ?? '' ),
            'torso_item_id'        => $uuid_or_null( $raw['torso_item_id'] ?? '' ),
            'hand_r_item_id'       => $uuid_or_null( $raw['hand_r_item_id'] ?? '' ),
            'hand_l_item_id'       => $uuid_or_null( $raw['hand_l_item_id'] ?? '' ),
            'belt_item_id'         => $uuid_or_null( $raw['belt_item_id']  ?? '' ),
            'items_list'           => $csv_to_arr( $raw['items_list']           ?? '' ),
            'attack_cards_pool'    => $csv_to_arr( $raw['attack_cards_pool']    ?? '' ),
            'defense_cards_pool'   => $csv_to_arr( $raw['defense_cards_pool']   ?? '' ),
            'compatibility_tags'   => $csv_to_arr( $raw['compatibility_tags']   ?? '' ),
            'compatible_class_ids' => $csv_to_arr( $raw['compatible_class_ids'] ?? '' ),
        ];

        $res = $id
            ? $this->supa( 'PATCH', 'cyber_starting_packages?id=eq.' . urlencode( $id ), $payload )
            : $this->supa( 'POST',  'cyber_starting_packages', $payload );

        if ( isset( $res['error'] ) ) { wp_send_json_error( $res['error'] ); }
        $code = $res['code'] ?? 0;
        ( $code >= 200 && $code < 300 )
            ? wp_send_json_success( $res['data'][0] ?? $res['data'] )
            : wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: TOGGLE player_selectable                                    */
    /* ---------------------------------------------------------------- */

    public function ajax_toggle(): void {
        check_ajax_referer( 'neoweaver_sp', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $id    = sanitize_text_field( $_POST['pkg_id']   ?? '' );
        $state = filter_var(           $_POST['is_player_selectable'] ?? false, FILTER_VALIDATE_BOOLEAN );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );
        $res = $this->supa( 'PATCH', 'cyber_starting_packages?id=eq.' . urlencode( $id ), [ 'is_player_selectable' => $state ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( [ 'is_player_selectable' => $state ] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: DELETE                                                      */
    /* ---------------------------------------------------------------- */

    public function ajax_delete(): void {
        check_ajax_referer( 'neoweaver_sp', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $id = sanitize_text_field( $_POST['pkg_id'] ?? '' );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );
        $res = $this->supa( 'DELETE', 'cyber_starting_packages?id=eq.' . urlencode( $id ), [], [ 'Prefer' => '' ] );
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
.nw-stats-bar{display:flex;gap:10px;margin-bottom:16px}
.nw-stat-pill{font-size:12px;padding:4px 12px;border-radius:20px;background:#1a1a1a;border:1px solid #2e2e2e;color:#aaa}
.nw-stat-pill strong{color:#fff}.nw-pill-active{border-color:#adff00}.nw-pill-active strong{color:#adff00}.nw-pill-inactive strong{color:#ff6b35}
.nw-notice{padding:10px 16px;border-radius:6px;margin-bottom:14px;font-size:13px;border-left:3px solid}
.nw-notice-success{background:#0a2800;border-color:#adff00;color:#adff00}.nw-notice-error{background:#2a0000;border-color:#ff4444;color:#ff4444}
.nw-table-wrap{background:#111;border:1px solid #222;border-radius:8px;overflow:hidden}
.nw-table{width:100%;border-collapse:collapse;font-size:13px}
.nw-table thead tr{background:#1a1a1a;border-bottom:1px solid #2a2a2a}
.nw-table th{padding:10px 14px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#666;font-weight:600}
.nw-table tbody tr{border-bottom:1px solid #1e1e1e;transition:background .12s}
.nw-table tbody tr:last-child{border-bottom:none}.nw-table tbody tr:hover{background:#161616}
.nw-table td{padding:10px 14px;vertical-align:middle}
.nw-pkg-name{font-weight:600;color:#fff}.nw-pkg-sub{font-size:11px;color:#555;margin-top:2px}
.nw-armor-val{color:#adff00;font-weight:700;font-size:13px}
.nw-slot-chip{font-size:10px;padding:2px 7px;background:#0d1a2e;border:1px solid #1e3a5e;border-radius:3px;color:#5599ff;margin:2px 1px;display:inline-block;white-space:nowrap}
.nw-tags{display:flex;flex-wrap:wrap;gap:4px}
.nw-tag{font-size:10px;padding:2px 7px;background:#1e1e1e;border:1px solid #2e2e2e;border-radius:3px;color:#888}
.nw-toggle{position:relative;display:inline-block;width:40px;height:22px}
.nw-toggle input{opacity:0;width:0;height:0}
.nw-toggle-slider{position:absolute;inset:0;background:#2a2a2a;border-radius:22px;cursor:pointer;transition:background .2s;border:1px solid #3a3a3a}
.nw-toggle-slider::before{content:'';position:absolute;width:16px;height:16px;left:2px;top:2px;background:#555;border-radius:50%;transition:all .2s}
.nw-toggle input:checked+.nw-toggle-slider{background:#1a3300;border-color:#adff00}
.nw-toggle input:checked+.nw-toggle-slider::before{background:#adff00;transform:translateX(18px)}
.nw-row-actions{display:flex;gap:6px}
.nw-action-btn{font-family:'Chakra Petch',monospace;font-size:11px;padding:4px 10px;border-radius:4px;border:1px solid #2e2e2e;background:transparent;color:#aaa;cursor:pointer;transition:all .15s;text-transform:uppercase}
.nw-action-btn:hover{border-color:#adff00;color:#adff00}
.nw-loading-row td{text-align:center;padding:32px;color:#555}
.nw-spinner{display:inline-block;width:16px;height:16px;border:2px solid #333;border-top-color:#adff00;border-radius:50%;animation:nw-spin .6s linear infinite;vertical-align:middle;margin-right:8px}
@keyframes nw-spin{to{transform:rotate(360deg)}}
.nw-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:99998;display:flex;align-items:center;justify-content:center;padding:20px}
.nw-modal{background:#111;border:1px solid #2e2e2e;border-radius:10px;width:100%;max-width:760px;max-height:92vh;overflow-y:auto;display:flex;flex-direction:column;font-family:'Chakra Petch',monospace}
.nw-modal-header{display:flex;align-items:center;justify-content:space-between;padding:18px 24px 14px;border-bottom:1px solid #1e1e1e;position:sticky;top:0;background:#111;z-index:1}
.nw-modal-header h2{margin:0;font-size:16px;color:#fff;font-family:'Chakra Petch',monospace}
.nw-modal-close{background:none;border:none;color:#666;font-size:18px;cursor:pointer;padding:2px 6px;border-radius:4px}
.nw-modal-close:hover{color:#fff;background:#222}
.nw-modal-body{padding:20px 24px;flex:1}
.nw-modal-footer{padding:14px 24px;border-top:1px solid #1e1e1e;display:flex;justify-content:flex-end;align-items:center;gap:10px;position:sticky;bottom:0;background:#111}
.nw-section-label{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#adff00;font-weight:700;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #1e2e00}
.nw-section-label:first-child{margin-top:0}
.nw-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.nw-field{display:flex;flex-direction:column;gap:5px}.nw-field-full{grid-column:1/-1}.nw-field-center{align-items:flex-start}
.nw-field label{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#666;font-weight:600}
.nw-req{color:#ff4444}.nw-hint{font-size:10px;color:#444;text-transform:none;letter-spacing:0;font-weight:400}
.nw-field input[type="text"],.nw-field input[type="number"],.nw-field textarea,.nw-select{background:#0d0d0d;border:1px solid #2a2a2a;border-radius:5px;color:#e0e0e0;padding:8px 10px;font-family:'Chakra Petch',monospace;font-size:13px;transition:border-color .15s;width:100%}
.nw-field input:focus,.nw-field textarea:focus,.nw-select:focus{outline:none;border-color:#adff00;box-shadow:0 0 0 2px rgba(173,255,0,.08)}
.nw-field textarea{resize:vertical}
.nw-select option{background:#111}
.nw-select-loading{color:#555;font-style:italic}
CSS;
    }

    /* ---------------------------------------------------------------- */
    /*  JS                                                                */
    /* ---------------------------------------------------------------- */

    private function get_js(): string { return <<<'JS'
jQuery(function($){
    var nonce  = $('#nw-nonce').val();
    var editId = null;
    var allItems = []; /* cache of cyber_items for slot selects */

    /* -------- load items for selects -------- */
    function loadItemsCache(cb){
        if(allItems.length){cb();return;}
        $.post(ajaxurl,{action:'nw_sp_get_items',nonce:nonce},function(r){
            if(r.success) allItems=r.data||[];
            cb();
        });
    }

    function populateItemSelects(pkg){
        var slotMap={
            'nw-field-head_item_id':  ['head'],
            'nw-field-torso_item_id': ['chest','torso','body'],
            'nw-field-hand_r_item_id':['hand_r','weapon','shield'],
            'nw-field-hand_l_item_id':['hand_l','weapon','shield'],
            'nw-field-belt_item_id':  ['waist','belt','bag']
        };
        /* For each select: show all items (not filtered) but group by slot for UX */
        $.each(slotMap,function(selId){
            var $sel=$('#'+selId);
            $sel.empty().append('<option value="">— none —</option>');
            /* Group by slot */
            var grouped={};
            $.each(allItems,function(_,it){
                var g=it.slot||it.type||'other';
                if(!grouped[g]) grouped[g]=[];
                grouped[g].push(it);
            });
            $.each(grouped,function(grpName,items){
                var $og=$('<optgroup>').attr('label',grpName.toUpperCase());
                $.each(items,function(_,it){
                    $og.append($('<option>').val(it.id).text(it.name+(it.slot?' ['+it.slot+']':'')));
                });
                $sel.append($og);
            });
            /* set current value */
            var fieldName=selId.replace('nw-field-','');
            var curVal=pkg&&pkg[fieldName]?pkg[fieldName]:'';
            $sel.val(curVal);
        });
    }

    /* -------- load packages -------- */
    function loadPackages(){
        $('#nw-sp-tbody').html('<tr class="nw-loading-row"><td colspan="7"><div class="nw-spinner"></div> Loading packages…</td></tr>');
        $.post(ajaxurl,{action:'nw_sp_get_all',nonce:nonce},function(r){
            if(!r.success){showNotice('error',r.data);return;}
            renderTable(r.data);
        });
    }

    function renderTable(rows){
        var total=rows.length,sel=0,hidden=0,html='';
        if(!rows.length){html='<tr><td colspan="7" style="text-align:center;padding:32px;color:#555;">No packages found.</td></tr>';}
        $.each(rows,function(_,p){
            if(p.is_player_selectable) sel++; else hidden++;
            /* slots summary */
            var slots='';
            var slotFields=['head_item_id','torso_item_id','hand_r_item_id','hand_l_item_id','belt_item_id'];
            var slotLabels={head_item_id:'Head',torso_item_id:'Torso',hand_r_item_id:'R-Hand',hand_l_item_id:'L-Hand',belt_item_id:'Belt'};
            $.each(slotFields,function(_,f){
                if(p[f]) slots+='<span class="nw-slot-chip">'+slotLabels[f]+'</span>';
            });
            if(!slots) slots='<span style="color:#333">—</span>';
            /* compat tags */
            var tags='';
            if(p.compatibility_tags&&p.compatibility_tags.length){
                $.each(p.compatibility_tags,function(_,t){tags+='<span class="nw-tag">'+escH(t)+'</span>';});}
            if(!tags) tags='<span style="color:#333">—</span>';
            /* class ids count */
            var classCount=(p.compatible_class_ids&&p.compatible_class_ids.length)?p.compatible_class_ids.length:0;
            html+='<tr data-id="'+escH(p.id)+'">'
                +'<td><div class="nw-pkg-name">'+escH(p.package_name)+'</div>'+(p.description?'<div class="nw-pkg-sub">'+escH(p.description.substring(0,60))+(p.description.length>60?'…':'')+'</div>':'')+'</td>'
                +'<td><span class="nw-armor-val">'+escH(p.base_armor)+'</span></td>'
                +'<td>'+slots+'</td>'
                +'<td><div class="nw-tags">'+tags+'</div></td>'
                +'<td>'+(classCount?'<span class="nw-tag">'+classCount+' class'+(classCount>1?'es':'')+'</span>':'<span style="color:#333">—</span>')+'</td>'
                +'<td><label class="nw-toggle"><input type="checkbox" class="nw-toggle-sel" data-id="'+escH(p.id)+'"'+(p.is_player_selectable?' checked':'')+'><span class="nw-toggle-slider"></span></label></td>'
                +'<td><div class="nw-row-actions"><button class="nw-action-btn nw-edit-btn" data-id="'+escH(p.id)+'">Edit</button></div></td>'
                +'</tr>';
        });
        $('#nw-sp-tbody').html(html);
        $('#nw-total').text(total);$('#nw-selectable').text(sel);$('#nw-hidden').text(hidden);
    }

    /* -------- modal -------- */
    function openModal(pkg){
        editId=pkg?pkg.id:null;
        $('#nw-modal-title').text(pkg?'Edit Package':'New Package');
        $('#nw-save-label').text(pkg?'Save Package':'Create Package');
        $('#nw-delete-btn').toggle(!!pkg);
        $('#nw-field-id').val(pkg?pkg.id:'');
        $('#nw-field-package_name').val(pkg?pkg.package_name:'');
        $('#nw-field-description').val(pkg?pkg.description||'':'');
        $('#nw-field-base_armor').val(pkg?pkg.base_armor:0);
        $('#nw-field-is_player_selectable').prop('checked',pkg?pkg.is_player_selectable:false);
        /* JSON array → comma string */
        var arrToStr=function(a){return (a&&a.length)?a.join(', '):''};
        $('#nw-field-items_list').val(arrToStr(pkg&&pkg.items_list));
        $('#nw-field-attack_cards_pool').val(arrToStr(pkg&&pkg.attack_cards_pool));
        $('#nw-field-defense_cards_pool').val(arrToStr(pkg&&pkg.defense_cards_pool));
        $('#nw-field-compatibility_tags').val(arrToStr(pkg&&pkg.compatibility_tags));
        $('#nw-field-compatible_class_ids').val(arrToStr(pkg&&pkg.compatible_class_ids));
        /* load items then populate selects */
        loadItemsCache(function(){populateItemSelects(pkg);});
        $('#nw-modal-overlay').show();
    }
    function closeModal(){ $('#nw-modal-overlay').hide(); editId=null; }

    /* -------- save -------- */
    function savePkg(){
        var data={action:'nw_sp_save',nonce:nonce,pkg:{}};
        $('#nw-sp-form').serializeArray().forEach(function(f){data.pkg[f.name]=f.value;});
        data.pkg.is_player_selectable=$('#nw-field-is_player_selectable').is(':checked')?'1':'0';
        /* slot selects (not serialised if value=="" properly, but be explicit) */
        ['head_item_id','torso_item_id','hand_r_item_id','hand_l_item_id','belt_item_id'].forEach(function(f){
            data.pkg[f]=$('#nw-field-'+f).val()||'';
        });
        $('#nw-save-btn').prop('disabled',true).text('Saving…');
        $.post(ajaxurl,data,function(r){
            $('#nw-save-btn').prop('disabled',false);
            $('#nw-save-label').text(editId?'Save Package':'Create Package');
            if(!r.success){showNotice('error',r.data);return;}
            showNotice('success',editId?'Package updated.':'Package created.');
            closeModal(); loadPackages();
        });
    }

    /* -------- toggle -------- */
    $(document).on('change','.nw-toggle-sel',function(){
        var id=$(this).data('id'), state=$(this).is(':checked');
        $.post(ajaxurl,{action:'nw_sp_toggle',nonce:nonce,pkg_id:id,is_player_selectable:state?1:0},function(r){
            if(!r.success){showNotice('error',r.data);loadPackages();}
        });
    });

    /* -------- delete -------- */
    $('#nw-delete-btn').on('click',function(){
        if(!editId||!confirm('Delete this package? This cannot be undone.')) return;
        $.post(ajaxurl,{action:'nw_sp_delete',nonce:nonce,pkg_id:editId},function(r){
            if(!r.success){showNotice('error',r.data);return;}
            showNotice('success','Package deleted.');
            closeModal(); loadPackages();
        });
    });

    /* -------- events -------- */
    $('#nw-add-btn').on('click',function(){openModal(null);});
    $('#nw-refresh-btn').on('click',loadPackages);
    $('#nw-modal-close,#nw-cancel-btn').on('click',closeModal);
    $('#nw-modal-overlay').on('click',function(e){if($(e.target).is('#nw-modal-overlay'))closeModal();});
    $('#nw-save-btn').on('click',savePkg);
    $(document).on('click','.nw-edit-btn',function(){
        var id=$(this).data('id');
        $.post(ajaxurl,{action:'nw_sp_get_all',nonce:nonce},function(r){
            if(!r.success) return;
            var pkg=null; $.each(r.data,function(_,p){if(p.id===id){pkg=p;return false;}});
            if(pkg) openModal(pkg);
        });
    });

    function showNotice(type,msg){
        var $n=$('#nw-notice');
        $n.removeClass('nw-notice-success nw-notice-error').addClass('nw-notice-'+type).text(msg).show();
        setTimeout(function(){$n.fadeOut();},4000);
    }
    function escH(s){return $('<div>').text(String(s||'')).html();}

    loadPackages();
});
JS;
    }
}

new NeoWeaver_Starting_Packages_Admin();
