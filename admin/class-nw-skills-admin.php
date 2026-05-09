<?php
/**
 * NeoWeaver Admin Panel — Skills (cyber_skills)
 *
 * Columns: id, name, description, category, application, card_effect,
 *          img_url, tags, linked_attributes, is_active, created_at
 *
 * Categories: Physical | Social | Mental | Exploration
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Skills_Admin {

    private string $supabase_url;
    private string $supabase_key;
    private string $page_slug   = 'neoweaver-skills';
    private string $parent_slug = 'neoweaver';

    public function __construct() {
        $this->supabase_url = rtrim( tw_supabase_url(), '/' );
        $this->supabase_key = tw_supabase_anon_key();

        add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_nw_skills_get_all', [ $this, 'ajax_get_all' ] );
        add_action( 'wp_ajax_nw_skills_save',    [ $this, 'ajax_save'    ] );
        add_action( 'wp_ajax_nw_skills_toggle',  [ $this, 'ajax_toggle'  ] );
        add_action( 'wp_ajax_nw_skills_delete',  [ $this, 'ajax_delete'  ] );
    }

    /* ---------------------------------------------------------------- */
    /*  MENU                                                              */
    /* ---------------------------------------------------------------- */

    public function register_menu(): void {
        add_submenu_page(
            $this->parent_slug,
            'NeoWeaver — Skills',
            '🧠 Skills',
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
        <div class="wrap nw-panel" id="nw-skills-panel">

            <div class="nw-panel-header">
                <h1 class="nw-panel-title">
                    <span class="nw-accent">Neo</span>Weaver
                    <span class="nw-panel-subtitle">/ Skills</span>
                </h1>
                <div class="nw-header-actions">
                    <div class="nw-filter-bar">
                        <select id="nw-filter-category" class="nw-select-filter">
                            <option value="">All categories</option>
                            <?php foreach ( $this->categories() as $c ) : ?>
                            <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($c); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">↻ Refresh</button>
                    <button class="nw-btn nw-btn-primary" id="nw-add-btn">+ New Skill</button>
                </div>
            </div>

            <div id="nw-notice" class="nw-notice" style="display:none;"></div>

            <div class="nw-stats-bar">
                <span class="nw-stat-pill">Total: <strong id="nw-total">—</strong></span>
                <span class="nw-stat-pill nw-pill-active">Active: <strong id="nw-active">—</strong></span>
                <span class="nw-stat-pill nw-pill-inactive">Inactive: <strong id="nw-inactive">—</strong></span>
            </div>

            <div class="nw-table-wrap">
                <table class="nw-table">
                    <thead><tr>
                        <th class="nw-col-img"></th>
                        <th>Name</th>
                        <th>Category</th>
                        <th>Application</th>
                        <th>Tags</th>
                        <th>Card Effect</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody id="nw-skills-tbody">
                        <tr class="nw-loading-row"><td colspan="8"><div class="nw-spinner"></div> Loading skills…</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- ==================== MODAL ==================== -->
            <div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
                <div class="nw-modal">
                    <div class="nw-modal-header">
                        <h2 id="nw-modal-title">Edit Skill</h2>
                        <button class="nw-modal-close" id="nw-modal-close">✕</button>
                    </div>
                    <div class="nw-modal-body">
                        <form id="nw-skill-form">
                            <input type="hidden" id="nw-field-id" name="id">

                            <!-- Section: Identity -->
                            <div class="nw-section-label">Identity</div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-full">
                                    <label>Name <span class="nw-req">*</span></label>
                                    <input type="text" id="nw-field-name" name="name" required placeholder="e.g. Neural Hacking">
                                </div>
                                <div class="nw-field">
                                    <label>Category</label>
                                    <select id="nw-field-category" name="category" class="nw-select">
                                        <option value="">— choose —</option>
                                        <?php foreach ( $this->categories() as $c ) : ?>
                                        <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($c); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="nw-field">
                                    <label>Application</label>
                                    <input type="text" id="nw-field-application" name="application" placeholder="e.g. Combat, Stealth…">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Description</label>
                                    <textarea id="nw-field-description" name="description" rows="3" placeholder="Public skill description…"></textarea>
                                </div>
                            </div>

                            <!-- Section: Mechanics -->
                            <div class="nw-section-label">Mechanics</div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-full">
                                    <label>Card Effect <span class="nw-hint">(effect triggered when the skill card is drawn)</span></label>
                                    <textarea id="nw-field-card_effect" name="card_effect" rows="3" placeholder="e.g. +2 to next hacking roll, ignore 1 firewall…"></textarea>
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Tags <span class="nw-hint">(comma-separated → JSON array)</span></label>
                                    <input type="text" id="nw-field-tags" name="tags" placeholder="e.g. cyber, passive, combat">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Linked Attributes <span class="nw-hint">(comma-separated → JSON array)</span></label>
                                    <input type="text" id="nw-field-linked_attributes" name="linked_attributes" placeholder="e.g. intelligence, agility">
                                </div>
                            </div>

                            <!-- Section: Media -->
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

                            <!-- Section: Visibility -->
                            <div class="nw-section-label">Visibility</div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-center">
                                    <label>Active (visible in game)</label>
                                    <label class="nw-toggle">
                                        <input type="checkbox" id="nw-field-is_active" name="is_active" checked>
                                        <span class="nw-toggle-slider"></span>
                                    </label>
                                </div>
                            </div>

                        </form>
                    </div><!-- .nw-modal-body -->
                    <div class="nw-modal-footer">
                        <button class="nw-btn nw-btn-danger" id="nw-delete-btn" style="display:none;margin-right:auto;">🗑 Delete</button>
                        <button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
                        <button class="nw-btn nw-btn-primary" id="nw-save-btn"><span id="nw-save-label">Save Skill</span></button>
                    </div>
                </div>
            </div><!-- .nw-modal-overlay -->

            <input type="hidden" id="nw-nonce" value="<?php echo esc_attr( wp_create_nonce( 'neoweaver_skills' ) ); ?>">
        </div>
    <?php }

    /* ---------------------------------------------------------------- */
    /*  DATA LISTS                                                        */
    /* ---------------------------------------------------------------- */

    private function categories(): array {
        return [ 'Physical', 'Social', 'Mental', 'Exploration' ];
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
        return [ 'code' => wp_remote_retrieve_response_code( $res ), 'data' => json_decode( wp_remote_retrieve_body( $res ), true ) ];
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: GET ALL                                                     */
    /* ---------------------------------------------------------------- */

    public function ajax_get_all(): void {
        check_ajax_referer( 'neoweaver_skills', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $category = sanitize_text_field( $_POST['filter_category'] ?? '' );

        $qs = 'cyber_skills?select=id,name,description,category,application,card_effect,img_url,tags,linked_attributes,is_active,created_at&order=name.asc';
        if ( $category ) $qs .= '&category=eq.' . urlencode( $category );

        $res = $this->supa( 'GET', $qs );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( $res['data'] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: SAVE                                                        */
    /* ---------------------------------------------------------------- */

    public function ajax_save(): void {
        check_ajax_referer( 'neoweaver_skills', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $raw  = $_POST['skill'] ?? [];
        $id   = sanitize_text_field( $raw['id'] ?? '' );

        $tags = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $raw['tags'] ?? '' ) ) ) ) );
        $attrs = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $raw['linked_attributes'] ?? '' ) ) ) ) );

        $payload = [
            'name'              => sanitize_text_field(     $raw['name']        ?? '' ),
            'description'       => sanitize_textarea_field( $raw['description'] ?? '' ) ?: null,
            'category'          => sanitize_text_field(     $raw['category']    ?? '' ) ?: null,
            'application'       => sanitize_text_field(     $raw['application'] ?? '' ) ?: null,
            'card_effect'       => sanitize_textarea_field( $raw['card_effect'] ?? '' ) ?: null,
            'img_url'           => esc_url_raw(             $raw['img_url']     ?? '' ) ?: null,
            'tags'              => $tags,
            'linked_attributes' => $attrs,
            'is_active'         => filter_var( $raw['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN ),
        ];

        $res = $id
            ? $this->supa( 'PATCH', 'cyber_skills?id=eq.' . urlencode( $id ), $payload )
            : $this->supa( 'POST',  'cyber_skills', $payload );

        if ( isset( $res['error'] ) ) { wp_send_json_error( $res['error'] ); }
        $code = $res['code'] ?? 0;
        ( $code >= 200 && $code < 300 )
            ? wp_send_json_success( $res['data'][0] ?? $res['data'] )
            : wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: TOGGLE                                                      */
    /* ---------------------------------------------------------------- */

    public function ajax_toggle(): void {
        check_ajax_referer( 'neoweaver_skills', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $id    = sanitize_text_field( $_POST['skill_id']  ?? '' );
        $state = filter_var(           $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );
        $res = $this->supa( 'PATCH', 'cyber_skills?id=eq.' . urlencode( $id ), [ 'is_active' => $state ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( [ 'is_active' => $state ] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: DELETE                                                      */
    /* ---------------------------------------------------------------- */

    public function ajax_delete(): void {
        check_ajax_referer( 'neoweaver_skills', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $id = sanitize_text_field( $_POST['skill_id'] ?? '' );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );
        $res = $this->supa( 'DELETE', 'cyber_skills?id=eq.' . urlencode( $id ), [], [ 'Prefer' => '' ] );
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
.nw-filter-bar{display:flex;gap:6px}
.nw-select-filter{font-family:'Chakra Petch',monospace;font-size:12px;background:#0d0d0d;border:1px solid #2a2a2a;border-radius:5px;color:#ccc;padding:6px 10px;cursor:pointer}
.nw-select-filter:focus{outline:none;border-color:#adff00}
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
.nw-table td{padding:10px 14px;vertical-align:middle}.nw-col-img{width:50px}
.nw-skill-img{width:40px;height:40px;border-radius:6px;object-fit:cover;border:1px solid #2e2e2e;background:#1a1a1a}
.nw-skill-img-placeholder{width:40px;height:40px;border-radius:6px;background:#1a1a1a;border:1px solid #2e2e2e;display:flex;align-items:center;justify-content:center;color:#444;font-size:20px}
.nw-skill-name{font-weight:600;color:#fff}.nw-skill-sub{font-size:11px;color:#555;margin-top:2px}
.nw-category-badge{font-size:10px;padding:2px 8px;border-radius:3px;background:#1e1e1e;border:1px solid #2e2e2e;color:#aaa;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}
.nw-cat-Physical{border-color:#ff6b35;color:#ff6b35}.nw-cat-Social{border-color:#4da6ff;color:#4da6ff}
.nw-cat-Mental{border-color:#b04dff;color:#b04dff}.nw-cat-Exploration{border-color:#4fc874;color:#4fc874}
.nw-tags{display:flex;flex-wrap:wrap;gap:4px}
.nw-tag{font-size:10px;padding:2px 7px;background:#1e1e1e;border:1px solid #2e2e2e;border-radius:3px;color:#888}
.nw-card-effect{font-size:11px;color:#adff00;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.nw-toggle{position:relative;display:inline-block;width:40px;height:22px}
.nw-toggle input{opacity:0;width:0;height:0}
.nw-toggle-slider{position:absolute;inset:0;background:#2a2a2a;border-radius:22px;cursor:pointer;transition:background .2s;border:1px solid #3a3a3a}
.nw-toggle-slider::before{content:'';position:absolute;width:16px;height:16px;left:2px;top:2px;background:#555;border-radius:50%;transition:all .2s}
.nw-toggle input:checked+.nw-toggle-slider{background:#1a3300;border-color:#adff00}
.nw-toggle input:checked+.nw-toggle-slider::before{background:#adff00;transform:translateX(18px)}
.nw-row-inactive td:not(:last-child):not(:first-child){opacity:.4}
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
.nw-field input[type="text"],.nw-field input[type="url"],.nw-field textarea,.nw-select{background:#0d0d0d;border:1px solid #2a2a2a;border-radius:5px;color:#e0e0e0;padding:8px 10px;font-family:'Chakra Petch',monospace;font-size:13px;transition:border-color .15s;width:100%}
.nw-field input:focus,.nw-field textarea:focus,.nw-select:focus{outline:none;border-color:#adff00;box-shadow:0 0 0 2px rgba(173,255,0,.08)}
.nw-field textarea{resize:vertical}
.nw-select option{background:#111}
CSS;
    }

    /* ---------------------------------------------------------------- */
    /*  JS                                                                */
    /* ---------------------------------------------------------------- */

    private function get_js(): string { return <<<'JS'
jQuery(function($){
    var nonce   = $('#nw-nonce').val();
    var editId  = null;

    /* ---------- load ---------- */
    function loadSkills(){
        var cat = $('#nw-filter-category').val();
        $('#nw-skills-tbody').html('<tr class="nw-loading-row"><td colspan="8"><div class="nw-spinner"></div> Loading skills…</td></tr>');
        $.post(ajaxurl,{action:'nw_skills_get_all',nonce:nonce,filter_category:cat},function(r){
            if(!r.success){showNotice('error',r.data);return;}
            renderTable(r.data);
        });
    }

    function renderTable(rows){
        var total=rows.length,active=0,inactive=0,html='';
        if(!rows.length){html='<tr><td colspan="8" style="text-align:center;padding:32px;color:#555;">No skills found.</td></tr>';}
        $.each(rows,function(_,s){
            if(s.is_active) active++; else inactive++;
            var tags='';
            if(s.tags&&s.tags.length){$.each(s.tags,function(_,t){tags+='<span class="nw-tag">'+escH(t)+'</span>';});}
            var img=s.img_url
                ?'<img class="nw-skill-img" src="'+escH(s.img_url)+'" alt="" loading="lazy">'
                :'<div class="nw-skill-img-placeholder">⚡</div>';
            var catCls='nw-cat-'+(s.category||'');
            var cardEff=s.card_effect?'<span class="nw-card-effect" title="'+escH(s.card_effect)+'">'+escH(s.card_effect)+'</span>':'<span style="color:#333">—</span>';
            html+='<tr class="'+(s.is_active?'':'nw-row-inactive')+'" data-id="'+escH(s.id)+'">'
                +'<td>'+img+'</td>'
                +'<td><div class="nw-skill-name">'+escH(s.name)+'</div>'+(s.description?'<div class="nw-skill-sub">'+escH(s.description.substring(0,60))+(s.description.length>60?'…':'')+'</div>':'')+'</td>'
                +'<td>'+(s.category?'<span class="nw-category-badge '+catCls+'">'+escH(s.category)+'</span>':'<span style="color:#333">—</span>')+'</td>'
                +'<td>'+(s.application?escH(s.application):'<span style="color:#333">—</span>')+'</td>'
                +'<td><div class="nw-tags">'+tags+'</div></td>'
                +'<td>'+cardEff+'</td>'
                +'<td><label class="nw-toggle"><input type="checkbox" class="nw-toggle-active" data-id="'+escH(s.id)+'"'+(s.is_active?' checked':'')+'><span class="nw-toggle-slider"></span></label></td>'
                +'<td><div class="nw-row-actions"><button class="nw-action-btn nw-edit-btn" data-id="'+escH(s.id)+'">Edit</button></div></td>'
                +'</tr>';
        });
        $('#nw-skills-tbody').html(html);
        $('#nw-total').text(total);$('#nw-active').text(active);$('#nw-inactive').text(inactive);
    }

    /* ---------- modal ---------- */
    function openModal(skill){
        editId = skill ? skill.id : null;
        $('#nw-modal-title').text(skill?'Edit Skill':'New Skill');
        $('#nw-save-label').text(skill?'Save Skill':'Create Skill');
        $('#nw-delete-btn').toggle(!!skill);
        $('#nw-field-id').val(skill?skill.id:'');
        $('#nw-field-name').val(skill?skill.name:'');
        $('#nw-field-category').val(skill&&skill.category?skill.category:'');
        $('#nw-field-application').val(skill?skill.application||'':'');
        $('#nw-field-description').val(skill?skill.description||'':'');
        $('#nw-field-card_effect').val(skill?skill.card_effect||'':'');
        $('#nw-field-img_url').val(skill?skill.img_url||'':'');
        $('#nw-field-tags').val(skill&&skill.tags?skill.tags.join(', '):'');
        $('#nw-field-linked_attributes').val(skill&&skill.linked_attributes?skill.linked_attributes.join(', '):'');
        $('#nw-field-is_active').prop('checked',skill?skill.is_active:true);
        updateImgPreview($('#nw-field-img_url').val());
        $('#nw-modal-overlay').show();
    }
    function closeModal(){ $('#nw-modal-overlay').hide(); editId=null; }

    function updateImgPreview(url){
        if(url){$('#nw-img-preview').attr('src',url);$('#nw-img-preview-wrap').show();}
        else{$('#nw-img-preview-wrap').hide();}
    }

    /* ---------- save ---------- */
    function saveSkill(){
        var data={action:'nw_skills_save',nonce:nonce,skill:{}};
        $('#nw-skill-form').serializeArray().forEach(function(f){data.skill[f.name]=f.value;});
        data.skill.is_active=$('#nw-field-is_active').is(':checked')?'1':'0';
        $('#nw-save-btn').prop('disabled',true).text('Saving…');
        $.post(ajaxurl,data,function(r){
            $('#nw-save-btn').prop('disabled',false);
            $('#nw-save-label').text(editId?'Save Skill':'Create Skill');
            if(!r.success){showNotice('error',r.data);return;}
            showNotice('success',editId?'Skill updated.':'Skill created.');
            closeModal(); loadSkills();
        });
    }

    /* ---------- toggle ---------- */
    $(document).on('change','.nw-toggle-active',function(){
        var id=$(this).data('id'), state=$(this).is(':checked');
        $.post(ajaxurl,{action:'nw_skills_toggle',nonce:nonce,skill_id:id,is_active:state?1:0},function(r){
            if(!r.success){showNotice('error',r.data);loadSkills();}
            else{$(document).find('tr[data-id="'+id+'"]').toggleClass('nw-row-inactive',!state);}
        });
    });

    /* ---------- delete ---------- */
    $('#nw-delete-btn').on('click',function(){
        if(!editId||!confirm('Delete this skill? This cannot be undone.')) return;
        $.post(ajaxurl,{action:'nw_skills_delete',nonce:nonce,skill_id:editId},function(r){
            if(!r.success){showNotice('error',r.data);return;}
            showNotice('success','Skill deleted.');
            closeModal(); loadSkills();
        });
    });

    /* ---------- events ---------- */
    $('#nw-add-btn').on('click',function(){openModal(null);});
    $('#nw-refresh-btn').on('click',loadSkills);
    $('#nw-filter-category').on('change',loadSkills);
    $('#nw-modal-close,#nw-cancel-btn').on('click',closeModal);
    $('#nw-modal-overlay').on('click',function(e){if($(e.target).is('#nw-modal-overlay'))closeModal();});
    $('#nw-save-btn').on('click',saveSkill);
    $('#nw-field-img_url').on('input',function(){updateImgPreview($(this).val());});
    $(document).on('click','.nw-edit-btn',function(){
        var id=$(this).data('id');
        var row=$('tr[data-id="'+id+'"]');
        /* fetch full row from server to get all fields */
        $.post(ajaxurl,{action:'nw_skills_get_all',nonce:nonce,filter_category:''},function(r){
            if(!r.success) return;
            var skill=null; $.each(r.data,function(_,s){if(s.id===id){skill=s;return false;}});
            if(skill) openModal(skill);
        });
    });

    function showNotice(type,msg){
        var $n=$('#nw-notice');
        $n.removeClass('nw-notice-success nw-notice-error').addClass('nw-notice-'+type).text(msg).show();
        setTimeout(function(){$n.fadeOut();},4000);
    }
    function escH(s){return $('<div>').text(String(s||'')).html();}

    loadSkills();
});
JS;
    }
}

new NeoWeaver_Skills_Admin();
