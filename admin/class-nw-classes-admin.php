<?php
/**
 * NeoWeaver Admin Panel — Classes (cyberclasses)
 * Schema: id, name, description, tags (jsonb), starting_gold, gm_instructions,
 *         ai_personality_modifier, mechanics, attribute_bonuses (jsonb),
 *         vulnerability, icon_slug, img_url, is_active, skill_limit, created_at
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Classes_Admin {

    private string $supabase_url;
    private string $supabase_key;
    private string $page_slug   = 'neoweaver-classes';
    private string $parent_slug = 'neoweaver';

    public function __construct() {
        $this->supabase_url = rtrim( tw_supabase_url(), '/' );
        $this->supabase_key = tw_supabase_anon_key();

        add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_nw_classes_get_all', [ $this, 'ajax_get_all' ] );
        add_action( 'wp_ajax_nw_classes_save',    [ $this, 'ajax_save'    ] );
        add_action( 'wp_ajax_nw_classes_delete',  [ $this, 'ajax_delete'  ] );
    }

    /* ---------------------------------------------------------------- */
    /*  MENU                                                              */
    /* ---------------------------------------------------------------- */

    public function register_menu(): void {
        add_submenu_page(
            $this->parent_slug,
            'NeoWeaver — Classes',
            '⚔️ Classes',
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
        <div class="wrap nw-panel" id="nw-classes-panel">

            <div class="nw-panel-header">
                <h1 class="nw-panel-title">
                    <span class="nw-accent">Neo</span>Weaver
                    <span class="nw-panel-subtitle">/ Classes</span>
                </h1>
                <div class="nw-header-actions">
                    <input type="text" id="nw-search" class="nw-search-input" placeholder="🔍 Search name…">
                    <button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">↻ Refresh</button>
                    <button class="nw-btn nw-btn-primary" id="nw-add-btn">+ New Class</button>
                </div>
            </div>

            <div id="nw-notice" class="nw-notice" style="display:none;"></div>

            <div class="nw-stats-bar">
                <span class="nw-stat-pill">Total: <strong id="nw-total">—</strong></span>
                <span class="nw-stat-pill">Active: <strong id="nw-active">—</strong></span>
            </div>

            <div class="nw-table-wrap">
                <table class="nw-table">
                    <thead><tr>
                        <th class="nw-col-img"></th>
                        <th>Name</th>
                        <th>Tags</th>
                        <th>Gold</th>
                        <th>Skill Limit</th>
                        <th>Vulnerability</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody id="nw-classes-tbody">
                        <tr class="nw-loading-row"><td colspan="8"><div class="nw-spinner"></div> Loading classes…</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- ==================== MODAL ==================== -->
            <div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
                <div class="nw-modal">
                    <div class="nw-modal-header">
                        <h2 id="nw-modal-title">Edit Class</h2>
                        <button class="nw-modal-close" id="nw-modal-close">✕</button>
                    </div>
                    <div class="nw-modal-body">
                        <form id="nw-class-form">
                            <input type="hidden" id="nw-field-id" name="id">

                            <div class="nw-section-label">Identity</div>
                            <div class="nw-form-grid">

                                <div class="nw-field nw-field-full">
                                    <label>Name <span class="nw-req">*</span></label>
                                    <input type="text" id="nw-field-name" name="name" required placeholder="e.g. Shadowblade">
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>Description</label>
                                    <textarea id="nw-field-description" name="description" rows="2" placeholder="Short class description visible to players…"></textarea>
                                </div>

                                <div class="nw-field">
                                    <label>Icon Slug</label>
                                    <input type="text" id="nw-field-icon_slug" name="icon_slug" placeholder="e.g. shadowblade">
                                </div>

                                <div class="nw-field">
                                    <label>Starting Gold</label>
                                    <input type="number" id="nw-field-starting_gold" name="starting_gold" min="0" placeholder="100">
                                </div>

                                <div class="nw-field">
                                    <label>Skill Limit</label>
                                    <input type="number" id="nw-field-skill_limit" name="skill_limit" min="0" placeholder="3">
                                </div>

                                <div class="nw-field">
                                    <label>Vulnerability</label>
                                    <input type="text" id="nw-field-vulnerability" name="vulnerability" placeholder="e.g. Fire, Poison">
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>Tags <span class="nw-hint">(comma-separated → JSON array)</span></label>
                                    <input type="text" id="nw-field-tags" name="tags" placeholder="e.g. Stealth, Melee, Shadow">
                                </div>

                                <div class="nw-field">
                                    <label>Active</label>
                                    <select id="nw-field-is_active" name="is_active">
                                        <option value="1">Yes</option>
                                        <option value="0">No</option>
                                    </select>
                                </div>

                            </div>

                            <div class="nw-section-label">Mechanics &amp; Lore</div>
                            <div class="nw-form-grid">

                                <div class="nw-field nw-field-full">
                                    <label>Mechanics <span class="nw-hint">(shown to players)</span></label>
                                    <textarea id="nw-field-mechanics" name="mechanics" rows="3" placeholder="Class mechanics description…"></textarea>
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>GM Instructions <span class="nw-hint">(internal / AI context)</span></label>
                                    <textarea id="nw-field-gm_instructions" name="gm_instructions" rows="3" placeholder="GM/AI hints…"></textarea>
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>AI Personality Modifier</label>
                                    <textarea id="nw-field-ai_personality_modifier" name="ai_personality_modifier" rows="2" placeholder="How AI narrates this class…"></textarea>
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>Attribute Bonuses <span class="nw-hint">(JSON object, e.g. {"Reflex":2,"Mind":1})</span></label>
                                    <input type="text" id="nw-field-attribute_bonuses" name="attribute_bonuses" placeholder='{"Reflex":2,"Mind":1}'>
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
                        <button class="nw-btn nw-btn-primary" id="nw-save-btn"><span id="nw-save-label">Save Class</span></button>
                    </div>
                </div>
            </div>

            <input type="hidden" id="nw-nonce" value="<?php echo esc_attr( wp_create_nonce( 'neoweaver_classes' ) ); ?>">
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
        return [
            'code' => wp_remote_retrieve_response_code( $res ),
            'data' => json_decode( wp_remote_retrieve_body( $res ), true ),
        ];
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: GET ALL                                                     */
    /* ---------------------------------------------------------------- */

    public function ajax_get_all(): void {
        check_ajax_referer( 'neoweaver_classes', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $res = $this->supa( 'GET',
            'cyber_classes?select=id,name,description,tags,starting_gold,gm_instructions,mechanics,icon_slug,ai_personality_modifier,attribute_bonuses,vulnerability,img_url,is_active,skill_limit,created_at&order=name.asc'
        );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( $res['data'] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: SAVE                                                        */
    /* ---------------------------------------------------------------- */

    public function ajax_save(): void {
        check_ajax_referer( 'neoweaver_classes', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $raw  = $_POST['nw_class'] ?? [];
        $id   = sanitize_text_field( $raw['id'] ?? '' );

        // tags: comma-separated → JSON array
        $tags = array_values( array_filter( array_map( 'trim',
            explode( ',', sanitize_text_field( $raw['tags'] ?? '' ) )
        ) ) );

        // attribute_bonuses: JSON string → decoded object (jsonb column)
        $ab_raw = trim( sanitize_text_field( $raw['attribute_bonuses'] ?? '' ) );
        $attribute_bonuses = null;
        if ( $ab_raw ) {
            $decoded = json_decode( $ab_raw, true );
            $attribute_bonuses = ( json_last_error() === JSON_ERROR_NONE ) ? $decoded : null;
        }

        $payload = [
            'name'                    => sanitize_text_field(     $raw['name']                    ?? '' ),
            'description'             => sanitize_textarea_field( $raw['description']             ?? '' ) ?: null,
            'icon_slug'               => sanitize_text_field(     $raw['icon_slug']               ?? '' ) ?: null,
            'vulnerability'           => sanitize_text_field(     $raw['vulnerability']           ?? '' ) ?: null,
            'attribute_bonuses'       => $attribute_bonuses,
            'mechanics'               => sanitize_textarea_field( $raw['mechanics']               ?? '' ) ?: null,
            'gm_instructions'         => sanitize_textarea_field( $raw['gm_instructions']         ?? '' ) ?: null,
            'ai_personality_modifier' => sanitize_textarea_field( $raw['ai_personality_modifier'] ?? '' ) ?: null,
            'img_url'                 => esc_url_raw(             $raw['img_url']                 ?? '' ) ?: null,
            'starting_gold'           => isset( $raw['starting_gold'] ) && $raw['starting_gold'] !== '' ? (int) $raw['starting_gold'] : 100,
            'skill_limit'             => isset( $raw['skill_limit']   ) && $raw['skill_limit']   !== '' ? (int) $raw['skill_limit']   : 3,
            'is_active'               => ( ( $raw['is_active'] ?? '1' ) === '1' ),
            'tags'                    => $tags,
        ];

        $res = $id
            ? $this->supa( 'PATCH', 'cyber_classes?id=eq.' . urlencode( $id ), $payload )
            : $this->supa( 'POST',  'cyber_classes', $payload );

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
        check_ajax_referer( 'neoweaver_classes', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $id = sanitize_text_field( $_POST['class_id'] ?? '' );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );
        $res = $this->supa( 'DELETE', 'cyber_classes?id=eq.' . urlencode( $id ), [], [ 'Prefer' => '' ] );
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
.nw-search-input{font-family:'Chakra Petch',monospace;font-size:12px;background:#0d0d0d;border:1px solid #2a2a2a;border-radius:5px;color:#e0e0e0;padding:6px 10px;width:180px}
.nw-search-input:focus{outline:none;border-color:#adff00}
.nw-btn{font-family:'Chakra Petch',monospace;font-size:12px;font-weight:600;padding:7px 16px;border-radius:5px;border:1px solid transparent;cursor:pointer;transition:all .15s;text-transform:uppercase;letter-spacing:.5px}
.nw-btn-primary{background:#adff00;color:#0a0a0a;border-color:#adff00}.nw-btn-primary:hover{background:#c8ff40}
.nw-btn-ghost{background:transparent;color:#adff00;border-color:#2e2e2e}.nw-btn-ghost:hover{border-color:#adff00}
.nw-btn-danger{background:transparent;color:#ff4444;border-color:#3a1111}.nw-btn-danger:hover{background:#2a0000;border-color:#ff4444}
.nw-stats-bar{display:flex;gap:10px;margin-bottom:16px}
.nw-stat-pill{font-size:12px;padding:4px 12px;border-radius:20px;background:#1a1a1a;border:1px solid #2e2e2e;color:#aaa}
.nw-stat-pill strong{color:#adff00}
.nw-notice{padding:10px 16px;border-radius:6px;margin-bottom:14px;font-size:13px;border-left:3px solid}
.nw-notice-success{background:#0a2800;border-color:#adff00;color:#adff00}.nw-notice-error{background:#2a0000;border-color:#ff4444;color:#ff4444}
.nw-table-wrap{background:#111;border:1px solid #222;border-radius:8px;overflow:hidden}
.nw-table{width:100%;border-collapse:collapse;font-size:13px}
.nw-table thead tr{background:#1a1a1a;border-bottom:1px solid #2a2a2a}
.nw-table th{padding:10px 14px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#666;font-weight:600}
.nw-table tbody tr{border-bottom:1px solid #1e1e1e;transition:background .12s}
.nw-table tbody tr:last-child{border-bottom:none}.nw-table tbody tr:hover{background:#161616}
.nw-table td{padding:10px 14px;vertical-align:middle}.nw-col-img{width:50px}
.nw-class-img{width:40px;height:40px;border-radius:6px;object-fit:cover;border:1px solid #2e2e2e;background:#1a1a1a}
.nw-class-img-placeholder{width:40px;height:40px;border-radius:6px;background:#1a1a1a;border:1px solid #2e2e2e;display:flex;align-items:center;justify-content:center;color:#444;font-size:20px}
.nw-class-name{font-weight:600;color:#fff}.nw-class-sub{font-size:11px;color:#555;margin-top:2px}
.nw-gold{color:#e8af34;font-weight:700;font-size:13px}
.nw-vuln{font-size:11px;color:#ff6b35}
.nw-tags{display:flex;flex-wrap:wrap;gap:4px}
.nw-tag{font-size:10px;padding:2px 7px;background:#1e1e1e;border:1px solid #2e2e2e;border-radius:3px;color:#888}
.nw-badge-active{font-size:10px;padding:2px 8px;border-radius:20px;background:#0a2800;border:1px solid #2a5000;color:#adff00}
.nw-badge-inactive{font-size:10px;padding:2px 8px;border-radius:20px;background:#1a1a1a;border:1px solid #2e2e2e;color:#555}
.nw-row-actions{display:flex;gap:6px}
.nw-action-btn{font-family:'Chakra Petch',monospace;font-size:11px;padding:4px 10px;border-radius:4px;border:1px solid #2e2e2e;background:transparent;color:#aaa;cursor:pointer;transition:all .15s;text-transform:uppercase}
.nw-action-btn:hover{border-color:#adff00;color:#adff00}
.nw-loading-row td{text-align:center;padding:32px;color:#555}
.nw-spinner{display:inline-block;width:16px;height:16px;border:2px solid #333;border-top-color:#adff00;border-radius:50%;animation:nw-spin .6s linear infinite;vertical-align:middle;margin-right:8px}
@keyframes nw-spin{to{transform:rotate(360deg)}}
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
.nw-field input[type="text"],.nw-field input[type="url"],.nw-field input[type="number"],.nw-field textarea,.nw-field select{background:#0d0d0d;border:1px solid #2a2a2a;border-radius:5px;color:#e0e0e0;padding:8px 10px;font-family:'Chakra Petch',monospace;font-size:13px;transition:border-color .15s;width:100%}
.nw-field input:focus,.nw-field textarea:focus,.nw-field select:focus{outline:none;border-color:#adff00;box-shadow:0 0 0 2px rgba(173,255,0,.08)}
.nw-field textarea{resize:vertical}
CSS;
    }

    /* ---------------------------------------------------------------- */
    /*  JS                                                                */
    /* ---------------------------------------------------------------- */

    private function get_js(): string { return <<<'JS'
jQuery(function($){
    var nonce=$("#nw-nonce").val(), all=[];

    function esc(s){return $('<span>').text(s||'').html();}
    function notice(msg,type){var el=$("#nw-notice");el.attr("class","nw-notice nw-notice-"+type).text(msg).show();setTimeout(function(){el.fadeOut(300);},3500);}
    function tagsStr(t){if(!t)return'';if(Array.isArray(t))return t.join(', ');try{var a=JSON.parse(t);return Array.isArray(a)?a.join(', '):t;}catch(e){return t;}}

    function renderTable(data){
        var tbody=$("#nw-classes-tbody");
        if(!data.length){tbody.html('<tr><td colspan="8" style="text-align:center;padding:32px;color:#555;">No classes found.</td></tr>');return;}
        tbody.html(data.map(function(c){
            var tags=Array.isArray(c.tags)?c.tags:(c.tags?[c.tags]:[]);
            var tagsH=tags.slice(0,4).map(function(t){return'<span class="nw-tag">'+esc(t)+'</span>';}).join('')+(tags.length>4?'<span class="nw-tag">+'+(tags.length-4)+'</span>':'');
            var imgH=c.img_url?'<img src="'+esc(c.img_url)+'" class="nw-class-img" loading="lazy" onerror="this.style.display=\'none\'">':'<div class="nw-class-img-placeholder">⚔️</div>';
            var activeH=c.is_active?'<span class="nw-badge-active">Active</span>':'<span class="nw-badge-inactive">Inactive</span>';
            return'<tr data-id="'+c.id+'">'
                +'<td>'+imgH+'</td>'
                +'<td><div class="nw-class-name">'+esc(c.name)+'</div>'+(c.description?'<div class="nw-class-sub">'+esc(c.description.substring(0,60))+(c.description.length>60?'…':'')+'</div>':'')+'</td>'
                +'<td><div class="nw-tags">'+tagsH+'</div></td>'
                +'<td>'+(c.starting_gold!=null?'<span class="nw-gold">'+c.starting_gold+' g</span>':'<span style="color:#444">—</span>')+'</td>'
                +'<td><span style="color:#aaa">'+( c.skill_limit!=null?c.skill_limit:'—')+'</span></td>'
                +'<td>'+(c.vulnerability?'<span class="nw-vuln">'+esc(c.vulnerability)+'</span>':'<span style="color:#444">—</span>')+'</td>'
                +'<td>'+activeH+'</td>'
                +'<td><div class="nw-row-actions"><button class="nw-action-btn nw-edit-btn" data-id="'+c.id+'">Edit</button></div></td>'
                +'</tr>';
        }).join(''));
    }

    function applySearch(){
        var q=$("#nw-search").val().toLowerCase().trim();
        var shown=q?all.filter(function(c){return c.name.toLowerCase().includes(q)||(c.icon_slug||'').toLowerCase().includes(q);}):all;
        renderTable(shown);
    }

    function loadAll(){
        $("#nw-classes-tbody").html('<tr class="nw-loading-row"><td colspan="8"><div class="nw-spinner"></div> Loading…</td></tr>');
        $.post(ajaxurl,{action:"nw_classes_get_all",nonce:nonce},function(res){
            if(!res.success){notice("Error: "+res.data,"error");return;}
            all=res.data||[];
            $("#nw-total").text(all.length);
            $("#nw-active").text(all.filter(function(c){return c.is_active;}).length);
            applySearch();
        }).fail(function(){notice("Request failed.","error");});
    }

    $("#nw-search").on("input",applySearch);

    function abToStr(ab){
        if(!ab)return'';
        if(typeof ab==='string')return ab;
        try{return JSON.stringify(ab);}catch(e){return'';}
    }

    function openModal(id){
        $("#nw-class-form")[0].reset();
        $("#nw-field-id").val("");
        $("#nw-img-preview-wrap").hide();
        $("#nw-field-is_active").val("1");
        if(id){
            var c=all.find(function(x){return x.id===id;});
            if(c){
                $("#nw-field-id").val(c.id);
                $("#nw-field-name").val(c.name||"");
                $("#nw-field-description").val(c.description||"");
                $("#nw-field-icon_slug").val(c.icon_slug||"");
                $("#nw-field-starting_gold").val(c.starting_gold!=null?c.starting_gold:"");
                $("#nw-field-skill_limit").val(c.skill_limit!=null?c.skill_limit:"");
                $("#nw-field-vulnerability").val(c.vulnerability||"");
                $("#nw-field-tags").val(tagsStr(c.tags));
                $("#nw-field-is_active").val(c.is_active?"1":"0");
                $("#nw-field-mechanics").val(c.mechanics||"");
                $("#nw-field-gm_instructions").val(c.gm_instructions||"");
                $("#nw-field-ai_personality_modifier").val(c.ai_personality_modifier||"");
                $("#nw-field-attribute_bonuses").val(abToStr(c.attribute_bonuses));
                if(c.img_url){$("#nw-field-img_url").val(c.img_url);$("#nw-img-preview").attr("src",c.img_url);$("#nw-img-preview-wrap").show();}
            }
            $("#nw-modal-title").text("Edit Class");
            $("#nw-save-label").text("Save Changes");
            $("#nw-delete-btn").show().data("id",id);
        } else {
            $("#nw-modal-title").text("New Class");
            $("#nw-save-label").text("Create Class");
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
        var fd={action:"nw_classes_save",nonce:nonce,"nw_class":{}};
        $("#nw-class-form").serializeArray().forEach(function(f){fd["nw_class"][f.name]=f.value;});
        $.post(ajaxurl,fd,function(res){
            btn.prop("disabled",false);$("#nw-save-label").text("Save Changes");
            if(res.success){notice("Class saved!","success");$("#nw-modal-overlay").fadeOut(150);loadAll();}
            else{notice("Error: "+(res.data||"Unknown"),"error");}
        }).fail(function(){btn.prop("disabled",false);$("#nw-save-label").text("Save Changes");notice("Request failed.","error");});
    });

    $("#nw-delete-btn").on("click",function(){
        var id=$(this).data("id");
        if(!id||!confirm("Delete this class permanently?"))return;
        $.post(ajaxurl,{action:"nw_classes_delete",nonce:nonce,class_id:id},function(res){
            if(res.success){notice("Class deleted.","success");$("#nw-modal-overlay").fadeOut(150);loadAll();}
            else{notice("Delete failed: "+res.data,"error");}
        });
    });

    loadAll();
});
JS;
    }
}

new NeoWeaver_Classes_Admin();
