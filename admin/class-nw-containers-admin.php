<?php
/**
 * NeoWeaver Admin Panel — Containers (cyber_containers)
 * Columns: id, name, description, total_slots, allowed_sizes,
 *          img_url, rarity, is_active, created_at, parent_id
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Containers_Admin {

    private string $supabase_url;
    private string $supabase_key;
    private string $page_slug = 'neoweaver-containers';
    private string $menu_slug = 'neoweaver';

    public function __construct() {
        $this->supabase_url = rtrim( tw_supabase_url(), '/' );
        $this->supabase_key = tw_supabase_anon_key();

        add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_nw_containers_get_all', [ $this, 'ajax_get_all' ] );
        add_action( 'wp_ajax_nw_containers_save',    [ $this, 'ajax_save'    ] );
        add_action( 'wp_ajax_nw_containers_toggle',  [ $this, 'ajax_toggle'  ] );
    }

    public function register_menu(): void {
        // NOTE: add_menu_page() is intentionally NOT called here.
        // The top-level NeoWeaver menu is registered solely in class-nw-admin.php.
        add_submenu_page(
            $this->menu_slug,
            'NeoWeaver — Containers',
            '🎒 Containers',
            'manage_options',
            $this->page_slug,
            [ $this, 'render_page' ]
        );
    }

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
        <div class="wrap nw-panel" id="nw-containers-panel">
            <div class="nw-panel-header">
                <h1 class="nw-panel-title"><span class="nw-accent">Neo</span>Weaver <span class="nw-panel-subtitle">/ Containers</span></h1>
                <div class="nw-header-actions">
                    <button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">↻ Refresh</button>
                    <button class="nw-btn nw-btn-primary" id="nw-add-btn">+ New Container</button>
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
                        <th>Rarity</th>
                        <th>Slots</th>
                        <th>Allowed Sizes</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody id="nw-containers-tbody">
                        <tr class="nw-loading-row"><td colspan="7"><div class="nw-spinner"></div> Loading…</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- MODAL -->
            <div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
                <div class="nw-modal">
                    <div class="nw-modal-header">
                        <h2 id="nw-modal-title">Edit Container</h2>
                        <button class="nw-modal-close" id="nw-modal-close">✕</button>
                    </div>
                    <div class="nw-modal-body">
                        <form id="nw-container-form">
                            <input type="hidden" id="nw-field-id" name="id">
                            <div class="nw-form-grid">

                                <div class="nw-field nw-field-full">
                                    <label>Name <span class="nw-req">*</span></label>
                                    <input type="text" id="nw-field-name" name="name" required placeholder="e.g. Tech Backpack">
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>Description</label>
                                    <textarea id="nw-field-description" name="description" rows="3"></textarea>
                                </div>

                                <div class="nw-field">
                                    <label>Image filename / URL</label>
                                    <input type="text" id="nw-field-img_url" name="img_url" placeholder="e.g. backpack.svg">
                                </div>

                                <div class="nw-field">
                                    <label>Rarity</label>
                                    <select id="nw-field-rarity" name="rarity">
                                        <option value="common">Common</option>
                                        <option value="uncommon">Uncommon</option>
                                        <option value="rare">Rare</option>
                                        <option value="epic">Epic</option>
                                        <option value="legendary">Legendary</option>
                                    </select>
                                </div>

                                <div class="nw-field">
                                    <label>Total Slots</label>
                                    <div class="nw-stat-slider-row">
                                        <input type="range" id="nw-field-total_slots" name="total_slots" min="1" max="50" value="5" class="nw-range">
                                        <span class="nw-range-val" id="nw-val-total_slots">5</span>
                                    </div>
                                </div>

                                <div class="nw-field">
                                    <label>Parent Item ID <span class="nw-hint">(UUID, optional)</span></label>
                                    <input type="text" id="nw-field-parent_id" name="parent_id" placeholder="UUID or leave empty">
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>Allowed Sizes <span class="nw-hint">(check all that apply)</span></label>
                                    <div class="nw-checkbox-group">
                                        <?php foreach ( [ 'tiny', 'small', 'medium', 'large' ] as $sz ) : ?>
                                        <label class="nw-check-label">
                                            <input type="checkbox" name="allowed_sizes[]" value="<?php echo esc_attr( $sz ); ?>" checked>
                                            <?php echo esc_html( ucfirst( $sz ) ); ?>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="nw-field nw-field-center">
                                    <label>Active</label>
                                    <label class="nw-toggle">
                                        <input type="checkbox" id="nw-field-is_active" name="is_active" checked>
                                        <span class="nw-toggle-slider"></span>
                                    </label>
                                </div>

                            </div><!-- .nw-form-grid -->
                        </form>
                    </div>
                    <div class="nw-modal-footer">
                        <button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
                        <button class="nw-btn nw-btn-primary" id="nw-save-btn"><span id="nw-save-label">Save Container</span></button>
                    </div>
                </div>
            </div>

            <input type="hidden" id="nw-nonce" value="<?php echo esc_attr( wp_create_nonce( 'neoweaver_containers' ) ); ?>">
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
    /*  AJAX                                                              */
    /* ---------------------------------------------------------------- */

    public function ajax_get_all(): void {
        check_ajax_referer( 'neoweaver_containers', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $res = $this->supa( 'GET',
            'cyber_containers?select=id,name,description,total_slots,allowed_sizes,img_url,rarity,is_active,created_at,parent_id&order=name.asc'
        );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( $res['data'] );
    }

    public function ajax_save(): void {
        check_ajax_referer( 'neoweaver_containers', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $raw  = $_POST['container'] ?? [];
        $id   = sanitize_text_field( $raw['id'] ?? '' );

        // allowed_sizes comes as a comma-separated string from JS
        $sizes_raw = sanitize_text_field( $raw['allowed_sizes'] ?? 'tiny,small,medium,large' );
        $allowed   = array_values( array_filter( array_map( 'trim', explode( ',', $sizes_raw ) ) ) );
        $valid     = [ 'tiny', 'small', 'medium', 'large' ];
        $allowed   = array_values( array_intersect( $allowed, $valid ) );
        if ( empty( $allowed ) ) $allowed = $valid;

        $parent_id = sanitize_text_field( $raw['parent_id'] ?? '' );

        $payload = [
            'name'          => sanitize_text_field( $raw['name'] ?? '' ),
            'description'   => sanitize_textarea_field( $raw['description'] ?? '' ) ?: null,
            'img_url'       => sanitize_text_field( $raw['img_url'] ?? '' ) ?: null,
            'rarity'        => in_array( $raw['rarity'] ?? '', [ 'common','uncommon','rare','epic','legendary' ], true )
                                ? $raw['rarity'] : 'common',
            'total_slots'   => max( 1, (int)( $raw['total_slots'] ?? 5 ) ),
            'allowed_sizes' => $allowed,
            'parent_id'     => $parent_id ?: null,
            'is_active'     => filter_var( $raw['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN ),
        ];

        $res = $id
            ? $this->supa( 'PATCH', 'cyber_containers?id=eq.' . urlencode( $id ), $payload )
            : $this->supa( 'POST',  'cyber_containers', $payload );

        if ( isset( $res['error'] ) ) { wp_send_json_error( $res['error'] ); }
        $code = $res['code'] ?? 0;
        ( $code >= 200 && $code < 300 )
            ? wp_send_json_success( $res['data'][0] ?? $res['data'] )
            : wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code );
    }

    public function ajax_toggle(): void {
        check_ajax_referer( 'neoweaver_containers', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $id    = sanitize_text_field( $_POST['container_id'] ?? '' );
        $state = filter_var( $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );
        $res = $this->supa( 'PATCH', 'cyber_containers?id=eq.' . urlencode( $id ), [ 'is_active' => $state ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( [ 'is_active' => $state ] );
    }

    /* ---------------------------------------------------------------- */
    /*  CSS                                                               */
    /* ---------------------------------------------------------------- */

    private function get_css(): string { return <<<'CSS'
.nw-panel{font-family:'Chakra Petch',monospace;color:#e0e0e0}.nw-panel *{box-sizing:border-box}
.nw-panel-header{display:flex;align-items:center;justify-content:space-between;padding:20px 0 16px;border-bottom:1px solid #2a2a2a;margin-bottom:16px}
.nw-panel-title{font-size:22px;font-weight:700;color:#fff;margin:0;font-family:'Chakra Petch',monospace}
.nw-accent{color:#adff00}.nw-panel-subtitle{color:#555;font-weight:400;font-size:18px;margin-left:4px}
.nw-header-actions{display:flex;gap:8px}
.nw-btn{font-family:'Chakra Petch',monospace;font-size:12px;font-weight:600;padding:7px 16px;border-radius:5px;border:1px solid transparent;cursor:pointer;transition:all .15s;text-transform:uppercase;letter-spacing:.5px}
.nw-btn-primary{background:#adff00;color:#0a0a0a;border-color:#adff00}.nw-btn-primary:hover{background:#c8ff40}
.nw-btn-ghost{background:transparent;color:#adff00;border-color:#2e2e2e}.nw-btn-ghost:hover{border-color:#adff00}
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
.nw-table td{padding:10px 14px;vertical-align:middle}.nw-col-img{width:44px}
.nw-cont-img{width:36px;height:36px;border-radius:6px;object-fit:cover;border:1px solid #2e2e2e;background:#1a1a1a;padding:3px;filter:invert(1) sepia(1) saturate(5) hue-rotate(50deg)}
.nw-cont-img-placeholder{width:36px;height:36px;border-radius:6px;background:#1a1a1a;border:1px solid #2e2e2e;display:flex;align-items:center;justify-content:center;color:#444;font-size:18px}
.nw-cont-name{font-weight:600;color:#fff}.nw-cont-sub{font-size:11px;color:#555;margin-top:2px}
.nw-rarity{font-size:11px;padding:2px 8px;border-radius:3px;font-weight:600;text-transform:uppercase;letter-spacing:.5px}
.nw-rarity-common{background:#1e1e1e;color:#aaa;border:1px solid #2e2e2e}
.nw-rarity-uncommon{background:#0d2a00;color:#4dff4d;border:1px solid #1a4400}
.nw-rarity-rare{background:#001a33;color:#4d9fff;border:1px solid #003366}
.nw-rarity-epic{background:#1a0033;color:#cc66ff;border:1px solid #330066}
.nw-rarity-legendary{background:#2a1500;color:#ffaa00;border:1px solid #553300}
.nw-slots-badge{font-size:13px;color:#adff00;font-weight:700}
.nw-sizes{display:flex;flex-wrap:wrap;gap:4px}
.nw-size-tag{font-size:10px;padding:2px 7px;background:#1e1e1e;border:1px solid #2e2e2e;border-radius:3px;color:#888}
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
.nw-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.78);z-index:99998;display:flex;align-items:center;justify-content:center;padding:20px}
.nw-modal{background:#111;border:1px solid #2e2e2e;border-radius:10px;width:100%;max-width:700px;max-height:92vh;overflow-y:auto;display:flex;flex-direction:column;font-family:'Chakra Petch',monospace}
.nw-modal-header{display:flex;align-items:center;justify-content:space-between;padding:18px 24px 14px;border-bottom:1px solid #1e1e1e;position:sticky;top:0;background:#111;z-index:1}
.nw-modal-header h2{margin:0;font-size:16px;color:#fff;font-family:'Chakra Petch',monospace}
.nw-modal-close{background:none;border:none;color:#666;font-size:18px;cursor:pointer;padding:2px 6px;border-radius:4px}
.nw-modal-close:hover{color:#fff;background:#222}
.nw-modal-body{padding:20px 24px;flex:1}.nw-modal-footer{padding:14px 24px;border-top:1px solid #1e1e1e;display:flex;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:#111}
.nw-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.nw-field{display:flex;flex-direction:column;gap:5px}.nw-field-full{grid-column:1/-1}.nw-field-center{align-items:flex-start}
.nw-field label{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#666;font-weight:600}
.nw-req{color:#ff4444}.nw-hint{font-size:10px;color:#444;text-transform:none;letter-spacing:0;font-weight:400}
.nw-field input[type="text"],.nw-field textarea,.nw-field select{background:#0d0d0d;border:1px solid #2a2a2a;border-radius:5px;color:#e0e0e0;padding:8px 10px;font-family:'Chakra Petch',monospace;font-size:13px;transition:border-color .15s;width:100%}
.nw-field select option{background:#111;color:#e0e0e0}
.nw-field input:focus,.nw-field textarea:focus,.nw-field select:focus{outline:none;border-color:#adff00;box-shadow:0 0 0 2px rgba(173,255,0,.08)}
.nw-field textarea{resize:vertical}
.nw-stat-slider-row{display:flex;align-items:center;gap:8px;margin-top:4px}
.nw-range{width:100%;accent-color:#adff00;cursor:pointer}
.nw-range-val{font-size:13px;color:#adff00;font-weight:700;min-width:28px;text-align:right}
.nw-checkbox-group{display:flex;flex-wrap:wrap;gap:10px;margin-top:4px}
.nw-check-label{display:flex;align-items:center;gap:6px;font-size:12px;color:#aaa;cursor:pointer;text-transform:capitalize;letter-spacing:0}
.nw-check-label input[type="checkbox"]{accent-color:#adff00;width:15px;height:15px;cursor:pointer}
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
    function updateStats(d){var a=d.filter(function(r){return r.is_active!==false;}).length;$("#nw-total").text(d.length);$("#nw-active").text(a);$("#nw-inactive").text(d.length-a);}

    var rarityColors={common:'nw-rarity-common',uncommon:'nw-rarity-uncommon',rare:'nw-rarity-rare',epic:'nw-rarity-epic',legendary:'nw-rarity-legendary'};

    function renderTable(data){
        var tbody=$("#nw-containers-tbody");
        if(!data.length){tbody.html('<tr><td colspan="7" style="text-align:center;padding:32px;color:#555;">No containers found.</td></tr>');return;}
        tbody.html(data.map(function(r){
            var active=r.is_active!==false;
            var sizes=Array.isArray(r.allowed_sizes)?r.allowed_sizes:[];
            var sizesH=sizes.map(function(s){return'<span class="nw-size-tag">'+esc(s)+'</span>';}).join('');
            var rarCls=rarityColors[r.rarity]||'nw-rarity-common';
            var imgH=r.img_url?'<img src="'+esc(r.img_url)+'" class="nw-cont-img" loading="lazy" onerror="this.style.display=\'none\'">':'<div class="nw-cont-img-placeholder">🎒</div>';
            return'<tr data-id="'+r.id+'" class="'+(active?'':'nw-row-inactive')+'">'
                +'<td>'+imgH+'</td>'
                +'<td><div class="nw-cont-name">'+esc(r.name)+'</div><div class="nw-cont-sub">'+esc(r.description?r.description.substring(0,50)+(r.description.length>50?'…':''):'')+'</div></td>'
                +'<td><span class="nw-rarity '+rarCls+'">'+esc(r.rarity||'common')+'</span></td>'
                +'<td><span class="nw-slots-badge">'+( r.total_slots||'?' )+'</span></td>'
                +'<td><div class="nw-sizes">'+sizesH+'</div></td>'
                +'<td><label class="nw-toggle"><input type="checkbox" class="nw-active-toggle" data-id="'+r.id+'" '+(active?'checked':'')+'><span class="nw-toggle-slider"></span></label></td>'
                +'<td><div class="nw-row-actions"><button class="nw-action-btn nw-edit-btn" data-id="'+r.id+'">Edit</button></div></td>'
                +'</tr>';
        }).join(''));
    }

    function loadAll(){
        $("#nw-containers-tbody").html('<tr class="nw-loading-row"><td colspan="7"><div class="nw-spinner"></div> Loading…</td></tr>');
        $.post(ajaxurl,{action:"nw_containers_get_all",nonce:nonce},function(res){
            if(!res.success){notice("Error: "+res.data,"error");return;}
            all=res.data||[];renderTable(all);updateStats(all);
        }).fail(function(){notice("Request failed.","error");});
    }

    $(document).on("change",".nw-active-toggle",function(){
        var id=$(this).data("id"),val=$(this).is(":checked"),row=$(this).closest("tr");
        $.post(ajaxurl,{action:"nw_containers_toggle",nonce:nonce,container_id:id,is_active:val?1:0},function(res){
            if(res.success){row.toggleClass("nw-row-inactive",!val);all=all.map(function(r){if(r.id===id)r.is_active=val;return r;});updateStats(all);notice((val?"Activated":"Deactivated")+".","success");}
            else{notice("Toggle failed: "+res.data,"error");row.find(".nw-active-toggle").prop("checked",!val);}
        });
    });

    function openModal(id){
        $("#nw-container-form")[0].reset();
        $("#nw-field-id").val("");
        $("#nw-field-total_slots").val(5);$("#nw-val-total_slots").text(5);
        // reset checkboxes to all checked
        $("input[name='allowed_sizes[]']").prop("checked",true);
        if(id){
            var r=all.find(function(x){return x.id===id;});
            if(r){
                $("#nw-field-id").val(r.id);
                $("#nw-field-name").val(r.name||"");
                $("#nw-field-description").val(r.description||"");
                $("#nw-field-img_url").val(r.img_url||"");
                $("#nw-field-rarity").val(r.rarity||"common");
                $("#nw-field-total_slots").val(r.total_slots||5);
                $("#nw-val-total_slots").text(r.total_slots||5);
                $("#nw-field-parent_id").val(r.parent_id||"");
                $("#nw-field-is_active").prop("checked",r.is_active!==false);
                var sizes=Array.isArray(r.allowed_sizes)?r.allowed_sizes:[];
                $("input[name='allowed_sizes[]']").each(function(){$(this).prop("checked",sizes.indexOf($(this).val())!==-1);});
            }
            $("#nw-modal-title").text("Edit Container");$("#nw-save-label").text("Save Changes");
        } else {
            $("#nw-modal-title").text("New Container");$("#nw-save-label").text("Create Container");
        }
        $("#nw-modal-overlay").fadeIn(150);
    }

    $(document).on("input","#nw-field-total_slots",function(){$("#nw-val-total_slots").text($(this).val());});
    $("#nw-modal-close,#nw-cancel-btn").on("click",function(){$("#nw-modal-overlay").fadeOut(150);});
    $("#nw-modal-overlay").on("click",function(e){if($(e.target).is("#nw-modal-overlay"))$("#nw-modal-overlay").fadeOut(150);});
    $(document).on("click",".nw-edit-btn",function(){openModal($(this).data("id"));});
    $("#nw-add-btn").on("click",function(){openModal(null);});
    $("#nw-refresh-btn").on("click",loadAll);

    $("#nw-save-btn").on("click",function(){
        if(!$("#nw-field-name").val().trim()){notice("Name is required.","error");return;}
        var btn=$(this);btn.prop("disabled",true);$("#nw-save-label").text("Saving…");
        var sizes=[];
        $("input[name='allowed_sizes[]']:checked").each(function(){sizes.push($(this).val());});
        if(!sizes.length)sizes=["tiny","small","medium","large"];
        var fd={action:"nw_containers_save",nonce:nonce,"container":{
            id:$("#nw-field-id").val(),
            name:$("#nw-field-name").val(),
            description:$("#nw-field-description").val(),
            img_url:$("#nw-field-img_url").val(),
            rarity:$("#nw-field-rarity").val(),
            total_slots:$("#nw-field-total_slots").val(),
            parent_id:$("#nw-field-parent_id").val(),
            allowed_sizes:sizes.join(","),
            is_active:$("#nw-field-is_active").is(":checked")?1:0
        }};
        $.post(ajaxurl,fd,function(res){
            btn.prop("disabled",false);$("#nw-save-label").text("Save Changes");
            if(res.success){notice("Container saved!","success");$("#nw-modal-overlay").fadeOut(150);loadAll();}
            else{notice("Error: "+(res.data||"Unknown"),"error");}
        }).fail(function(){btn.prop("disabled",false);$("#nw-save-label").text("Save Changes");notice("Request failed.","error");});
    });

    loadAll();
});
JS;
    }
}

new NeoWeaver_Containers_Admin();
