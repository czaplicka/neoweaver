<?php
/**
 * NeoWeaver Admin Panel — Abilities (cyber_abilities)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Abilities_Admin {

    private string $page_slug   = 'neoweaver-abilities';
    private string $parent_slug = 'neoweaver';

    // [OPT-2] Static list — defined once as a constant
    private const ABILITY_TYPES = [
        'Active', 'Passive', 'Reaction', 'Ultimate',
        'Racial', 'Class', 'Item', 'Special',
    ];

    // [BUG-1] Constructor does NOT call tw_supabase_*() — credentials resolved lazily
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

        // [BUG-7] Inline CSS — no external file that could 404
        wp_add_inline_style( 'chakra-petch', $this->get_css() );

        // [BUG-8] Register own handle first, attach inline JS to it (not to 'jquery')
        wp_register_script(
            'nw-abilities-script',
            false,
            [ 'jquery' ],
            NEOWEAVER_VERSION,
            true
        );
        wp_enqueue_script( 'nw-abilities-script' );
        wp_add_inline_script( 'nw-abilities-script', $this->get_js() );
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
                        <tr class="nw-loading-row">
                            <td colspan="7"><div class="nw-spinner"></div> Loading abilities…</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ==================== MODAL ==================== -->
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
                                    <label>Tags <span class="nw-hint">(comma-separated → JSON array)</span></label>
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
                                        <img id="nw-img-preview" src="" alt="preview"
                                             style="max-height:80px;border-radius:4px;border:1px solid #2e2e2e;">
                                    </div>
                                </div>

                            </div>
                        </form>
                    </div>
                    <div class="nw-modal-footer">
                        <button class="nw-btn nw-btn-danger" id="nw-delete-btn" style="display:none;margin-right:auto;">🗑 Delete</button>
                        <button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
                        <button class="nw-btn nw-btn-primary" id="nw-save-btn">
                            <span id="nw-save-label">Save Ability</span>
                        </button>
                    </div>
                </div>
            </div>

            <input type="hidden" id="nw-nonce" value="<?php echo esc_attr( wp_create_nonce( 'neoweaver_abilities' ) ); ?>">
        </div>
    <?php }

    /* ---------------------------------------------------------------- */
    /*  SUPABASE — deleguje do project helpers  [OPT-1]                  */
    /* ---------------------------------------------------------------- */

    private function supa( string $method, string $endpoint, array $body = [] ): array {
        // [BUG-2] Credentials resolved here, fresh on every call
        $url = rtrim( tw_supabase_url(), '/' ) . '/rest/v1/' . $endpoint;

        if ( 'GET' === strtoupper( $method ) ) {
            $result = tw_supabase_get( $url );
            if ( is_wp_error( $result ) ) {
                return [ 'error' => $result->get_error_message() ];
            }
            return [ 'code' => 200, 'data' => $result ];
        }

        // [OPT-3] Prefer: return=representation only for write ops
        $extra = in_array( strtoupper( $method ), [ 'POST', 'PATCH' ], true )
            ? [ 'Prefer' => 'return=representation' ]
            : [];

        $result = tw_supabase_request( $method, $url, $body, $extra );
        if ( is_wp_error( $result ) ) {
            return [ 'error' => $result->get_error_message() ];
        }
        return $result;
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: GET ALL                                                     */
    /* ---------------------------------------------------------------- */

    public function ajax_get_all(): void {
        check_ajax_referer( 'neoweaver_abilities', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 ); return;
        }

        // [BUG-4] Allowlist protects against PostgREST injection
        $raw_type = $_POST['filter_type'] ?? '';
        $type     = in_array( $raw_type, self::ABILITY_TYPES, true ) ? $raw_type : '';

        // [OPT-5] Hard limit — configurable via filter
        $limit = absint( apply_filters( 'neoweaver_abilities_per_page', 500 ) );

        $qs = 'cyber_abilities?select=id,name,description,ability_type,source,gm_notes,cost,img_url,tags,created_at'
            . '&order=name.asc'
            . '&limit=' . $limit;

        if ( $type ) {
            $qs .= '&ability_type=eq.' . rawurlencode( $type );
        }

        $res = $this->supa( 'GET', $qs );
        isset( $res['error'] )
            ? wp_send_json_error( $res['error'] )
            : wp_send_json_success( $res['data'] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: SAVE                                                        */
    /* ---------------------------------------------------------------- */

    public function ajax_save(): void {
        check_ajax_referer( 'neoweaver_abilities', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 ); return;
        }

        $raw = $_POST;

        // [BUG-3] UUID sanitisation
        $id = function_exists( 'tw_sanitize_supabase_id' )
            ? tw_sanitize_supabase_id( $raw['id'] ?? '' )
            : preg_replace( '/[^a-f0-9\-]/i', '', sanitize_text_field( $raw['id'] ?? '' ) );

        $tags = array_values( array_filter(
            array_map( 'trim', explode( ',', sanitize_text_field( $raw['tags'] ?? '' ) ) )
        ) );

        $payload = [
            'name'         => sanitize_text_field(     $raw['name']         ?? '' ),
            'description'  => sanitize_textarea_field( $raw['description']  ?? '' ) ?: null,
            'gm_notes'     => sanitize_textarea_field( $raw['gm_notes']     ?? '' ) ?: null,
            'ability_type' => sanitize_text_field(     $raw['ability_type'] ?? '' ) ?: null,
            'source'       => sanitize_text_field(     $raw['source']       ?? '' ) ?: null,
            'cost'         => sanitize_text_field(     $raw['cost']         ?? '' ) ?: null,
            'img_url'      => esc_url_raw(             $raw['img_url']      ?? '' ) ?: null,
            'tags'         => $tags,
        ];

        // [BUG-5] Explicit guard with return on same line
        if ( empty( $payload['name'] ) ) {
            wp_send_json_error( 'Name is required.' ); return;
        }

        // [BUG-4] ability_type validated against allowlist
        if ( $payload['ability_type'] !== null
             && ! in_array( $payload['ability_type'], self::ABILITY_TYPES, true ) ) {
            wp_send_json_error( 'Invalid ability type.' ); return;
        }

        $res = $id
            ? $this->supa( 'PATCH', 'cyber_abilities?id=eq.' . rawurlencode( $id ), $payload )
            : $this->supa( 'POST',  'cyber_abilities', $payload );

        if ( isset( $res['error'] ) ) {
            wp_send_json_error( $res['error'] ); return;
        }

        $code = $res['code'] ?? 0;
        ( $code >= 200 && $code < 300 )
            ? wp_send_json_success( $res['data'][0] ?? $res['data'] )
            : wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: DELETE                                                      */
    /* ---------------------------------------------------------------- */

    public function ajax_delete(): void {
        check_ajax_referer( 'neoweaver_abilities', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 ); return;
        }

        // [BUG-3] UUID sanitisation — same pattern as ajax_save()
        $id = function_exists( 'tw_sanitize_supabase_id' )
            ? tw_sanitize_supabase_id( $_POST['ability_id'] ?? '' )
            : preg_replace( '/[^a-f0-9\-]/i', '', sanitize_text_field( $_POST['ability_id'] ?? '' ) );

        if ( ! $id ) {
            wp_send_json_error( 'Missing or invalid ID' ); return;
        }

        $res = $this->supa( 'DELETE', 'cyber_abilities?id=eq.' . rawurlencode( $id ) );
        isset( $res['error'] )
            ? wp_send_json_error( $res['error'] )
            : wp_send_json_success( 'deleted' );
    }

    /* ---------------------------------------------------------------- */
    /*  CSS  [BUG-6]                                                      */
    /* ---------------------------------------------------------------- */

    private function get_css(): string {
        return '
/* ===== NeoWeaver Abilities Admin ===== */
#nw-abilities-panel *{box-sizing:border-box}
#nw-abilities-panel,
#nw-abilities-panel input,
#nw-abilities-panel select,
#nw-abilities-panel textarea,
#nw-abilities-panel button{font-family:\'Chakra Petch\',sans-serif}

#nw-abilities-panel{padding:20px;background:#0d0d0d;min-height:100vh;color:#e0e0e0}

.nw-panel-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px}
.nw-panel-title{font-size:24px;font-weight:700;color:#fff;margin:0}
.nw-accent{color:#adff00}
.nw-panel-subtitle{font-size:16px;color:#888;margin-left:6px}
.nw-header-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}

.nw-btn{padding:7px 16px;border-radius:4px;border:none;cursor:pointer;font-size:13px;font-weight:600;transition:all .15s}
.nw-btn-primary{background:#adff00;color:#0d0d0d}
.nw-btn-primary:hover{background:#c8ff40}
.nw-btn-ghost{background:transparent;color:#adff00;border:1px solid #adff00}
.nw-btn-ghost:hover{background:#adff0015}
.nw-btn-danger{background:#ff4444;color:#fff}
.nw-btn-danger:hover{background:#ff6666}

.nw-search-input,.nw-select-filter{background:#1a1a1a;border:1px solid #333;color:#e0e0e0;border-radius:4px;padding:6px 10px;font-size:13px}
.nw-search-input{width:240px}
.nw-search-input:focus,.nw-select-filter:focus{outline:none;border-color:#adff00}

.nw-stats-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.nw-stat-pill{background:#1a1a1a;border:1px solid #2e2e2e;border-radius:20px;padding:4px 14px;font-size:12px;color:#aaa}
.nw-stat-pill strong{color:#adff00}
.nw-pill-passive strong{color:#00cfff}
.nw-pill-special strong{color:#ff9900}

.nw-notice{padding:10px 14px;border-radius:4px;margin-bottom:16px;font-size:13px}
.nw-notice.nw-notice-success{background:#1a2d0e;border:1px solid #adff00;color:#adff00}
.nw-notice.nw-notice-error{background:#2d0e0e;border:1px solid #ff4444;color:#ff8888}

.nw-table-wrap{overflow-x:auto;border-radius:6px;border:1px solid #2e2e2e}
.nw-table{width:100%;border-collapse:collapse;font-size:13px}
.nw-table thead tr{background:#1a1a1a}
.nw-table th{padding:10px 12px;text-align:left;color:#adff00;font-weight:600;border-bottom:1px solid #2e2e2e;white-space:nowrap}
.nw-table td{padding:9px 12px;border-bottom:1px solid #1e1e1e;color:#ccc;vertical-align:middle}
.nw-table tbody tr:hover{background:#151515}
.nw-table tbody tr:last-child td{border-bottom:none}
.nw-col-img{width:44px}
.nw-ability-thumb{width:36px;height:36px;object-fit:cover;border-radius:3px;border:1px solid #333}
.nw-ability-thumb-placeholder{width:36px;height:36px;background:#1e1e1e;border-radius:3px;display:inline-block}
.nw-loading-row td{text-align:center;padding:30px;color:#666}

.nw-tag{display:inline-block;background:#1e2a00;border:1px solid #3d5200;color:#adff00;border-radius:3px;padding:1px 7px;font-size:11px;margin:1px 2px 1px 0}

.nw-type-badge{display:inline-block;padding:2px 9px;border-radius:3px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.nw-type-active{background:#1a2d0e;color:#adff00}
.nw-type-passive{background:#0e1f2d;color:#00cfff}
.nw-type-reaction{background:#2d1a0e;color:#ff9900}
.nw-type-ultimate{background:#2d0e2d;color:#ff60ff}
.nw-type-racial{background:#0e2d2d;color:#00ffcc}
.nw-type-class{background:#2d2d0e;color:#ffee00}
.nw-type-item{background:#1e1e1e;color:#aaa}
.nw-type-special{background:#2d0e0e;color:#ff6060}

@keyframes nw-spin{to{transform:rotate(360deg)}}
.nw-spinner{display:inline-block;width:18px;height:18px;border:2px solid #333;border-top-color:#adff00;border-radius:50%;animation:nw-spin .7s linear infinite;vertical-align:middle;margin-right:6px}

.nw-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px}
.nw-modal{background:#141414;border:1px solid #2e2e2e;border-radius:8px;width:100%;max-width:700px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.7)}
.nw-modal-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid #2e2e2e}
.nw-modal-header h2{margin:0;font-size:16px;color:#adff00;font-weight:700}
.nw-modal-close{background:none;border:none;color:#888;font-size:18px;cursor:pointer;padding:4px 8px;border-radius:3px}
.nw-modal-close:hover{color:#fff;background:#2e2e2e}
.nw-modal-body{overflow-y:auto;padding:20px;flex:1}
.nw-modal-footer{display:flex;justify-content:flex-end;gap:10px;padding:14px 20px;border-top:1px solid #2e2e2e}

.nw-section-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#555;margin:14px 0 8px}
.nw-section-label:first-child{margin-top:0}
.nw-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.nw-field{display:flex;flex-direction:column;gap:4px}
.nw-field-full{grid-column:1/-1}
.nw-field label{font-size:12px;color:#888;font-weight:600}
.nw-req{color:#adff00}
.nw-hint{font-size:11px;color:#555;font-weight:400}
.nw-field input,.nw-field select,.nw-field textarea{background:#0d0d0d;border:1px solid #333;color:#e0e0e0;border-radius:4px;padding:7px 10px;font-size:13px;font-family:\'Chakra Petch\',sans-serif;transition:border-color .15s}
.nw-field input:focus,.nw-field select:focus,.nw-field textarea:focus{outline:none;border-color:#adff00}
.nw-field textarea{resize:vertical;min-height:80px}
.nw-select{width:100%}

@media(max-width:600px){
  .nw-form-grid{grid-template-columns:1fr}
  .nw-field-full{grid-column:1}
  .nw-header-actions{flex-direction:column;align-items:stretch}
  .nw-search-input{width:100%}
}';
    }

    /* ---------------------------------------------------------------- */
    /*  JS  [BUG-6]                                                       */
    /* ---------------------------------------------------------------- */

    private function get_js(): string {
        return <<<'JS'
(function($){
'use strict';

var abilities = [];
var nonce     = $('#nw-nonce').val();

function notice(msg, type){
    $('#nw-notice')
        .text(msg)
        .removeClass('nw-notice-success nw-notice-error')
        .addClass(type === 'error' ? 'nw-notice-error' : 'nw-notice-success')
        .show();
    if (type !== 'error') setTimeout(function(){ $('#nw-notice').fadeOut(); }, 3000);
}

function typeBadge(t){
    if (!t) return '';
    var cls = 'nw-type-' + t.toLowerCase().replace(/[^a-z]/g,'');
    return '<span class="nw-type-badge ' + cls + '">' + $('<span>').text(t).html() + '</span>';
}

function renderTags(tags){
    if (!tags || !tags.length) return '';
    return tags.map(function(t){
        return '<span class="nw-tag">' + $('<span>').text(t).html() + '</span>';
    }).join('');
}

function updateStats(){
    var total   = abilities.length;
    var active  = abilities.filter(function(a){ return a.ability_type === 'Active';  }).length;
    var passive = abilities.filter(function(a){ return a.ability_type === 'Passive'; }).length;
    var other   = total - active - passive;
    $('#nw-total').text(total);
    $('#nw-active-count').text(active);
    $('#nw-passive-count').text(passive);
    $('#nw-other-count').text(other);
}

function renderTable(list){
    var $tbody = $('#nw-abilities-tbody');
    if (!list.length){
        $tbody.html('<tr><td colspan="7" style="text-align:center;color:#555;padding:30px;">No abilities found.</td></tr>');
        return;
    }
    var rows = list.map(function(a){
        var img = a.img_url
            ? '<img class="nw-ability-thumb" src="' + $('<span>').text(a.img_url).html() + '" alt="" loading="lazy">'
            : '<span class="nw-ability-thumb-placeholder"></span>';
        return [
            '<tr data-id="' + $('<span>').text(a.id).html() + '">',
            '<td>' + img + '</td>',
            '<td><strong>' + $('<span>').text(a.name).html() + '</strong></td>',
            '<td>' + typeBadge(a.ability_type) + '</td>',
            '<td>' + $('<span>').text(a.source || '').html() + '</td>',
            '<td>' + $('<span>').text(a.cost || '').html() + '</td>',
            '<td>' + renderTags(a.tags) + '</td>',
            '<td><button class="nw-btn nw-btn-ghost nw-edit-btn" data-id="'
                + $('<span>').text(a.id).html() + '">Edit</button></td>',
            '</tr>',
        ].join('');
    });
    $tbody.html(rows.join(''));
}

function applyFilters(){
    var type   = $('#nw-filter-type').val().toLowerCase();
    var search = $('#nw-search').val().toLowerCase().trim();
    var list   = abilities.filter(function(a){
        var matchType   = !type || (a.ability_type || '').toLowerCase() === type;
        var tags        = Array.isArray(a.tags) ? a.tags.join(',').toLowerCase() : '';
        var matchSearch = !search
            || (a.name   || '').toLowerCase().includes(search)
            || (a.source || '').toLowerCase().includes(search)
            || tags.includes(search);
        return matchType && matchSearch;
    });
    renderTable(list);
}

function loadAbilities(){
    $('#nw-abilities-tbody').html(
        '<tr class="nw-loading-row"><td colspan="7"><div class="nw-spinner"></div> Loading abilities…</td></tr>'
    );
    $.post(ajaxurl, {
        action:      'nw_abilities_get_all',
        nonce:       nonce,
        filter_type: $('#nw-filter-type').val()
    }, function(res){
        if (!res.success){ notice(res.data || 'Load failed', 'error'); return; }
        abilities = res.data || [];
        updateStats();
        applyFilters();
    });
}

function openModal(ability){
    var editing = !!ability;
    $('#nw-modal-title').text(editing ? 'Edit Ability' : 'New Ability');
    $('#nw-field-id').val(editing ? ability.id : '');
    $('#nw-field-name').val(editing ? ability.name : '');
    $('#nw-field-ability_type').val(editing ? (ability.ability_type || '') : '');
    $('#nw-field-cost').val(editing ? (ability.cost || '') : '');
    $('#nw-field-source').val(editing ? (ability.source || '') : '');
    $('#nw-field-tags').val(editing
        ? (Array.isArray(ability.tags) ? ability.tags.join(', ') : '')
        : '');
    $('#nw-field-description').val(editing ? (ability.description || '') : '');
    $('#nw-field-gm_notes').val(editing ? (ability.gm_notes || '') : '');
    $('#nw-field-img_url').val(editing ? (ability.img_url || '') : '');

    if (editing && ability.img_url){
        $('#nw-img-preview').attr('src', ability.img_url);
        $('#nw-img-preview-wrap').show();
    } else {
        $('#nw-img-preview-wrap').hide();
    }

    $('#nw-delete-btn').toggle(editing);
    $('#nw-save-label').text(editing ? 'Update Ability' : 'Save Ability');
    $('#nw-modal-overlay').show();
    setTimeout(function(){ $('#nw-field-name').focus(); }, 50);
}

function closeModal(){
    $('#nw-modal-overlay').hide();
    $('#nw-ability-form')[0].reset();
    $('#nw-img-preview-wrap').hide();
}

function saveAbility(){
    var $btn    = $('#nw-save-btn');
    var editing = !!$('#nw-field-id').val();
    $btn.prop('disabled', true).html('<div class="nw-spinner"></div>');

    var formData = {};
    $('#nw-ability-form').serializeArray().forEach(function(f){ formData[f.name] = f.value; });
    formData.nonce  = nonce;
    formData.action = 'nw_abilities_save';

    $.post(ajaxurl, formData, function(res){
        $btn.prop('disabled', false)
            .html('<span id="nw-save-label">' + (editing ? 'Update Ability' : 'Save Ability') + '</span>');
        if (!res.success){ notice(res.data || 'Save failed', 'error'); return; }
        notice('Ability saved!', 'success');
        closeModal();
        loadAbilities();
    });
}

function deleteAbility(){
    var id = $('#nw-field-id').val();
    if (!id || !confirm('Delete this ability? This cannot be undone.')) return;
    $('#nw-delete-btn').prop('disabled', true);
    $.post(ajaxurl, { action:'nw_abilities_delete', nonce:nonce, ability_id:id }, function(res){
        $('#nw-delete-btn').prop('disabled', false);
        if (!res.success){ notice(res.data || 'Delete failed', 'error'); return; }
        notice('Ability deleted.', 'success');
        closeModal();
        loadAbilities();
    });
}

$(document)
    .on('click', '#nw-add-btn',     function(){ openModal(null); })
    .on('click', '#nw-refresh-btn', loadAbilities)
    .on('click', '#nw-modal-close', closeModal)
    .on('click', '#nw-cancel-btn',  closeModal)
    .on('click', '#nw-save-btn',    saveAbility)
    .on('click', '#nw-delete-btn',  deleteAbility)
    .on('click', '.nw-edit-btn',    function(){
        var id = $(this).data('id');
        var a  = abilities.find(function(x){ return x.id == id; });
        if (a) openModal(a);
    })
    .on('click', '#nw-modal-overlay', function(e){
        if ($(e.target).is('#nw-modal-overlay')) closeModal();
    })
    .on('keydown', function(e){
        if (e.key === 'Escape' && $('#nw-modal-overlay').is(':visible')) closeModal();
    });

$('#nw-filter-type').on('change', loadAbilities);
$('#nw-search').on('input', applyFilters);

$('#nw-field-img_url').on('input', function(){
    var val = $(this).val().trim();
    if (val){ $('#nw-img-preview').attr('src', val); $('#nw-img-preview-wrap').show(); }
    else    { $('#nw-img-preview-wrap').hide(); }
});

$(function(){ loadAbilities(); });

})(jQuery);
JS;
    }

}

// [BUG-10] Instantiate only after plugins_loaded (priority 20) — supabase-helpers.php
// is guaranteed to be loaded by NeoWeaver_Core at priority 10.
add_action( 'plugins_loaded', function() {
    if ( is_admin() ) {
        new NeoWeaver_Abilities_Admin();
    }
}, 20 );
