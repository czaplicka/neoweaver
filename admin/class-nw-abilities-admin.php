<?php
/**
 * NeoWeaver Admin Panel — Abilities (cyber_abilities)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Abilities_Admin {

    private string $page_slug   = 'neoweaver-abilities';
    private string $parent_slug = 'neoweaver';

    private const ABILITY_TYPES = [
        'Active', 'Passive', 'Reaction', 'Ultimate',
        'Racial', 'Class', 'Item', 'Special',
    ];

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_nw_abilities_get_all', [ $this, 'ajax_get_all' ] );
        add_action( 'wp_ajax_nw_abilities_save',    [ $this, 'ajax_save'    ] );
        add_action( 'wp_ajax_nw_abilities_delete',  [ $this, 'ajax_delete'  ] );
    }

    /* ---------------------------------------------------------------- */
    /*  MENU                                                              */
    /* ---------------------------------------------------------------- */

    public function register_menu(): void {
        add_submenu_page(
            $this->parent_slug,
            'NeoWeaver — Abilities',
            '✨ Abilities',
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

        wp_enqueue_style(
            'chakra-petch',
            'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
            [],
            null
        );

        wp_register_script( 'nw-abilities-script', false, [ 'jquery' ], null, true );
        wp_enqueue_script( 'nw-abilities-script' );

        wp_localize_script( 'nw-abilities-script', 'NWAbilities', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'neoweaver_abilities' ),
        ] );

        wp_add_inline_style( 'chakra-petch', $this->get_css() );
        wp_add_inline_script( 'nw-abilities-script', $this->get_js(), 'after' );
    }

    /* ---------------------------------------------------------------- */
    /*  RENDER                                                            */
    /* ---------------------------------------------------------------- */

    public function render_page(): void { ?>
        <div class="wrap nw-panel" id="nw-abilities-panel">

            <div class="nw-panel-header">
                <h1 class="nw-panel-title">
                    <span class="nw-accent">Neo</span>Weaver
                    <span class="nw-panel-subtitle">/ Abilities</span>
                </h1>
                <div class="nw-header-actions">
                    <select id="nw-filter-type" class="nw-select-filter">
                        <option value="">All types</option>
                        <?php foreach ( self::ABILITY_TYPES as $t ) : ?>
                        <option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $t ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" id="nw-search" class="nw-search-input" placeholder="🔍 Search name, source or tag…">
                    <button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">↻ Refresh</button>
                    <button class="nw-btn nw-btn-primary" id="nw-add-btn">+ New Ability</button>
                </div>
            </div>

            <div id="nw-notice" class="nw-notice" style="display:none;"></div>

            <div class="nw-stats-bar">
                <span class="nw-stat-pill">Total: <strong id="nw-total">—</strong></span>
                <span class="nw-stat-pill nw-pill-active">Active: <strong id="nw-active-count">—</strong></span>
                <span class="nw-stat-pill nw-pill-passive">Passive: <strong id="nw-passive-count">—</strong></span>
                <span class="nw-stat-pill nw-pill-special">Other: <strong id="nw-other-count">—</strong></span>
            </div>

            <div class="nw-table-wrap">
                <table class="nw-table">
                    <thead><tr>
                        <th class="nw-col-img"></th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Source</th>
                        <th>Cost</th>
                        <th>Tags</th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody id="nw-abilities-tbody">
                        <tr class="nw-loading-row"><td colspan="7"><div class="nw-spinner"></div> Loading abilities…</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- MODAL -->
            <div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
                <div class="nw-modal">
                    <div class="nw-modal-header">
                        <h2 id="nw-modal-title">Edit Ability</h2>
                        <button class="nw-modal-close" id="nw-modal-close">✕</button>
                    </div>
                    <div class="nw-modal-body">
                        <form id="nw-ability-form">
                            <input type="hidden" id="nw-field-id" name="id">

                            <div class="nw-section-label">Identity</div>
                            <div class="nw-form-grid">

                                <div class="nw-field nw-field-full">
                                    <label>Name <span class="nw-req">*</span></label>
                                    <input type="text" id="nw-field-name" name="name" required placeholder="e.g. Brute Force">
                                </div>

                                <div class="nw-field">
                                    <label>Ability Type</label>
                                    <select id="nw-field-ability_type" name="ability_type" class="nw-select">
                                        <option value="">— choose —</option>
                                        <?php foreach ( self::ABILITY_TYPES as $t ) : ?>
                                        <option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $t ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="nw-field">
                                    <label>Cost</label>
                                    <input type="text" id="nw-field-cost" name="cost" placeholder="e.g. 1 card, 2 MP, Free">
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>Source <span class="nw-hint">(classes / archetypes that have this ability)</span></label>
                                    <input type="text" id="nw-field-source" name="source" placeholder="e.g. Mercenary, Soldier, Juggernaut">
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>Tags <span class="nw-hint">(comma-separated)</span></label>
                                    <input type="text" id="nw-field-tags" name="tags" placeholder="e.g. Combat, Power, Sacrifice">
                                </div>

                            </div>

                            <div class="nw-section-label">Content</div>
                            <div class="nw-form-grid">

                                <div class="nw-field nw-field-full">
                                    <label>Description <span class="nw-hint">(shown to players)</span></label>
                                    <textarea id="nw-field-description" name="description" rows="4" placeholder="Ability effect description…"></textarea>
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>GM Notes <span class="nw-hint">(internal / AI context)</span></label>
                                    <textarea id="nw-field-gm_notes" name="gm_notes" rows="3" placeholder="GM/AI interpretation hints…"></textarea>
                                </div>

                            </div>

                            <div class="nw-section-label">Media</div>
                            <div class="nw-form-grid">

                                <div class="nw-field nw-field-full">
                                    <label>Image URL</label>
                                    <input type="url" id="nw-field-img_url" name="img_url" placeholder="https://…">
                                    <div id="nw-img-preview-wrap" style="display:none;margin-top:6px;">
                                        <img id="nw-img-preview" src="" alt="preview" style="max-height:80px;border-radius:4px;border:1px solid #2e2e2e;">
                                    </div>
                                </div>

                            </div>

                        </form>
                    </div>
                    <div class="nw-modal-footer">
                        <button class="nw-btn nw-btn-danger" id="nw-delete-btn" style="display:none;margin-right:auto;">🗑 Delete</button>
                        <button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
                        <button class="nw-btn nw-btn-primary" id="nw-save-btn"><span id="nw-save-label">Save Ability</span></button>
                    </div>
                </div>
            </div>

        </div>
    <?php }

    /* ---------------------------------------------------------------- */
    /*  SUPABASE                                                          */
    /* ---------------------------------------------------------------- */

    /**
     * @param string $method  GET | POST | PATCH | DELETE
     * @param string $table   Nazwa tabeli, np. 'cyber_abilities'
     * @param array  $body    Dane JSON (POST/PATCH)
     * @param array  $query   Filtry PostgREST, np. ['id' => 'eq.123']
     * @param array  $extra   Dodatkowe $extra_args dla helperów
     */
    private function supa(
        string $method,
        string $table,
        array  $body  = [],
        array  $query = [],
        array  $extra = []
    ): array {
        $method = strtoupper( $method );

        if ( 'GET' === $method ) {
            $data = tw_supabase_get( $table, $query, $extra );
            return [ 'code' => 200, 'data' => $data ];
        }

        $prefer_header = in_array( $method, [ 'POST', 'PATCH' ], true )
            ? [ 'headers' => [ 'Prefer' => 'return=representation' ] ]
            : [];

        $merged_extra = array_replace_recursive( $prefer_header, $extra );

        $res = tw_supabase_request(
            $method,
            $table,
            $query,         // ← 3. arg: filtry URL
            $body ?: null,  // ← 4. arg: body JSON
            $merged_extra
        );

        if ( ! is_array( $res ) || ! array_key_exists( 'code', $res ) ) {
            return [ 'error' => 'Unexpected response from tw_supabase_request' ];
        }

        return [ 'code' => $res['code'], 'data' => $res['data'] ];
    }

    /* ---------------------------------------------------------------- */
    /*  UUID sanitisation                                                 */
    /* ---------------------------------------------------------------- */

    private function sanitize_uuid( string $raw ): string {
        if ( function_exists( 'tw_sanitize_supabase_id' ) ) {
            return tw_sanitize_supabase_id( $raw );
        }
        return preg_replace( '/[^a-f0-9\-]/i', '', $raw );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: GET ALL                                                     */
    /* ---------------------------------------------------------------- */

    public function ajax_get_all(): void {
        check_ajax_referer( 'neoweaver_abilities', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $raw_type = sanitize_text_field( $_POST['filter_type'] ?? '' );
        $type     = in_array( $raw_type, self::ABILITY_TYPES, true ) ? $raw_type : '';

        $limit = (int) apply_filters( 'neoweaver_abilities_per_page', 500 );

        $query = [
            'select' => 'id,name,description,ability_type,source,gm_notes,cost,img_url,tags,created_at',
            'order'  => 'name.asc',
            'limit'  => $limit,
        ];

        if ( $type ) {
            $query['ability_type'] = 'eq.' . $type;
        }

        $res = $this->supa( 'GET', 'cyber_abilities', [], $query );

        if ( isset( $res['error'] ) ) {
            wp_send_json_error( $res['error'] );
            return;
        }

        wp_send_json_success( $res['data'] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: SAVE                                                        */
    /* ---------------------------------------------------------------- */

    public function ajax_save(): void {
        check_ajax_referer( 'neoweaver_abilities', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $raw = $_POST;
        $id  = $this->sanitize_uuid( sanitize_text_field( $raw['id'] ?? '' ) );

        $tags = array_values( array_filter(
            array_map( 'trim', explode( ',', sanitize_text_field( $raw['tags'] ?? '' ) ) )
        ) );

        $ability_type_raw = sanitize_text_field( $raw['ability_type'] ?? '' );
        $ability_type     = in_array( $ability_type_raw, self::ABILITY_TYPES, true ) ? $ability_type_raw : null;

        $payload = [
            'name'         => sanitize_text_field(     $raw['name']        ?? '' ),
            'description'  => sanitize_textarea_field( $raw['description'] ?? '' ) ?: null,
            'gm_notes'     => sanitize_textarea_field( $raw['gm_notes']    ?? '' ) ?: null,
            'ability_type' => $ability_type,
            'source'       => sanitize_text_field(     $raw['source']      ?? '' ) ?: null,
            'cost'         => sanitize_text_field(     $raw['cost']        ?? '' ) ?: null,
            'img_url'      => esc_url_raw(             $raw['img_url']     ?? '' ) ?: null,
            'tags'         => $tags,
        ];

        if ( empty( $payload['name'] ) ) {
            wp_send_json_error( 'Name is required.' );
            return;
        }

        if ( $id ) {
            $res = $this->supa( 'PATCH', 'cyber_abilities', $payload, [ 'id' => 'eq.' . $id ] );
        } else {
            $res = $this->supa( 'POST', 'cyber_abilities', $payload );
        }

        if ( isset( $res['error'] ) ) {
            wp_send_json_error( $res['error'] );
            return;
        }

        $code = $res['code'] ?? 0;
        if ( $code >= 200 && $code < 300 ) {
            $data = $res['data'];
            wp_send_json_success( is_array( $data ) ? ( $data[0] ?? $data ) : $data );
        } else {
            $msg = is_array( $res['data'] )
                ? ( $res['data']['message'] ?? 'Supabase error ' . $code )
                : 'Supabase error ' . $code;
            wp_send_json_error( $msg );
        }
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: DELETE                                                      */
    /* ---------------------------------------------------------------- */

    public function ajax_delete(): void {
        check_ajax_referer( 'neoweaver_abilities', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $id = $this->sanitize_uuid( sanitize_text_field( $_POST['ability_id'] ?? '' ) );

        if ( ! $id ) {
            wp_send_json_error( 'Missing or invalid ID' );
            return;
        }

        $res = $this->supa( 'DELETE', 'cyber_abilities', [], [ 'id' => 'eq.' . $id ] );

        if ( isset( $res['error'] ) ) {
            wp_send_json_error( $res['error'] );
            return;
        }

        wp_send_json_success( 'deleted' );
    }

    /* ---------------------------------------------------------------- */
    /*  CSS                                                               */
    /* ---------------------------------------------------------------- */

    private function get_css(): string {
        return "
        #nw-abilities-panel *{font-family:'Chakra Petch',sans-serif;box-sizing:border-box;}
        .nw-panel{background:#0d0d0d;min-height:100vh;padding:24px;color:#e0e0e0;}
        .nw-panel-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid #1e1e1e;}
        .nw-panel-title{font-size:22px;font-weight:700;color:#fff;margin:0;}
        .nw-accent{color:#adff00;}
        .nw-panel-subtitle{color:#555;font-weight:400;margin-left:6px;}
        .nw-header-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}

        .nw-btn{padding:7px 16px;border-radius:4px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .15s;}
        .nw-btn-primary{background:#adff00;color:#000;}
        .nw-btn-primary:hover{background:#c8ff3f;}
        .nw-btn-ghost{background:transparent;border:1px solid #333;color:#aaa;}
        .nw-btn-ghost:hover{border-color:#adff00;color:#adff00;}
        .nw-btn-danger{background:#c0392b;color:#fff;}
        .nw-btn-danger:hover{background:#e74c3c;}

        .nw-select-filter,.nw-search-input{background:#111;border:1px solid #2a2a2a;color:#ddd;padding:7px 10px;border-radius:4px;font-size:13px;}
        .nw-select-filter:focus,.nw-search-input:focus{outline:none;border-color:#adff00;}
        .nw-search-input{min-width:220px;}

        .nw-notice{padding:10px 16px;border-radius:4px;margin-bottom:16px;font-size:13px;}
        .nw-notice-success{background:#1a3300;border-left:3px solid #adff00;color:#adff00;}
        .nw-notice-error{background:#3a1a1a;border-left:3px solid #e74c3c;color:#e74c3c;}
        .nw-notice-info{background:#1a1a3a;border-left:3px solid #3498db;color:#3498db;}

        .nw-stats-bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;}
        .nw-stat-pill{background:#111;border:1px solid #222;padding:4px 12px;border-radius:20px;font-size:12px;color:#888;}
        .nw-stat-pill strong{color:#ddd;}
        .nw-pill-active strong{color:#adff00;}
        .nw-pill-passive strong{color:#3498db;}
        .nw-pill-special strong{color:#9b59b6;}

        .nw-table-wrap{border:1px solid #1e1e1e;border-radius:6px;overflow:hidden;}
        .nw-table{width:100%;border-collapse:collapse;font-size:13px;}
        .nw-table thead tr{background:#111;}
        .nw-table th{padding:10px 12px;text-align:left;color:#adff00;font-weight:600;border-bottom:1px solid #1e1e1e;white-space:nowrap;}
        .nw-table td{padding:8px 12px;border-bottom:1px solid #1a1a1a;vertical-align:middle;}
        .nw-table tbody tr:hover{background:#0f0f0f;}
        .nw-col-img{width:52px;}
        .nw-ability-img{width:40px;height:40px;object-fit:cover;border-radius:4px;border:1px solid #222;}
        .nw-ability-img-placeholder{width:40px;height:40px;display:flex;align-items:center;justify-content:center;background:#1a1a1a;border-radius:4px;font-size:18px;}
        .nw-ability-name{font-weight:600;color:#fff;}
        .nw-ability-desc{color:#666;font-size:11px;margin-top:2px;max-width:260px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;}
        .nw-source{color:#aaa;font-size:12px;}

        .nw-type-badge{display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;}
        .nw-type-active{background:#1a3300;color:#adff00;}
        .nw-type-passive{background:#001a33;color:#3498db;}
        .nw-type-reaction{background:#331a00;color:#e67e22;}
        .nw-type-ultimate{background:#1a0033;color:#9b59b6;}
        .nw-type-racial{background:#1a1a00;color:#f1c40f;}
        .nw-type-class{background:#001a1a;color:#1abc9c;}
        .nw-type-item{background:#1a0000;color:#e74c3c;}
        .nw-type-special{background:#1a1a1a;color:#bbb;}

        .nw-tags{display:flex;flex-wrap:wrap;gap:4px;}
        .nw-tag{background:#1a1a1a;border:1px solid #2e2e2e;color:#888;padding:2px 7px;border-radius:3px;font-size:11px;}
        .nw-cost-badge{background:#111;border:1px solid #333;color:#adff00;padding:2px 8px;border-radius:3px;font-size:11px;}

        .nw-row-actions{display:flex;gap:6px;}
        .nw-action-btn{background:#1a1a1a;border:1px solid #2e2e2e;color:#aaa;padding:5px 12px;border-radius:3px;font-size:12px;cursor:pointer;font-family:'Chakra Petch',sans-serif;}
        .nw-action-btn:hover{border-color:#adff00;color:#adff00;}
        .nw-loading-row td{text-align:center;padding:32px;color:#555;}

        .nw-spinner{display:inline-block;width:16px;height:16px;border:2px solid #333;border-top-color:#adff00;border-radius:50%;animation:nwSpin .7s linear infinite;vertical-align:middle;margin-right:8px;}
        @keyframes nwSpin{to{transform:rotate(360deg);}}

        .nw-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:100000;display:flex;align-items:center;justify-content:center;padding:20px;}
        .nw-modal{background:#111;border:1px solid #2a2a2a;border-radius:8px;width:100%;max-width:680px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 8px 40px rgba(0,0,0,.8);}
        .nw-modal-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #1e1e1e;}
        .nw-modal-header h2{font-size:16px;font-weight:700;color:#adff00;margin:0;}
        .nw-modal-close{background:transparent;border:none;color:#555;font-size:18px;cursor:pointer;line-height:1;padding:4px;}
        .nw-modal-close:hover{color:#fff;}
        .nw-modal-body{padding:20px;overflow-y:auto;}
        .nw-modal-footer{display:flex;align-items:center;justify-content:flex-end;padding:16px 20px;border-top:1px solid #1e1e1e;gap:8px;}
        .nw-section-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#adff00;margin:16px 0 10px;border-bottom:1px solid #1e1e1e;padding-bottom:6px;}
        .nw-section-label:first-child{margin-top:0;}
        .nw-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
        .nw-field{display:flex;flex-direction:column;gap:4px;}
        .nw-field-full{grid-column:1/-1;}
        .nw-field label{font-size:12px;color:#888;font-weight:600;}
        .nw-req{color:#e74c3c;}
        .nw-hint{color:#555;font-weight:400;}
        .nw-field input,.nw-field select,.nw-field textarea{background:#0d0d0d;border:1px solid #2a2a2a;color:#ddd;padding:8px 10px;border-radius:4px;font-size:13px;font-family:'Chakra Petch',sans-serif;width:100%;}
        .nw-field input:focus,.nw-field select:focus,.nw-field textarea:focus{outline:none;border-color:#adff00;}
        .nw-field textarea{resize:vertical;}
        .nw-select{background:#0d0d0d;border:1px solid #2a2a2a;color:#ddd;}
        ";
    }

    /* ---------------------------------------------------------------- */
    /*  JS                                                                */
    /* ---------------------------------------------------------------- */

    private function get_js(): string {
        return <<<'JS'
jQuery(function ($) {
    'use strict';

    var cfg          = window.NWAbilities || {};
    var ajaxEndpoint = cfg.ajaxurl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
    var nonce        = cfg.nonce  || '';

    var $notice       = $('#nw-notice');
    var $tbody        = $('#nw-abilities-tbody');
    var $filterType   = $('#nw-filter-type');
    var $search       = $('#nw-search');
    var $modalOverlay = $('#nw-modal-overlay');
    var $form         = $('#nw-ability-form');
    var $saveBtn      = $('#nw-save-btn');
    var $saveLabel    = $('#nw-save-label');
    var $deleteBtn    = $('#nw-delete-btn');
    var $imgPreview     = $('#nw-img-preview');
    var $imgPreviewWrap = $('#nw-img-preview-wrap');
    var $fieldId          = $('#nw-field-id');
    var $fieldName        = $('#nw-field-name');
    var $fieldDescription = $('#nw-field-description');
    var $fieldGMNotes     = $('#nw-field-gm_notes');
    var $fieldAbilityType = $('#nw-field-ability_type');
    var $fieldSource      = $('#nw-field-source');
    var $fieldCost        = $('#nw-field-cost');
    var $fieldTags        = $('#nw-field-tags');
    var $fieldImgUrl      = $('#nw-field-img_url');

    var all       = [];
    var filtered  = [];
    var activeXhr = null;

    var typeClass = {
        'Active':   'nw-type-active',
        'Passive':  'nw-type-passive',
        'Reaction': 'nw-type-reaction',
        'Ultimate': 'nw-type-ultimate',
        'Racial':   'nw-type-racial',
        'Class':    'nw-type-class',
        'Item':     'nw-type-item',
        'Special':  'nw-type-special'
    };

    function esc(s) {
        return $('<span>').text(s || '').html();
    }

    function notice(msg, type) {
        var safeType = String(type || 'info').replace(/[^a-z-]/g, '');
        $notice
            .attr('class', 'nw-notice nw-notice-' + safeType)
            .text(msg)
            .show();
        setTimeout(function () { $notice.fadeOut(300); }, 3500);
    }

    function tagsStr(t) {
        if (!t) return '';
        if (Array.isArray(t)) return t.join(', ');
        return String(t);
    }

    function debounce(fn, delay) {
        var timer;
        return function () {
            var args = arguments, ctx = this;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(ctx, args); }, delay);
        };
    }

    function cloneAbilities(data) {
        if (!Array.isArray(data)) return [];
        return data
            .filter(function (item) {
                return item && typeof item === 'object' && item.id;
            })
            .map(function (item) {
                return {
                    id:           String(item.id           || ''),
                    name:         String(item.name         || ''),
                    description:  String(item.description  || ''),
                    gm_notes:     String(item.gm_notes     || ''),
                    ability_type: String(item.ability_type || ''),
                    source:       String(item.source       || ''),
                    cost:         String(item.cost         || ''),
                    img_url:      String(item.img_url      || ''),
                    tags: Array.isArray(item.tags) ? item.tags.slice() : []
                };
            });
    }

    function updateStats(data) {
        var active = 0, passive = 0;
        (data || []).forEach(function (a) {
            if      (a.ability_type === 'Active')  active++;
            else if (a.ability_type === 'Passive') passive++;
        });
        $('#nw-total').text(data.length);
        $('#nw-active-count').text(active);
        $('#nw-passive-count').text(passive);
        $('#nw-other-count').text(data.length - active - passive);
    }

    function bindImageFallbacks() {
        $tbody.find('img[data-fallback]')
            .off('error.nwFallback')
            .on('error.nwFallback', function () { $(this).hide(); });
    }

    function renderTable(data) {
        if (!data.length) {
            $tbody.html('<tr><td colspan="7" style="text-align:center;padding:32px;color:#555;">No abilities found.</td></tr>');
            return;
        }
        $tbody.html(data.map(function (a) {
            var tags   = Array.isArray(a.tags) ? a.tags : [];
            var safeId = esc(a.id);
            var tagsH  = tags.slice(0, 3).map(function (t) {
                return '<span class="nw-tag">' + esc(t) + '</span>';
            }).join('') + (tags.length > 3 ? '<span class="nw-tag">+' + (tags.length - 3) + '</span>' : '');
            var tc    = typeClass[a.ability_type] || 'nw-type-special';
            var typeH = a.ability_type
                ? '<span class="nw-type-badge ' + tc + '">' + esc(a.ability_type) + '</span>'
                : '—';
            var imgH  = a.img_url
                ? '<img src="' + esc(a.img_url) + '" class="nw-ability-img" loading="lazy" data-fallback="1" alt="">'
                : '<div class="nw-ability-img-placeholder">✨</div>';

            return '<tr data-id="' + safeId + '">'
                + '<td>' + imgH + '</td>'
                + '<td>'
                +   '<div class="nw-ability-name">' + esc(a.name) + '</div>'
                +   '<div class="nw-ability-desc">' + esc(a.description) + '</div>'
                + '</td>'
                + '<td>' + typeH + '</td>'
                + '<td><div class="nw-source">' + esc(a.source || '—') + '</div></td>'
                + '<td>' + (a.cost
                    ? '<span class="nw-cost-badge">' + esc(a.cost) + '</span>'
                    : '<span style="color:#444">—</span>') + '</td>'
                + '<td><div class="nw-tags">' + tagsH + '</div></td>'
                + '<td><div class="nw-row-actions">'
                +   '<button class="nw-action-btn nw-edit-btn" data-id="' + safeId + '">Edit</button>'
                + '</div></td>'
                + '</tr>';
        }).join(''));
        bindImageFallbacks();
    }

    function applySearch() {
        var q = $search.val().toLowerCase().trim();
        var shown = q ? filtered.filter(function (a) {
            var tagMatch = (Array.isArray(a.tags) ? a.tags : []).some(function (t) {
                return String(t).toLowerCase().indexOf(q) !== -1;
            });
            return String(a.name   || '').toLowerCase().indexOf(q) !== -1
                || String(a.source || '').toLowerCase().indexOf(q) !== -1
                || tagMatch;
        }) : filtered;
        renderTable(shown);
    }

    function loadAll() {
        var ft = $filterType.val();

        if (!ajaxEndpoint) {
            notice('Missing AJAX endpoint.', 'error');
            return;
        }

        if (activeXhr && activeXhr.readyState !== 4) {
            activeXhr.abort();
        }

        $tbody.html('<tr class="nw-loading-row"><td colspan="7"><div class="nw-spinner"></div> Loading…</td></tr>');

        activeXhr = $.post(ajaxEndpoint, {
            action:      'nw_abilities_get_all',
            nonce:       nonce,
            filter_type: ft
        }, function (res) {
            if (!res || !res.success) {
                notice('Error: ' + ((res && res.data) ? String(res.data) : 'Unknown error'), 'error');
                renderTable([]);
                return;
            }

            // Odrzuć obiekt błędu Supabase (np. PGRST125) schowany w res.data
            if (res.data && !Array.isArray(res.data) && res.data.code) {
                notice('Supabase error: ' + (res.data.message || res.data.code), 'error');
                renderTable([]);
                return;
            }

            var rows = Array.isArray(res.data) ? res.data : [];
            all      = cloneAbilities(rows);
            filtered = cloneAbilities(rows);
            updateStats(all);
            applySearch();

        }).fail(function (xhr, status) {
            if (status !== 'abort') {
                notice('Request failed.', 'error');
                renderTable([]);
            }
        }).always(function () {
            activeXhr = null;
        });
    }

    function confirmModal(message, onConfirm) {
        if ($('.nw-confirm-overlay').length) return;
        var overlay = $(
            '<div class="nw-confirm-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99999;display:flex;align-items:center;justify-content:center;">'
          + '<div style="background:#1a1a2e;border:1px solid #adff00;border-radius:8px;padding:32px 28px;min-width:320px;text-align:center;">'
          + '<p style="color:#fff;margin-bottom:24px;font-family:\'Chakra Petch\',sans-serif;">' + esc(message) + '</p>'
          + '<button class="nw-confirm-yes nw-action-btn" style="margin-right:12px;background:#c0392b;color:#fff;border-color:#c0392b;">Delete</button>'
          + '<button class="nw-confirm-no nw-action-btn" style="background:#333;">Cancel</button>'
          + '</div></div>'
        );
        $('body').append(overlay);
        overlay.find('.nw-confirm-yes').on('click', function () { overlay.remove(); onConfirm(); });
        overlay.find('.nw-confirm-no').on('click',  function () { overlay.remove(); });
        overlay.on('click', function (e) { if ($(e.target).is(overlay)) overlay.remove(); });
    }

    function openModal(id) {
        $form[0].reset();
        $fieldId.val('');
        $imgPreviewWrap.hide();

        if (id) {
            var a = all.find(function (x) { return x.id === String(id); });
            if (!a) {
                notice('Ability not found in local data — try Refresh.', 'error');
                return;
            }
            $fieldId.val(a.id);
            $fieldName.val(a.name);
            $fieldDescription.val(a.description);
            $fieldGMNotes.val(a.gm_notes);
            $fieldAbilityType.val(a.ability_type);
            $fieldSource.val(a.source);
            $fieldCost.val(a.cost);
            $fieldTags.val(tagsStr(a.tags));
            if (a.img_url) {
                $fieldImgUrl.val(a.img_url);
                $imgPreview.attr('src', a.img_url);
                $imgPreviewWrap.show();
            }
            $('#nw-modal-title').text('Edit Ability');
            $saveLabel.text('Save Changes');
            $deleteBtn.show().data('id', id);
        } else {
            $('#nw-modal-title').text('New Ability');
            $saveLabel.text('Create Ability');
            $deleteBtn.hide();
        }

        $modalOverlay.fadeIn(150);
    }

    $fieldImgUrl.on('input', function () {
        var v = $(this).val().trim();
        if (v) { $imgPreview.attr('src', v); $imgPreviewWrap.show(); }
        else   { $imgPreviewWrap.hide(); }
    });

    $('#nw-modal-close, #nw-cancel-btn').on('click', function () {
        $modalOverlay.fadeOut(150);
    });
    $modalOverlay.on('click', function (e) {
        if ($(e.target).is('#nw-modal-overlay')) $modalOverlay.fadeOut(150);
    });

    $(document).on('click', '.nw-edit-btn', function () {
        openModal($(this).data('id'));
    });
    $('#nw-add-btn').on('click', function () { openModal(null); });

    $('#nw-refresh-btn').on('click', loadAll);
    $filterType.on('change', loadAll);
    $search.on('input', debounce(applySearch, 150));

    $saveBtn.on('click', function () {
        var name = $fieldName.val().trim();
        if (!name) { notice('Name is required.', 'error'); return; }

        var btn = $(this);
        var prevLabel = $saveLabel.text();
        btn.prop('disabled', true);
        $saveLabel.text('Saving…');

        var fd = { action: 'nw_abilities_save', nonce: nonce };
        $form.serializeArray().forEach(function (f) { fd[f.name] = f.value; });

        $.post(ajaxEndpoint, fd, function (res) {
            btn.prop('disabled', false);
            $saveLabel.text(prevLabel);
            if (res.success) {
                notice('Ability saved!', 'success');
                $modalOverlay.fadeOut(150);
                loadAll();
            } else {
                notice('Error: ' + (res.data || 'Unknown'), 'error');
            }
        }).fail(function () {
            btn.prop('disabled', false);
            $saveLabel.text(prevLabel);
            notice('Request failed.', 'error');
        });
    });

    $deleteBtn.on('click', function () {
        var id = $(this).data('id');
        if (!id) return;
        confirmModal('Delete this ability permanently?', function () {
            $.post(ajaxEndpoint, {
                action:     'nw_abilities_delete',
                nonce:      nonce,
                ability_id: id
            }, function (res) {
                if (res.success) {
                    notice('Ability deleted.', 'success');
                    $modalOverlay.fadeOut(150);
                    loadAll();
                } else {
                    notice('Delete failed: ' + (res.data || 'Unknown'), 'error');
                }
            }).fail(function () {
                notice('Delete request failed.', 'error');
            });
        });
    });

    loadAll();
});
JS;
    }

} // end class

add_action( 'plugins_loaded', static function () {
    if ( is_admin() ) {
        new NeoWeaver_Abilities_Admin();
    }
}, 20 );
