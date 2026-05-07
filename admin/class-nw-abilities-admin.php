<?php
/**
 * NeoWeaver Admin Panel — Abilities (cyber_abilities)
 *
 * Columns: id, name, description, ability_type, source,
 *          gm_notes, cost, img_url, tags, created_at
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Abilities_Admin {

    private string $supabase_url;
    private string $supabase_key;
    private string $page_slug   = 'neoweaver-abilities';
    private string $parent_slug = 'neoweaver';

    public function __construct() {
        $this->supabase_url = rtrim( nw_supabase_url(), '/' );
        $this->supabase_key = nw_supabase_key();

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
        wp_enqueue_style( 'chakra-petch',
            'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap', [], null );
        wp_add_inline_style( 'chakra-petch', $this->get_css() );
        wp_add_inline_script( 'jquery', $this->get_js() );
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
                        <?php foreach ( $this->ability_types() as $t ) : ?>
                        <option value="<?php echo esc_attr($t); ?>"><?php echo esc_html($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" id="nw-search" class="nw-search-input" placeholder="🔍 Search name…">
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
                                        <?php foreach ( $this->ability_types() as $t ) : ?>
                                        <option value="<?php echo esc_attr($t); ?>"><?php echo esc_html($t); ?></option>
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
                                        <img id="nw-img-preview" src="" alt="preview" style="max-height:80px;border-radius:4px;border:1px solid #2e2e2e;">
                                    </div>
                                </div>

                            </div>

                        </form>
                    </div><!-- .nw-modal-body -->
                    <div class="nw-modal-footer">
                        <button class="nw-btn nw-btn-danger" id="nw-delete-btn" style="display:none;margin-right:auto;">🗑 Delete</button>
                        <button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
                        <button class="nw-btn nw-btn-primary" id="nw-save-btn"><span id="nw-save-label">Save Ability</span></button>
                    </div>
                </div>
            </div><!-- .nw-modal-overlay -->

            <input type="hidden" id="nw-nonce" value="<?php echo esc_attr( wp_create_nonce( 'neoweaver_abilities' ) ); ?>">
        </div>
    <?php }

    /* ---------------------------------------------------------------- */
    /*  DATA LISTS                                                        */
    /* ---------------------------------------------------------------- */

    private function ability_types(): array {
        return ['Active', 'Passive', 'Reaction', 'Ultimate', 'Racial', 'Class', 'Item', 'Special'];
    }

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
        return [
            'code' => wp_remote_retrieve_response_code( $res ),
            'data' => json_decode( wp_remote_retrieve_body( $res ), true ),
        ];
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: GET ALL                                                     */
    /* ---------------------------------------------------------------- */

    public function ajax_get_all(): void {
        check_ajax_referer( 'neoweaver_abilities', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $type = sanitize_text_field( $_POST['filter_type'] ?? '' );
        $qs   = 'cyber_abilities?select=id,name,description,ability_type,source,gm_notes,cost,img_url,tags,created_at&order=name.asc';
        if ( $type ) $qs .= '&ability_type=eq.' . urlencode( $type );

        $res = $this->supa( 'GET', $qs );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( $res['data'] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: SAVE                                                        */
    /* ---------------------------------------------------------------- */

    public function ajax_save(): void {
        check_ajax_referer( 'neoweaver_abilities', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $raw  = $_POST['ability'] ?? [];
        $id   = sanitize_text_field( $raw['id'] ?? '' );
        $tags = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $raw['tags'] ?? '' ) ) ) ) );

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

        $res = $id
            ? $this->supa( 'PATCH', 'cyber_abilities?id=eq.' . urlencode( $id ), $payload )
            : $this->supa( 'POST',  'cyber_abilities', $payload );

        if ( isset( $res['error'] ) ) { wp_send_json_error( $res['error'] ); }
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
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $id = sanitize_text_field( $_POST['ability_id'] ?? '' );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );
        $res = $this->supa( 'DELETE', 'cyber_abilities?id=eq.' . urlencode( $id ), [], [ 'Prefer' => '' ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( 'deleted' );
    }

    /* ---------------------------------------------------------------- */
    /*  CSS                                                               */
    /* ---------------------------------------------------------------- */

    private function get_css(): string { return <<<'CSS'
.nw-panel{font-family:'Chakra Petch',monospace;color:#e0e0e0}.nw-panel *{box-sizing:border-box}
.nw-panel-header{display:flex;align-items:center;justify-content:space-between;padding:20px 0 16px;border-bottom:1px solid #2a2a2a;margin-bottom:16px;flex-wrap:wrap;gap:10px}
.nw-panel-title{font-size:22px;font-weight:700;color:#fff;margin:0;font-family:'Chakra Petch',monospace}
.nw-accent{color:#adff00}.nw-panel-subtitle{color:#555;font-weight:400;font-size:18px;margin-left:4px}
.nw-header-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.nw-select-filter{font-family:'Chakra Petch',monospace;font-size:12px;background:#0d0d0d;border:1px solid #2a2a2a;border-radius:5px;color:#ccc;padding:6px 10px;cursor:pointer}
.nw-select-filter:focus{outline:none;border-color:#adff00}
.nw-search-input{font-family:'Chakra Petch',monospace;font-size:12px;background:#0d0d0d;border:1px solid #2a2a2a;border-radius:5px;color:#e0e0e0;padding:6px 10px;width:180px}
.nw-search-input:focus{outline:none;border-color:#adff00}
.nw-btn{font-family:'Chakra Petch',monospace;font-size:12px;font-weight:600;padding:7px 16px;border-radius:5px;border:1px solid transparent;cursor:pointer;transition:all .15s;text-transform:uppercase;letter-spacing:.5px}
.nw-btn-primary{background:#adff00;color:#0a0a0a;border-color:#adff00}.nw-btn-primary:hover{background:#c8ff40}
.nw-btn-ghost{background:transparent;color:#adff00;border-color:#2e2e2e}.nw-btn-ghost:hover{border-color:#adff00}
.nw-btn-danger{background:transparent;color:#ff4444;border-color:#3a1111}.nw-btn-danger:hover{background:#2a0000;border-color:#ff4444}
.nw-stats-bar{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.nw-stat-pill{font-size:12px;padding:4px 12px;border-radius:20px;background:#1a1a1a;border:1px solid #2e2e2e;color:#aaa}
.nw-stat-pill strong{color:#fff}
.nw-pill-active{border-color:#adff00}.nw-pill-active strong{color:#adff00}
.nw-pill-passive{border-color:#4da6ff}.nw-pill-passive strong{color:#4da6ff}
.nw-pill-special{border-color:#b04dff}.nw-pill-special strong{color:#b04dff}
.nw-notice{padding:10px 16px;border-radius:6px;margin-bottom:14px;font-size:13px;border-left:3px solid}
.nw-notice-success{background:#0a2800;border-color:#adff00;color:#adff00}.nw-notice-error{background:#2a0000;border-color:#ff4444;color:#ff4444}
.nw-table-wrap{background:#111;border:1px solid #222;border-radius:8px;overflow:hidden}
.nw-table{width:100%;border-collapse:collapse;font-size:13px}
.nw-table thead tr{background:#1a1a1a;border-bottom:1px solid #2a2a2a}
.nw-table th{padding:10px 14px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#666;font-weight:600}
.nw-table tbody tr{border-bottom:1px solid #1e1e1e;transition:background .12s}
.nw-table tbody tr:last-child{border-bottom:none}.nw-table tbody tr:hover{background:#161616}
.nw-table td{padding:10px 14px;vertical-align:middle}.nw-col-img{width:50px}
.nw-ability-img{width:40px;height:40px;border-radius:6px;object-fit:cover;border:1px solid #2e2e2e;background:#1a1a1a}
.nw-ability-img-placeholder{width:40px;height:40px;border-radius:6px;background:#1a1a1a;border:1px solid #2e2e2e;display:flex;align-items:center;justify-content:center;color:#444;font-size:20px}
.nw-ability-name{font-weight:600;color:#fff}
.nw-ability-desc{font-size:11px;color:#666;margin-top:3px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.nw-type-badge{display:inline-block;font-size:10px;padding:2px 9px;border-radius:3px;text-transform:uppercase;letter-spacing:.5px;font-weight:700;white-space:nowrap}
.nw-type-active{background:#1a2800;border:1px solid #adff00;color:#adff00}
.nw-type-passive{background:#001a3a;border:1px solid #4da6ff;color:#4da6ff}
.nw-type-reaction{background:#2a1a00;border:1px solid #ff9f00;color:#ff9f00}
.nw-type-ultimate{background:#2a0020;border:1px solid #ff4da6;color:#ff4da6}
.nw-type-racial{background:#1a0a2a;border:1px solid #b04dff;color:#b04dff}
.nw-type-class{background:#0a2a2a;border:1px solid #4dffee;color:#4dffee}
.nw-type-item{background:#2a2000;border:1px solid #e8af34;color:#e8af34}
.nw-type-special{background:#1a1a1a;border:1px solid #888;color:#aaa}
.nw-source{font-size:11px;color:#666;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.nw-cost-badge{font-size:11px;padding:2px 8px;background:#1e1e1e;border:1px solid #2e2e2e;border-radius:3px;color:#aaa;white-space:nowrap}
.nw-tags{display:flex;flex-wrap:wrap;gap:4px}
.nw-tag{font-size:10px;padding:2px 7px;background:#1e1e1e;border:1px solid #2e2e2e;border-radius:3px;color:#888}
.nw-row-actions{display:flex;gap:6px}
.nw-action-btn{font-family:'Chakra Petch',monospace;font-size:11px;padding:4px 10px;border-radius:4px;border:1px solid #2e2e2e;background:transparent;color:#aaa;cursor:pointer;transition:all .15s;text-transform:uppercase}
.nw-action-btn:hover{border-color:#adff00;color:#adff00}
.nw-loading-row td{text-align:center;padding:32px;color:#555}
.nw-spinner{display:inline-block;width:16px;height:16px;border:2px solid #333;border-top-color:#adff00;border-radius:50%;animation:nw-spin .6s linear infinite;vertical-align:middle;margin-right:8px}
@keyframes nw-spin{to{transform:rotate(360deg)}}
.nw-hidden{display:none!important}
.nw-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:99998;display:flex;align-items:center;justify-content:center;padding:20px}
.nw-modal{background:#111;border:1px solid #2e2e2e;border-radius:10px;width:100%;max-width:700px;max-height:92vh;overflow-y:auto;display:flex;flex-direction:column;font-family:'Chakra Petch',monospace}
.nw-modal-header{display:flex;align-items:center;justify-content:space-between;padding:18px 24px 14px;border-bottom:1px solid #1e1e1e;position:sticky;top:0;background:#111;z-index:1}
.nw-modal-header h2{margin:0;font-size:16px;color:#fff;font-family:'Chakra Petch',monospace}
.nw-modal-close{background:none;border:none;color:#666;font-size:18px;cursor:pointer;padding:2px 6px;border-radius:4px}
.nw-modal-close:hover{color:#fff;background:#222}
.nw-modal-body{padding:20px 24px;flex:1}
.nw-modal-footer{padding:14px 24px;border-top:1px solid #1e1e1e;display:flex;justify-content:flex-end;align-items:center;gap:10px;position:sticky;bottom:0;background:#111}
.nw-section-label{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#adff00;font-weight:700;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #1e2e00}
.nw-section-label:first-child{margin-top:0}
.nw-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.nw-field{display:flex;flex-direction:column;gap:5px}.nw-field-full{grid-column:1/-1}
.nw-field label{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#666;font-weight:600}
.nw-req{color:#ff4444}.nw-hint{font-size:10px;color:#444;text-transform:none;letter-spacing:0;font-weight:400}
.nw-field input[type="text"],.nw-field input[type="url"],.nw-field textarea,.nw-select{background:#0d0d0d;border:1px solid #2a2a2a;border-radius:5px;color:#e0e0e0;padding:8px 10px;font-family:'Chakra Petch',monospace;font-size:13px;transition:border-color .15s;width:100%}
.nw-field input:focus,.nw-field textarea:focus,.nw-select:focus{outline:none;border-color:#adff00;box-shadow:0 0 0 2px rgba(173,255,0,.08)}
.nw-field textarea{resize:vertical}.nw-select option{background:#111}
CSS;
    }

    /* ---------------------------------------------------------------- */
    /*  JS                                                                */
    /* ---------------------------------------------------------------- */

    private function get_js(): string { return <<<'JS'
jQuery(function($){
    var nonce=$("#nw-nonce").val(), all=[], filtered=[];

    var typeClass={
        'Active':'nw-type-active','Passive':'nw-type-passive','Reaction':'nw-type-reaction',
        'Ultimate':'nw-type-ultimate','Racial':'nw-type-racial','Class':'nw-type-class',
        'Item':'nw-type-item','Special':'nw-type-special'
    };

    function esc(s){return $('<span>').text(s||'').html();}
    function notice(msg,type){var el=$("#nw-notice");el.attr("class","nw-notice nw-notice-"+type).text(msg).show();setTimeout(function(){el.fadeOut(300);},3500);}
    function tagsStr(t){if(!t)return'';if(Array.isArray(t))return t.join(', ');try{var a=JSON.parse(t);return Array.isArray(a)?a.join(', '):t;}catch(e){return t;}}

    function updateStats(data){
        var active=data.filter(function(a){return a.ability_type==='Active';}).length;
        var passive=data.filter(function(a){return a.ability_type==='Passive';}).length;
        $("#nw-total").text(data.length);
        $("#nw-active-count").text(active);
        $("#nw-passive-count").text(passive);
        $("#nw-other-count").text(data.length-active-passive);
    }

    function renderTable(data){
        var tbody=$("#nw-abilities-tbody");
        if(!data.length){tbody.html('<tr><td colspan="7" style="text-align:center;padding:32px;color:#555;">No abilities found.</td></tr>');return;}
        tbody.html(data.map(function(a){
            var tags=Array.isArray(a.tags)?a.tags:[];
            var tagsH=tags.slice(0,3).map(function(t){return'<span class="nw-tag">'+esc(t)+'</span>';}).join('')+(tags.length>3?'<span class="nw-tag">+'+(tags.length-3)+'</span>':'');
            var tc=typeClass[a.ability_type]||'nw-type-special';
            var typeH=a.ability_type?'<span class="nw-type-badge '+tc+'">'+esc(a.ability_type)+'</span>':'—';
            var imgH=a.img_url?'<img src="'+esc(a.img_url)+'" class="nw-ability-img" loading="lazy" onerror="this.style.display=\'none\'">':'<div class="nw-ability-img-placeholder">✨</div>';
            return'<tr data-id="'+a.id+'">'
                +'<td>'+imgH+'</td>'
                +'<td><div class="nw-ability-name">'+esc(a.name)+'</div><div class="nw-ability-desc">'+esc(a.description||'')+'</div></td>'
                +'<td>'+typeH+'</td>'
                +'<td><div class="nw-source">'+esc(a.source||'—')+'</div></td>'
                +'<td>'+(a.cost?'<span class="nw-cost-badge">'+esc(a.cost)+'</span>':'<span style="color:#444">—</span>')+'</td>'
                +'<td><div class="nw-tags">'+tagsH+'</div></td>'
                +'<td><div class="nw-row-actions"><button class="nw-action-btn nw-edit-btn" data-id="'+a.id+'">Edit</button></div></td>'
                +'</tr>';
        }).join(''));
    }

    function applySearch(){
        var q=$("#nw-search").val().toLowerCase().trim();
        var shown=q?filtered.filter(function(a){return a.name.toLowerCase().includes(q)||(a.source||'').toLowerCase().includes(q);}) : filtered;
        renderTable(shown);
    }

    function loadAll(){
        var ft=$("#nw-filter-type").val();
        $("#nw-abilities-tbody").html('<tr class="nw-loading-row"><td colspan="7"><div class="nw-spinner"></div> Loading…</td></tr>');
        $.post(ajaxurl,{action:"nw_abilities_get_all",nonce:nonce,filter_type:ft},function(res){
            if(!res.success){notice("Error: "+res.data,"error");return;}
            all=filtered=res.data||[];
            updateStats(all);
            applySearch();
        }).fail(function(){notice("Request failed.","error");});
    }

    $("#nw-filter-type").on("change",loadAll);
    $("#nw-search").on("input",applySearch);

    /* modal */
    function openModal(id){
        $("#nw-ability-form")[0].reset();
        $("#nw-field-id").val("");
        $("#nw-img-preview-wrap").hide();
        if(id){
            var a=all.find(function(x){return x.id===id;});
            if(a){
                $("#nw-field-id").val(a.id);
                $("#nw-field-name").val(a.name||"");
                $("#nw-field-description").val(a.description||"");
                $("#nw-field-gm_notes").val(a.gm_notes||"");
                $("#nw-field-ability_type").val(a.ability_type||"");
                $("#nw-field-source").val(a.source||"");
                $("#nw-field-cost").val(a.cost||"");
                $("#nw-field-tags").val(tagsStr(a.tags));
                if(a.img_url){
                    $("#nw-field-img_url").val(a.img_url);
                    $("#nw-img-preview").attr("src",a.img_url);
                    $("#nw-img-preview-wrap").show();
                }
            }
            $("#nw-modal-title").text("Edit Ability");
            $("#nw-save-label").text("Save Changes");
            $("#nw-delete-btn").show().data("id",id);
        } else {
            $("#nw-modal-title").text("New Ability");
            $("#nw-save-label").text("Create Ability");
            $("#nw-delete-btn").hide();
        }
        $("#nw-modal-overlay").fadeIn(150);
    }

    $("#nw-field-img_url").on("input",function(){
        var v=$(this).val().trim();
        if(v){$("#nw-img-preview").attr("src",v);$("#nw-img-preview-wrap").show();}
        else{$("#nw-img-preview-wrap").hide();}
    });

    $("#nw-modal-close,#nw-cancel-btn").on("click",function(){$("#nw-modal-overlay").fadeOut(150);});
    $("#nw-modal-overlay").on("click",function(e){if($(e.target).is("#nw-modal-overlay"))$("#nw-modal-overlay").fadeOut(150);});
    $(document).on("click",".nw-edit-btn",function(){openModal($(this).data("id"));});
    $("#nw-add-btn").on("click",function(){openModal(null);});
    $("#nw-refresh-btn").on("click",loadAll);

    $("#nw-save-btn").on("click",function(){
        if(!$("#nw-field-name").val().trim()){notice("Name is required.","error");return;}
        var btn=$(this);btn.prop("disabled",true);$("#nw-save-label").text("Saving…");
        var fd={action:"nw_abilities_save",nonce:nonce,"ability":{}};
        $("#nw-ability-form").serializeArray().forEach(function(f){ fd["ability"][f.name]=f.value; });
        $.post(ajaxurl,fd,function(res){
            btn.prop("disabled",false);$("#nw-save-label").text("Save Changes");
            if(res.success){notice("Ability saved!","success");$("#nw-modal-overlay").fadeOut(150);loadAll();}
            else{notice("Error: "+(res.data||"Unknown"),"error");}
        }).fail(function(){btn.prop("disabled",false);$("#nw-save-label").text("Save Changes");notice("Request failed.","error");});
    });

    $("#nw-delete-btn").on("click",function(){
        var id=$(this).data("id");
        if(!id||!confirm("Delete this ability permanently?"))return;
        $.post(ajaxurl,{action:"nw_abilities_delete",nonce:nonce,ability_id:id},function(res){
            if(res.success){notice("Ability deleted.","success");$("#nw-modal-overlay").fadeOut(150);loadAll();}
            else{notice("Delete failed: "+res.data,"error");}
        });
    });

    loadAll();
});
JS;
    }
}

new NeoWeaver_Abilities_Admin();
