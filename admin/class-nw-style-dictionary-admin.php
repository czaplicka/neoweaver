<?php
/**
 * NeoWeaver Admin Panel — Style Dictionary (cyber_style_dictionary)
 *
 * Columns: id, tag_name, category, interpretation_en, is_active, created_at
 * Categories: behavior | visuals | vibe | general
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Style_Dictionary_Admin {

    private string $supabase_url;
    private string $supabase_key;
    private string $page_slug   = 'neoweaver-style-dictionary';
    private string $parent_slug = 'neoweaver';

    private array $categories = [ 'behavior', 'visuals', 'vibe', 'general' ];

    public function __construct() {
        $this->supabase_url = rtrim( tw_supabase_url(), '/' );
        $this->supabase_key = tw_supabase_anon_key();

        add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_nw_sd_get_all', [ $this, 'ajax_get_all' ] );
        add_action( 'wp_ajax_nw_sd_save',    [ $this, 'ajax_save'    ] );
        add_action( 'wp_ajax_nw_sd_toggle',  [ $this, 'ajax_toggle'  ] );
        add_action( 'wp_ajax_nw_sd_delete',  [ $this, 'ajax_delete'  ] );
    }

    /* ---------------------------------------------------------------- */
    /*  MENU                                                              */
    /* ---------------------------------------------------------------- */

    public function register_menu(): void {
        add_submenu_page(
            $this->parent_slug,
            'NeoWeaver — Style Dictionary',
            '🔤 Style Dictionary',
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
        <div class="wrap nw-panel" id="nw-sd-panel">

            <div class="nw-panel-header">
                <h1 class="nw-panel-title">
                    <span class="nw-accent">Neo</span>Weaver
                    <span class="nw-panel-subtitle">/ Style Dictionary</span>
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
                <?php foreach ( $this->categories as $cat ) : ?>
                    <span class="nw-stat-pill nw-pill-cat-<?php echo esc_attr( $cat ); ?>">
                        <?php echo esc_html( ucfirst( $cat ) ); ?>: <strong class="nw-cat-count" data-cat="<?php echo esc_attr( $cat ); ?>">—</strong>
                    </span>
                <?php endforeach; ?>
            </div>

            <!-- Filter bar -->
            <div class="nw-filter-bar">
                <select id="nw-filter-category" class="nw-select nw-filter-select">
                    <option value="">All Categories</option>
                    <?php foreach ( $this->categories as $c ) : ?>
                        <option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( ucfirst( $c ) ); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="nw-filter-active" class="nw-select nw-filter-select">
                    <option value="">Active & Inactive</option>
                    <option value="1">Active only</option>
                    <option value="0">Inactive only</option>
                </select>
                <input type="text" id="nw-filter-search" class="nw-filter-input" placeholder="Search tag name or interpretation…">
            </div>

            <div class="nw-table-wrap">
                <table class="nw-table">
                    <thead><tr>
                        <th>Tag Name</th>
                        <th>Category</th>
                        <th>Interpretation (EN)</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody id="nw-sd-tbody">
                        <tr class="nw-loading-row"><td colspan="5"><div class="nw-spinner"></div> Loading tags…</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- ==================== MODAL ==================== -->
            <div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
                <div class="nw-modal">
                    <div class="nw-modal-header">
                        <h2 id="nw-modal-title">Edit Style Tag</h2>
                        <button class="nw-modal-close" id="nw-modal-close">✕</button>
                    </div>
                    <div class="nw-modal-body">
                        <form id="nw-sd-form">
                            <input type="hidden" id="nw-field-id" name="id">

                            <div class="nw-section-label">Identity</div>
                            <div class="nw-form-grid">
                                <div class="nw-field">
                                    <label>Tag Name <span class="nw-req">*</span></label>
                                    <input type="text" id="nw-field-tag_name" name="tag_name" required placeholder="e.g. neon-shadow">
                                </div>
                                <div class="nw-field">
                                    <label>Category <span class="nw-req">*</span></label>
                                    <select id="nw-field-category" name="category" class="nw-select">
                                        <?php foreach ( $this->categories as $c ) : ?>
                                            <option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( ucfirst( $c ) ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Interpretation (EN) <span class="nw-req">*</span></label>
                                    <textarea id="nw-field-interpretation_en" name="interpretation_en" rows="4" required placeholder="Describe what this style tag means in the game world…"></textarea>
                                </div>
                            </div>

                            <div class="nw-section-label">Visibility</div>
                            <div class="nw-form-grid">
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
            </div>

            <input type="hidden" id="nw-nonce" value="<?php echo esc_attr( wp_create_nonce( 'neoweaver_sd' ) ); ?>">
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
    /*  AJAX: GET ALL                                                     */
    /* ---------------------------------------------------------------- */

    public function ajax_get_all(): void {
        check_ajax_referer( 'neoweaver_sd', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $res = $this->supa( 'GET', 'cyber_style_dictionary?select=id,tag_name,category,interpretation_en,is_active,created_at&order=tag_name.asc' );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( $res['data'] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: SAVE                                                        */
    /* ---------------------------------------------------------------- */

    public function ajax_save(): void {
        check_ajax_referer( 'neoweaver_sd', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $raw      = $_POST['tag'] ?? [];
        $id       = sanitize_text_field( $raw['id'] ?? '' );
        $category = sanitize_text_field( $raw['category'] ?? 'general' );

        $payload = [
            'tag_name'          => sanitize_text_field(     $raw['tag_name']           ?? '' ),
            'category'          => in_array( $category, $this->categories, true ) ? $category : 'general',
            'interpretation_en' => sanitize_textarea_field( $raw['interpretation_en']  ?? '' ),
            'is_active'         => filter_var( $raw['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN ),
        ];

        if ( empty( $payload['tag_name'] ) )       { wp_send_json_error( 'Tag name is required.' ); }
        if ( empty( $payload['interpretation_en'] ) ) { wp_send_json_error( 'Interpretation is required.' ); }

        $res = $id
            ? $this->supa( 'PATCH', 'cyber_style_dictionary?id=eq.' . urlencode( $id ), $payload )
            : $this->supa( 'POST',  'cyber_style_dictionary', $payload );

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
        check_ajax_referer( 'neoweaver_sd', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $id    = sanitize_text_field( $_POST['tag_id']   ?? '' );
        $state = filter_var(           $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );
        $res = $this->supa( 'PATCH', 'cyber_style_dictionary?id=eq.' . urlencode( $id ), [ 'is_active' => $state ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( [ 'is_active' => $state ] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: DELETE                                                      */
    /* ---------------------------------------------------------------- */

    public function ajax_delete(): void {
        check_ajax_referer( 'neoweaver_sd', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $id = sanitize_text_field( $_POST['tag_id'] ?? '' );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );
        $res = $this->supa( 'DELETE', 'cyber_style_dictionary?id=eq.' . urlencode( $id ), [], [ 'Prefer' => '' ] );
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
.nw-stats-bar{display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap}
.nw-stat-pill{font-size:12px;padding:4px 12px;border-radius:20px;background:#1a1a1a;border:1px solid #2e2e2e;color:#aaa}
.nw-stat-pill strong{color:#fff}
.nw-pill-active{border-color:#adff00}.nw-pill-active strong{color:#adff00}
.nw-pill-inactive strong{color:#ff6b35}
.nw-pill-cat-behavior strong{color:#c88fff}.nw-pill-cat-behavior{border-color:#3a1a5e}
.nw-pill-cat-visuals strong{color:#44aaff}.nw-pill-cat-visuals{border-color:#0a2a4a}
.nw-pill-cat-vibe strong{color:#ff9944}.nw-pill-cat-vibe{border-color:#4a2a00}
.nw-pill-cat-general strong{color:#adff00}.nw-pill-cat-general{border-color:#2e4400}
.nw-filter-bar{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.nw-filter-select{min-width:160px}
.nw-filter-input{background:#0d0d0d;border:1px solid #2a2a2a;border-radius:5px;color:#e0e0e0;padding:7px 10px;font-family:'Chakra Petch',monospace;font-size:12px;min-width:260px}
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
.nw-tag-name{font-weight:700;color:#fff;font-size:13px;letter-spacing:.3px}
.nw-interp{font-size:12px;color:#666;margin-top:3px;max-width:600px;line-height:1.5}
.nw-badge{font-size:10px;padding:2px 8px;border-radius:3px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;border:1px solid}
.nw-badge-behavior{color:#c88fff;border-color:#3a1a5e;background:#1a0a2e}
.nw-badge-visuals{color:#44aaff;border-color:#0a2a4a;background:#021020}
.nw-badge-vibe{color:#ff9944;border-color:#4a2a00;background:#1e1000}
.nw-badge-general{color:#adff00;border-color:#2e4400;background:#0a1800}
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
.nw-modal{background:#111;border:1px solid #2e2e2e;border-radius:10px;width:100%;max-width:620px;max-height:92vh;overflow-y:auto;display:flex;flex-direction:column;font-family:'Chakra Petch',monospace}
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
.nw-req{color:#ff4444}
.nw-field input[type="text"],.nw-field textarea,.nw-select{background:#0d0d0d;border:1px solid #2a2a2a;border-radius:5px;color:#e0e0e0;padding:8px 10px;font-family:'Chakra Petch',monospace;font-size:13px;transition:border-color .15s;width:100%}
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
    var nonce = $('#nw-nonce').val();
    var editId = null;
    var allTags = [];

    function escH(s){return $('<div>').text(String(s||'')).html();}

    function badgeHtml(cat){
        return '<span class="nw-badge nw-badge-'+escH(cat)+'">'+escH(cat)+'</span>';
    }

    /* -------- load -------- */
    function loadTags(){
        $('#nw-sd-tbody').html('<tr class="nw-loading-row"><td colspan="5"><div class="nw-spinner"></div> Loading tags…</td></tr>');
        $.post(ajaxurl,{action:'nw_sd_get_all',nonce:nonce},function(r){
            if(!r.success){showNotice('error',r.data);return;}
            allTags=r.data||[];
            renderFiltered();
        });
    }

    function renderFiltered(){
        var catF=$('#nw-filter-category').val();
        var actF=$('#nw-filter-active').val();
        var search=$('#nw-filter-search').val().toLowerCase();
        var rows=allTags.filter(function(t){
            if(catF&&t.category!==catF) return false;
            if(actF==='1'&&!t.is_active) return false;
            if(actF==='0'&&t.is_active) return false;
            if(search&&!(t.tag_name.toLowerCase().includes(search)||t.interpretation_en.toLowerCase().includes(search))) return false;
            return true;
        });
        renderTable(rows);
    }

    function renderTable(rows){
        var total=allTags.length,active=0,inactive=0;
        var catCounts={};
        $.each(allTags,function(_,t){
            if(t.is_active) active++; else inactive++;
            catCounts[t.category]=(catCounts[t.category]||0)+1;
        });
        $('#nw-total').text(total);
        $('#nw-active').text(active);
        $('#nw-inactive').text(inactive);
        $('.nw-cat-count').each(function(){
            var cat=$(this).data('cat');
            $(this).text(catCounts[cat]||0);
        });

        var html='';
        if(!rows.length){html='<tr><td colspan="5" style="text-align:center;padding:32px;color:#555;">No tags found.</td></tr>';}
        $.each(rows,function(_,t){
            var interp=t.interpretation_en||'';
            var preview=interp.length>90?interp.substring(0,90)+'…':interp;
            html+='<tr data-id="'+escH(t.id)+'">'
                +'<td><div class="nw-tag-name">'+escH(t.tag_name)+'</div></td>'
                +'<td>'+badgeHtml(t.category)+'</td>'
                +'<td><div class="nw-interp">'+escH(preview)+'</div></td>'
                +'<td><label class="nw-toggle"><input type="checkbox" class="nw-toggle-active" data-id="'+escH(t.id)+'"'+(t.is_active?' checked':'')+'><span class="nw-toggle-slider"></span></label></td>'
                +'<td><div class="nw-row-actions"><button class="nw-action-btn nw-edit-btn" data-id="'+escH(t.id)+'">Edit</button></div></td>'
                +'</tr>';
        });
        $('#nw-sd-tbody').html(html);
    }

    /* -------- filters -------- */
    $('#nw-filter-category,#nw-filter-active').on('change',renderFiltered);
    $('#nw-filter-search').on('input',renderFiltered);

    /* -------- modal -------- */
    function openModal(tag){
        editId=tag?tag.id:null;
        $('#nw-modal-title').text(tag?'Edit Style Tag':'New Style Tag');
        $('#nw-save-label').text(tag?'Save Tag':'Create Tag');
        $('#nw-delete-btn').toggle(!!tag);
        $('#nw-field-id').val(tag?tag.id:'');
        $('#nw-field-tag_name').val(tag?tag.tag_name:'');
        $('#nw-field-category').val(tag?tag.category:'general');
        $('#nw-field-interpretation_en').val(tag?tag.interpretation_en:'');
        $('#nw-field-is_active').prop('checked',tag?tag.is_active:true);
        $('#nw-modal-overlay').show();
        $('#nw-field-tag_name').focus();
    }
    function closeModal(){ $('#nw-modal-overlay').hide(); editId=null; }

    /* -------- save -------- */
    function saveTag(){
        var data={action:'nw_sd_save',nonce:nonce,tag:{}};
        $('#nw-sd-form').serializeArray().forEach(function(f){data.tag[f.name]=f.value;});
        data.tag.is_active=$('#nw-field-is_active').is(':checked')?'1':'0';
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
        $.post(ajaxurl,{action:'nw_sd_toggle',nonce:nonce,tag_id:id,is_active:state?1:0},function(r){
            if(!r.success){showNotice('error',r.data);loadTags();}
        });
    });

    /* -------- delete -------- */
    $('#nw-delete-btn').on('click',function(){
        if(!editId||!confirm('Delete this style tag? This cannot be undone.')) return;
        $.post(ajaxurl,{action:'nw_sd_delete',nonce:nonce,tag_id:editId},function(r){
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
        var tag=null; $.each(allTags,function(_,t){if(t.id===id){tag=t;return false;}});
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

new NeoWeaver_Style_Dictionary_Admin();
