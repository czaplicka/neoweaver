<?php
/**
 * NeoWeaver Admin Panel — Races (cyberraces)
 * Kolumny: id, name, parent_race, tags, gm_instructions, description,
 *          race_base_hp, img_url, preferred_tech, preferred_magic,
 *          preferred_gods, preferred_wealth, preferred_threat,
 *          preferred_moral, preferred_social, conflict_axis,
 *          conflict_side, race_base_mp, bonus, is_active, created_at
 */

if ( ! defined( 'ABSPATH' ) ) exit;
class NeoWeaver_Races_Admin {

    private string $supabase_url;
    private string $supabase_key;
    private string $page_slug = 'neoweaver-races';
    private string $menu_slug = 'neoweaver';

    public function __construct() {
        $this->supabase_url = rtrim( tw_supabase_url(), '/' );
$this->supabase_key = tw_supabase_anon_key();

        add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_nw_races_get_all', [ $this, 'ajax_get_all' ] );
        add_action( 'wp_ajax_nw_races_save',    [ $this, 'ajax_save'    ] );
        add_action( 'wp_ajax_nw_races_toggle',  [ $this, 'ajax_toggle'  ] );
    }

    public function register_menu(): void {
        // NOTE: add_menu_page() is intentionally NOT called here.
        // The top-level NeoWeaver menu is registered solely in class-nw-admin.php.
        // Each sub-module only adds its own submenu item.
        add_submenu_page( $this->menu_slug, 'NeoWeaver — Races', '🧬 Races',
            'manage_options', $this->page_slug, [ $this, 'render_page' ] );
    }

    public function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, $this->page_slug ) ) return;
        wp_enqueue_style( 'chakra-petch',
            'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap', [], null );
        wp_add_inline_style( 'chakra-petch', $this->get_css() );
        wp_add_inline_script( 'jquery', $this->get_js() );
    }

    public function render_page(): void { ?>
        <div class="wrap nw-panel" id="nw-races-panel">
            <div class="nw-panel-header">
                <h1 class="nw-panel-title"><span class="nw-accent">Neo</span>Weaver <span class="nw-panel-subtitle">/ Races</span></h1>
                <div class="nw-header-actions">
                    <button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">↻ Refresh</button>
                    <button class="nw-btn nw-btn-primary" id="nw-add-btn">+ New Race</button>
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
                        <th>Conflict Axis</th>
                        <th>Tags</th>
                        <th>HP / MP</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody id="nw-races-tbody">
                        <tr class="nw-loading-row"><td colspan="7"><div class="nw-spinner"></div> Loading…</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- MODAL -->
            <div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
                <div class="nw-modal">
                    <div class="nw-modal-header">
                        <h2 id="nw-modal-title">Edit Race</h2>
                        <button class="nw-modal-close" id="nw-modal-close">✕</button>
                    </div>
                    <div class="nw-modal-body">
                        <form id="nw-race-form">
                            <input type="hidden" id="nw-field-id" name="id">
                            <div class="nw-form-grid">

                                <div class="nw-field nw-field-full">
                                    <label>Name <span class="nw-req">*</span></label>
                                    <input type="text" id="nw-field-name" name="name" required placeholder="e.g. Spirit">
                                </div>

                                <div class="nw-field">
                                    <label>Parent Race</label>
                                    <input type="text" id="nw-field-parent_race" name="parent_race" placeholder="e.g. Homo Sapiens">
                                </div>

                                <div class="nw-field">
                                    <label>Image filename</label>
                                    <input type="text" id="nw-field-img_url" name="img_url" placeholder="e.g. spirit.svg">
                                </div>

                                <div class="nw-field">
                                    <label>Conflict Axis</label>
                                    <input type="text" id="nw-field-conflict_axis" name="conflict_axis" placeholder="e.g. organic_vs_synthetic">
                                </div>

                                <div class="nw-field">
                                    <label>Conflict Side</label>
                                    <input type="text" id="nw-field-conflict_side" name="conflict_side" placeholder="e.g. organic">
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>Description</label>
                                    <textarea id="nw-field-description" name="description" rows="3"></textarea>
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>GM Instructions</label>
                                    <textarea id="nw-field-gm_instructions" name="gm_instructions" rows="3"></textarea>
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>Bonus / Notes</label>
                                    <input type="text" id="nw-field-bonus" name="bonus" placeholder="Optional bonus info">
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>Tags <span class="nw-hint">(comma-separated → JSON array)</span></label>
                                    <input type="text" id="nw-field-tags" name="tags" placeholder="e.g. Intangible, Mystic, Resonance">
                                </div>

                                <div class="nw-field nw-field-center">
                                    <label>Active</label>
                                    <label class="nw-toggle">
                                        <input type="checkbox" id="nw-field-is_active" name="is_active" checked>
                                        <span class="nw-toggle-slider"></span>
                                    </label>
                                </div>

                            </div><!-- .nw-form-grid -->

                            <!-- Stat sliders -->
                            <div class="nw-stats-section">
                                <h3 class="nw-stats-title">Base Stats &amp; Preferences</h3>
                                <div class="nw-stats-grid">
                                    <?php
                                    $stats = [
                                        ['key'=>'race_base_hp',    'label'=>'Base HP',  'min'=>1, 'max'=>20, 'def'=>8 ],
                                        ['key'=>'race_base_mp',    'label'=>'Base MP',  'min'=>1, 'max'=>20, 'def'=>8 ],
                                        ['key'=>'preferred_tech',  'label'=>'Tech',     'min'=>0, 'max'=>10, 'def'=>3 ],
                                        ['key'=>'preferred_magic', 'label'=>'Magic',    'min'=>0, 'max'=>10, 'def'=>3 ],
                                        ['key'=>'preferred_gods',  'label'=>'Gods',     'min'=>0, 'max'=>10, 'def'=>3 ],
                                        ['key'=>'preferred_wealth','label'=>'Wealth',   'min'=>0, 'max'=>10, 'def'=>3 ],
                                        ['key'=>'preferred_threat','label'=>'Threat',   'min'=>0, 'max'=>10, 'def'=>2 ],
                                        ['key'=>'preferred_moral', 'label'=>'Moral',    'min'=>0, 'max'=>10, 'def'=>3 ],
                                        ['key'=>'preferred_social','label'=>'Social',   'min'=>0, 'max'=>10, 'def'=>2 ],
                                    ];
                                    foreach ( $stats as $s ) : ?>
                                    <div class="nw-stat-slider">
                                        <label><?php echo esc_html( $s['label'] ); ?></label>
                                        <div class="nw-stat-slider-row">
                                            <input type="range"
                                                id="nw-field-<?php echo esc_attr( $s['key'] ); ?>"
                                                name="<?php echo esc_attr( $s['key'] ); ?>"
                                                min="<?php echo esc_attr( $s['min'] ); ?>"
                                                max="<?php echo esc_attr( $s['max'] ); ?>"
                                                value="<?php echo esc_attr( $s['def'] ); ?>"
                                                class="nw-range">
                                            <span class="nw-range-val" id="nw-val-<?php echo esc_attr( $s['key'] ); ?>"><?php echo esc_html( $s['def'] ); ?></span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                        </form>
                    </div>
                    <div class="nw-modal-footer">
                        <button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
                        <button class="nw-btn nw-btn-primary" id="nw-save-btn"><span id="nw-save-label">Save Race</span></button>
                    </div>
                </div>
            </div>

            <input type="hidden" id="nw-nonce" value="<?php echo esc_attr( wp_create_nonce( 'neoweaver_races' ) ); ?>">
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
        check_ajax_referer( 'neoweaver_races', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $res = $this->supa( 'GET',
            'cyberraces?select=id,name,parent_race,tags,gm_instructions,description,race_base_hp,img_url,preferred_tech,preferred_magic,preferred_gods,preferred_wealth,preferred_threat,preferred_moral,preferred_social,conflict_axis,conflict_side,race_base_mp,bonus,is_active&order=name.asc'
        );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( $res['data'] );
    }

    public function ajax_save(): void {
        check_ajax_referer( 'neoweaver_races', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $raw  = $_POST['race'] ?? [];
        $id   = sanitize_text_field( $raw['id'] ?? '' );
        $tags = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $raw['tags'] ?? '' ) ) ) ) );

        $payload = [
            'name'             => sanitize_text_field(     $raw['name']             ?? '' ),
            'parent_race'      => sanitize_text_field(     $raw['parent_race']      ?? '' ) ?: null,
            'description'      => sanitize_textarea_field( $raw['description']      ?? '' ) ?: null,
            'gm_instructions'  => sanitize_textarea_field( $raw['gm_instructions']  ?? '' ) ?: null,
            'img_url'          => sanitize_text_field(     $raw['img_url']          ?? '' ) ?: null,
            'bonus'            => sanitize_text_field(     $raw['bonus']            ?? '' ) ?: null,
            'conflict_axis'    => sanitize_text_field(     $raw['conflict_axis']    ?? '' ) ?: null,
            'conflict_side'    => sanitize_text_field(     $raw['conflict_side']    ?? '' ) ?: null,
            'tags'             => $tags,
            'race_base_hp'     => (int)( $raw['race_base_hp']     ?? 8 ),
            'race_base_mp'     => (int)( $raw['race_base_mp']     ?? 8 ),
            'preferred_tech'   => (int)( $raw['preferred_tech']   ?? 3 ),
            'preferred_magic'  => (int)( $raw['preferred_magic']  ?? 3 ),
            'preferred_gods'   => (int)( $raw['preferred_gods']   ?? 3 ),
            'preferred_wealth' => (int)( $raw['preferred_wealth'] ?? 3 ),
            'preferred_threat' => (int)( $raw['preferred_threat'] ?? 2 ),
            'preferred_moral'  => (int)( $raw['preferred_moral']  ?? 3 ),
            'preferred_social' => (int)( $raw['preferred_social'] ?? 2 ),
            'is_active'        => filter_var( $raw['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN ),
        ];

        $res = $id
            ? $this->supa( 'PATCH', 'cyberraces?id=eq.' . urlencode( $id ), $payload )
            : $this->supa( 'POST',  'cyberraces', $payload );

        if ( isset( $res['error'] ) ) { wp_send_json_error( $res['error'] ); }
        $code = $res['code'] ?? 0;
        ( $code >= 200 && $code < 300 )
            ? wp_send_json_success( $res['data'][0] ?? $res['data'] )
            : wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code );
    }

    public function ajax_toggle(): void {
        check_ajax_referer( 'neoweaver_races', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $id    = sanitize_text_field( $_POST['race_id'] ?? '' );
        $state = filter_var( $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );
        $res = $this->supa( 'PATCH', 'cyberraces?id=eq.' . urlencode( $id ), [ 'is_active' => $state ] );
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
.nw-race-img{width:36px;height:36px;border-radius:50%;object-fit:cover;border:1px solid #2e2e2e;background:#1a1a1a;padding:3px;filter:invert(1) sepia(1) saturate(5) hue-rotate(50deg)}
.nw-race-img-placeholder{width:36px;height:36px;border-radius:50%;background:#1a1a1a;border:1px solid #2e2e2e;display:flex;align-items:center;justify-content:center;color:#444;font-size:16px}
.nw-race-name{font-weight:600;color:#fff}.nw-race-sub{font-size:11px;color:#555;margin-top:2px}
.nw-tags{display:flex;flex-wrap:wrap;gap:4px}
.nw-tag{font-size:10px;padding:2px 7px;background:#1e1e1e;border:1px solid #2e2e2e;border-radius:3px;color:#888}
.nw-hp-mp{font-size:12px;color:#aaa;white-space:nowrap}.nw-hp{color:#ff6b35;font-weight:600}.nw-mp{color:#00d4ff;font-weight:600}
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
.nw-modal{background:#111;border:1px solid #2e2e2e;border-radius:10px;width:100%;max-width:740px;max-height:92vh;overflow-y:auto;display:flex;flex-direction:column;font-family:'Chakra Petch',monospace}
.nw-modal-header{display:flex;align-items:center;justify-content:space-between;padding:18px 24px 14px;border-bottom:1px solid #1e1e1e;position:sticky;top:0;background:#111;z-index:1}
.nw-modal-header h2{margin:0;font-size:16px;color:#fff;font-family:'Chakra Petch',monospace}
.nw-modal-close{background:none;border:none;color:#666;font-size:18px;cursor:pointer;padding:2px 6px;border-radius:4px}
.nw-modal-close:hover{color:#fff;background:#222}
.nw-modal-body{padding:20px 24px;flex:1}.nw-modal-footer{padding:14px 24px;border-top:1px solid #1e1e1e;display:flex;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:#111}
.nw-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.nw-field{display:flex;flex-direction:column;gap:5px}.nw-field-full{grid-column:1/-1}.nw-field-center{align-items:flex-start}
.nw-field label{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#666;font-weight:600}
.nw-req{color:#ff4444}.nw-hint{font-size:10px;color:#444;text-transform:none;letter-spacing:0;font-weight:400}
.nw-field input[type="text"],.nw-field textarea{background:#0d0d0d;border:1px solid #2a2a2a;border-radius:5px;color:#e0e0e0;padding:8px 10px;font-family:'Chakra Petch',monospace;font-size:13px;transition:border-color .15s;width:100%}
.nw-field input:focus,.nw-field textarea:focus{outline:none;border-color:#adff00;box-shadow:0 0 0 2px rgba(173,255,0,.08)}
.nw-field textarea{resize:vertical}
.nw-stats-section{margin-top:20px;padding-top:16px;border-top:1px solid #1e1e1e}
.nw-stats-title{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#666;margin:0 0 12px;font-family:'Chakra Petch',monospace}
.nw-stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.nw-stat-slider{display:flex;flex-direction:column;gap:4px}
.nw-stat-slider label{font-size:11px;color:#666;text-transform:uppercase;letter-spacing:.5px}
.nw-stat-slider-row{display:flex;align-items:center;gap:8px}
.nw-range{width:100%;accent-color:#adff00;cursor:pointer}
.nw-range-val{font-size:13px;color:#adff00;font-weight:700;min-width:22px;text-align:right}
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
    function updateStats(d){var a=d.filter(function(r){return r.is_active!==false;}).length;$("#nw-total").text(d.length);$("#nw-active").text(a);$("#nw-inactive").text(d.length-a);}

    function renderTable(data){
        var tbody=$("#nw-races-tbody");
        if(!data.length){tbody.html('<tr><td colspan="7" style="text-align:center;padding:32px;color:#555;">No races found.</td></tr>');return;}
        tbody.html(data.map(function(r){
            var tags=Array.isArray(r.tags)?r.tags:[];
            var tagsH=tags.slice(0,4).map(function(t){return'<span class="nw-tag">'+esc(t)+'</span>';}).join('')+(tags.length>4?'<span class="nw-tag">+'+(tags.length-4)+'</span>':'');
            var active=r.is_active!==false;
            var imgH=r.img_url?'<img src="'+esc(r.img_url)+'" class="nw-race-img" loading="lazy" onerror="this.style.display=\'none\'">':'<div class="nw-race-img-placeholder">🧬</div>';
            return'<tr data-id="'+r.id+'" class="'+(active?'':'nw-row-inactive')+'">'
                +'<td>'+imgH+'</td>'
                +'<td><div class="nw-race-name">'+esc(r.name)+'</div><div class="nw-race-sub">'+esc(r.parent_race||'')+'</div></td>'
                +'<td>'+esc(r.conflict_axis||'—')+'</td>'
                +'<td><div class="nw-tags">'+tagsH+'</div></td>'
                +'<td class="nw-hp-mp"><span class="nw-hp">HP '+(r.race_base_hp||'?')+'</span> / <span class="nw-mp">MP '+(r.race_base_mp||'?')+'</span></td>'
                +'<td><label class="nw-toggle"><input type="checkbox" class="nw-active-toggle" data-id="'+r.id+'" '+(active?'checked':'')+'><span class="nw-toggle-slider"></span></label></td>'
                +'<td><div class="nw-row-actions"><button class="nw-action-btn nw-edit-btn" data-id="'+r.id+'">Edit</button></div></td>'
                +'</tr>';
        }).join(''));
    }

    function loadAll(){
        $("#nw-races-tbody").html('<tr class="nw-loading-row"><td colspan="7"><div class="nw-spinner"></div> Loading…</td></tr>');
        $.post(ajaxurl,{action:"nw_races_get_all",nonce:nonce},function(res){
            if(!res.success){notice("Error: "+res.data,"error");return;}
            all=res.data||[];renderTable(all);updateStats(all);
        }).fail(function(){notice("Request failed.","error");});
    }

    $(document).on("change",".nw-active-toggle",function(){
        var id=$(this).data("id"),val=$(this).is(":checked"),row=$(this).closest("tr");
        $.post(ajaxurl,{action:"nw_races_toggle",nonce:nonce,race_id:id,is_active:val?1:0},function(res){
            if(res.success){row.toggleClass("nw-row-inactive",!val);all=all.map(function(r){if(r.id===id)r.is_active=val;return r;});updateStats(all);notice((val?"Activated":"Deactivated")+".","success");}
            else{notice("Toggle failed: "+res.data,"error");row.find(".nw-active-toggle").prop("checked",!val);}
        });
    });

    var sliderKeys=["race_base_hp","race_base_mp","preferred_tech","preferred_magic","preferred_gods","preferred_wealth","preferred_threat","preferred_moral","preferred_social"];
    var sliderDefs={"race_base_hp":8,"race_base_mp":8,"preferred_tech":3,"preferred_magic":3,"preferred_gods":3,"preferred_wealth":3,"preferred_threat":2,"preferred_moral":3,"preferred_social":2};

    function openModal(id){
        $("#nw-race-form")[0].reset();
        $("#nw-field-id").val("");
        sliderKeys.forEach(function(k){var d=sliderDefs[k];$("#nw-field-"+k).val(d);$("#nw-val-"+k).text(d);});
        if(id){
            var r=all.find(function(x){return x.id===id;});
            if(r){
                $("#nw-field-id").val(r.id);
                $("#nw-field-name").val(r.name||"");
                $("#nw-field-parent_race").val(r.parent_race||"");
                $("#nw-field-description").val(r.description||"");
                $("#nw-field-gm_instructions").val(r.gm_instructions||"");
                $("#nw-field-img_url").val(r.img_url||"");
                $("#nw-field-bonus").val(r.bonus||"");
                $("#nw-field-conflict_axis").val(r.conflict_axis||"");
                $("#nw-field-conflict_side").val(r.conflict_side||"");
                $("#nw-field-tags").val(tagsStr(r.tags));
                $("#nw-field-is_active").prop("checked",r.is_active!==false);
                sliderKeys.forEach(function(k){var v=r[k]!==undefined?r[k]:sliderDefs[k];$("#nw-field-"+k).val(v);$("#nw-val-"+k).text(v);});
            }
            $("#nw-modal-title").text("Edit Race");$("#nw-save-label").text("Save Changes");
        } else {
            $("#nw-modal-title").text("New Race");$("#nw-save-label").text("Create Race");
        }
        $("#nw-modal-overlay").fadeIn(150);
    }

    $(document).on("input",".nw-range",function(){$("#nw-val-"+$(this).attr("id").replace("nw-field-","")).text($(this).val());});
    $("#nw-modal-close,#nw-cancel-btn").on("click",function(){$("#nw-modal-overlay").fadeOut(150);});
    $("#nw-modal-overlay").on("click",function(e){if($(e.target).is("#nw-modal-overlay"))$("#nw-modal-overlay").fadeOut(150);});
    $(document).on("click",".nw-edit-btn",function(){openModal($(this).data("id"));});
    $("#nw-add-btn").on("click",function(){openModal(null);});
    $("#nw-refresh-btn").on("click",loadAll);

    $("#nw-save-btn").on("click",function(){
        if(!$("#nw-field-name").val().trim()){notice("Name is required.","error");return;}
        var btn=$(this);btn.prop("disabled",true);$("#nw-save-label").text("Saving…");
        var fd={action:"nw_races_save",nonce:nonce,"race":{}};
        $("#nw-race-form").serializeArray().forEach(function(f){if(f.name!=="is_active")fd["race"][f.name]=f.value;});
        fd["race"].is_active=$("#nw-field-is_active").is(":checked")?1:0;
        $.post(ajaxurl,fd,function(res){
            btn.prop("disabled",false);$("#nw-save-label").text("Save Changes");
            if(res.success){notice("Race saved!","success");$("#nw-modal-overlay").fadeOut(150);loadAll();}
            else{notice("Error: "+(res.data||"Unknown"),"error");}
        }).fail(function(){btn.prop("disabled",false);$("#nw-save-label").text("Save Changes");notice("Request failed.","error");});
    });

    loadAll();
});
JS;
    }
}

new NeoWeaver_Races_Admin();
