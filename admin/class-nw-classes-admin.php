<?php
/**
 * NeoWeaver Admin Panel — Classes (cyberclasses)
 * List, inline edit modal, active/inactive toggle
 * Mirrors the Races panel architecture.
 *
 * Requires: wp-config.php defines SUPABASE_URL and SUPABASE_KEY
 * Requires: cyberclasses table — see cyberclasses-migration.sql
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Classes_Admin {

    private string $supabase_url;
    private string $supabase_key;
    private string $page_slug = 'neoweaver-classes';
    private string $parent_slug = 'neoweaver';

    public function __construct() {
        $this->supabase_url = defined( 'SUPABASE_URL' ) ? rtrim( SUPABASE_URL, '/' ) : '';
        $this->supabase_key = defined( 'SUPABASE_KEY' ) ? SUPABASE_KEY : '';

        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_nw_classes_get_all', [ $this, 'ajax_get_all' ] );
        add_action( 'wp_ajax_nw_classes_save',    [ $this, 'ajax_save'    ] );
        add_action( 'wp_ajax_nw_classes_toggle',  [ $this, 'ajax_toggle'  ] );
        add_action( 'wp_ajax_nw_classes_delete',  [ $this, 'ajax_delete'  ] );
    }

    /* -------------------------------------------------------------- */
    /*  MENU                                                            */
    /* -------------------------------------------------------------- */

    public function register_menu(): void {
        add_submenu_page(
            $this->parent_slug,
            'NeoWeaver — Classes',
            '⚡ Classes',
            'manage_options',
            $this->page_slug,
            [ $this, 'render_page' ]
        );
    }

    /* -------------------------------------------------------------- */
    /*  ASSETS                                                          */
    /* -------------------------------------------------------------- */

    public function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, $this->page_slug ) ) return;

        wp_enqueue_style(
            'chakra-petch',
            'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
            [], null
        );
        wp_add_inline_style( 'chakra-petch', $this->get_css() );
        wp_add_inline_script( 'jquery', $this->get_js() );
    }

    /* -------------------------------------------------------------- */
    /*  RENDER                                                          */
    /* -------------------------------------------------------------- */

    public function render_page(): void {
        ?>
        <div class="wrap nw-panel" id="nw-classes-panel">

            <div class="nw-panel-header">
                <h1 class="nw-panel-title">
                    <span class="nw-accent">Neo</span>Weaver
                    <span class="nw-panel-subtitle">/ Classes</span>
                </h1>
                <div class="nw-header-actions">
                    <button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">↻ Refresh</button>
                    <button class="nw-btn nw-btn-primary" id="nw-add-btn">+ New Class</button>
                </div>
            </div>

            <div id="nw-notice" class="nw-notice" style="display:none;"></div>

            <div class="nw-stats-bar">
                <span class="nw-stat-pill">Total: <strong id="nw-total">—</strong></span>
                <span class="nw-stat-pill nw-pill-active">Active: <strong id="nw-active">—</strong></span>
                <span class="nw-stat-pill nw-pill-inactive">Inactive: <strong id="nw-inactive">—</strong></span>
            </div>

            <div class="nw-table-wrap">
                <table class="nw-table" id="nw-classes-table">
                    <thead>
                        <tr>
                            <th class="nw-col-img"></th>
                            <th>Name</th>
                            <th>Primary Role</th>
                            <th>Tags</th>
                            <th>HP / MP / Skills</th>
                            <th>Active</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="nw-classes-tbody">
                        <tr class="nw-loading-row">
                            <td colspan="7"><div class="nw-spinner"></div> Loading classes…</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Modal -->
            <div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
                <div class="nw-modal">
                    <div class="nw-modal-header">
                        <h2 id="nw-modal-title">Edit Class</h2>
                        <button class="nw-modal-close" id="nw-modal-close">✕</button>
                    </div>
                    <div class="nw-modal-body">
                        <form id="nw-class-form">
                            <input type="hidden" id="nw-field-id" name="id">

                            <div class="nw-form-grid">

                                <div class="nw-field nw-field-full">
                                    <label>Name <span class="nw-req">*</span></label>
                                    <input type="text" id="nw-field-name" name="name" required placeholder="e.g. Mercenary">
                                </div>

                                <div class="nw-field">
                                    <label>Primary Role</label>
                                    <select id="nw-field-primaryrole" name="primaryrole" class="nw-select">
                                        <option value="">— choose —</option>
                                        <option value="combat">Combat</option>
                                        <option value="stealth">Stealth</option>
                                        <option value="support">Support</option>
                                        <option value="tech">Tech</option>
                                        <option value="magic">Magic</option>
                                        <option value="social">Social</option>
                                        <option value="hybrid">Hybrid</option>
                                    </select>
                                </div>

                                <div class="nw-field">
                                    <label>Starting Skills</label>
                                    <input type="number" id="nw-field-startingskills" name="startingskills" min="1" max="20" value="3" class="nw-input-num">
                                </div>

                                <div class="nw-field">
                                    <label>Conflict Axis</label>
                                    <input type="text" id="nw-field-conflictaxis" name="conflictaxis" placeholder="e.g. organicvssynthetic">
                                </div>

                                <div class="nw-field">
                                    <label>Conflict Side</label>
                                    <input type="text" id="nw-field-conflictside" name="conflictside" placeholder="e.g. synthetic">
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>Description</label>
                                    <textarea id="nw-field-description" name="description" rows="3" placeholder="Public lore description…"></textarea>
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>GM Instructions</label>
                                    <textarea id="nw-field-gminstructions" name="gminstructions" rows="3" placeholder="Internal GM notes for AI…"></textarea>
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>Tags <span class="nw-hint">(comma-separated → JSON array)</span></label>
                                    <input type="text" id="nw-field-tags" name="tags" placeholder="e.g. combat, heavy, soldier">
                                </div>

                                <div class="nw-field">
                                    <label>Image URL</label>
                                    <input type="url" id="nw-field-imgurl" name="imgurl" placeholder="https://…">
                                </div>

                                <div class="nw-field nw-field-center">
                                    <label>Active</label>
                                    <label class="nw-toggle">
                                        <input type="checkbox" id="nw-field-active" name="is_active" checked>
                                        <span class="nw-toggle-slider"></span>
                                    </label>
                                </div>

                            </div><!-- .nw-form-grid -->

                            <!-- HP / MP quick inputs -->
                            <div class="nw-stats-section">
                                <h3 class="nw-stats-title">Base Stats</h3>
                                <div class="nw-basestats-row">
                                    <div class="nw-basestat">
                                        <label>Base HP</label>
                                        <input type="number" id="nw-field-basehp" name="basehp" min="1" max="30" value="10" class="nw-input-num">
                                    </div>
                                    <div class="nw-basestat">
                                        <label>Base MP</label>
                                        <input type="number" id="nw-field-basemp" name="basemp" min="1" max="30" value="8" class="nw-input-num">
                                    </div>
                                </div>
                            </div>

                            <!-- Attribute sliders -->
                            <div class="nw-stats-section">
                                <h3 class="nw-stats-title">Preferred Attributes <span class="nw-hint">(0–10)</span></h3>
                                <div class="nw-stats-grid">
                                    <?php
                                    $attrs = [
                                        ['key' => 'preferredbody',   'label' => 'Body'],
                                        ['key' => 'preferredreflex', 'label' => 'Reflex'],
                                        ['key' => 'preferredmind',   'label' => 'Mind'],
                                        ['key' => 'preferredspirit', 'label' => 'Spirit'],
                                    ];
                                    foreach ( $attrs as $a ) : ?>
                                    <div class="nw-stat-slider">
                                        <label><?php echo esc_html( $a['label'] ); ?></label>
                                        <div class="nw-stat-slider-row">
                                            <input type="range"
                                                id="nw-field-<?php echo esc_attr( $a['key'] ); ?>"
                                                name="<?php echo esc_attr( $a['key'] ); ?>"
                                                min="0" max="10" value="3" class="nw-range">
                                            <span class="nw-range-val" id="nw-val-<?php echo esc_attr( $a['key'] ); ?>">3</span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                        </form>
                    </div><!-- .nw-modal-body -->

                    <div class="nw-modal-footer">
                        <button class="nw-btn nw-btn-danger" id="nw-delete-btn" style="display:none; margin-right:auto;">🗑 Delete</button>
                        <button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
                        <button class="nw-btn nw-btn-primary" id="nw-save-btn">
                            <span id="nw-save-label">Save Class</span>
                        </button>
                    </div>
                </div>
            </div><!-- .nw-modal-overlay -->

            <input type="hidden" id="nw-nonce" value="<?php echo esc_attr( wp_create_nonce( 'neoweaver_classes' ) ); ?>">
        </div>
        <?php
    }

    /* -------------------------------------------------------------- */
    /*  SUPABASE HELPER                                                 */
    /* -------------------------------------------------------------- */

    private function supa( string $method, string $endpoint, array $body = [], array $extra = [] ): array {
        $args = [
            'method'  => $method,
            'timeout' => 10,
            'headers' => array_merge([
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

    /* -------------------------------------------------------------- */
    /*  AJAX: GET ALL                                                   */
    /* -------------------------------------------------------------- */

    public function ajax_get_all(): void {
        check_ajax_referer( 'neoweaver_classes', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $res = $this->supa( 'GET',
            'cyberclasses?select=id,name,description,gminstructions,tags,imgurl,basehp,basemp,startingskills,primaryrole,preferredbody,preferredreflex,preferredmind,preferredspirit,conflictaxis,conflictside,is_active&order=name.asc'
        );

        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( $res['data'] );
    }

    /* -------------------------------------------------------------- */
    /*  AJAX: SAVE (INSERT or PATCH)                                    */
    /* -------------------------------------------------------------- */

    public function ajax_save(): void {
        check_ajax_referer( 'neoweaver_classes', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $raw = $_POST['class'] ?? [];
        $id  = sanitize_text_field( $raw['id'] ?? '' );

        $tags_raw = sanitize_text_field( $raw['tags'] ?? '' );
        $tags_arr = array_values( array_filter( array_map( 'trim', explode( ',', $tags_raw ) ) ) );

        $payload = [
            'name'            => sanitize_text_field( $raw['name'] ?? '' ),
            'description'     => sanitize_textarea_field( $raw['description'] ?? '' ) ?: null,
            'gminstructions'  => sanitize_textarea_field( $raw['gminstructions'] ?? '' ) ?: null,
            'tags'            => $tags_arr,
            'imgurl'          => esc_url_raw( $raw['imgurl'] ?? '' ) ?: null,
            'primaryrole'     => sanitize_text_field( $raw['primaryrole'] ?? '' ) ?: null,
            'conflictaxis'    => sanitize_text_field( $raw['conflictaxis'] ?? '' ) ?: null,
            'conflictside'    => sanitize_text_field( $raw['conflictside'] ?? '' ) ?: null,
            'basehp'          => (int) ( $raw['basehp']          ?? 10 ),
            'basemp'          => (int) ( $raw['basemp']          ?? 8  ),
            'startingskills'  => (int) ( $raw['startingskills']  ?? 3  ),
            'preferredbody'   => (int) ( $raw['preferredbody']   ?? 3  ),
            'preferredreflex' => (int) ( $raw['preferredreflex'] ?? 3  ),
            'preferredmind'   => (int) ( $raw['preferredmind']   ?? 3  ),
            'preferredspirit' => (int) ( $raw['preferredspirit'] ?? 3  ),
            'is_active'       => filter_var( $raw['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN ),
        ];

        if ( $id ) {
            $res = $this->supa( 'PATCH', 'cyberclasses?id=eq.' . urlencode( $id ), $payload );
        } else {
            $res = $this->supa( 'POST', 'cyberclasses', $payload );
        }

        if ( isset( $res['error'] ) ) { wp_send_json_error( $res['error'] ); }
        $code = $res['code'] ?? 0;
        $code >= 200 && $code < 300
            ? wp_send_json_success( $res['data'][0] ?? $res['data'] )
            : wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code );
    }

    /* -------------------------------------------------------------- */
    /*  AJAX: TOGGLE ACTIVE                                             */
    /* -------------------------------------------------------------- */

    public function ajax_toggle(): void {
        check_ajax_referer( 'neoweaver_classes', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $id    = sanitize_text_field( $_POST['class_id'] ?? '' );
        $state = filter_var( $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );

        $res = $this->supa( 'PATCH', 'cyberclasses?id=eq.' . urlencode( $id ), [ 'is_active' => $state ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( [ 'is_active' => $state ] );
    }

    /* -------------------------------------------------------------- */
    /*  AJAX: DELETE                                                    */
    /* -------------------------------------------------------------- */

    public function ajax_delete(): void {
        check_ajax_referer( 'neoweaver_classes', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $id = sanitize_text_field( $_POST['class_id'] ?? '' );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );

        $res = $this->supa( 'DELETE', 'cyberclasses?id=eq.' . urlencode( $id ), [], [ 'Prefer' => '' ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( 'deleted' );
    }

    /* -------------------------------------------------------------- */
    /*  CSS (shared NW panel skin)                                      */
    /* -------------------------------------------------------------- */

    private function get_css(): string { return <<<'CSS'
.nw-panel { font-family: 'Chakra Petch', monospace; color: #e0e0e0; }
.nw-panel * { box-sizing: border-box; }

.nw-panel-header { display:flex; align-items:center; justify-content:space-between; padding:20px 0 16px; border-bottom:1px solid #2a2a2a; margin-bottom:16px; }
.nw-panel-title  { font-size:22px; font-weight:700; color:#fff; margin:0; font-family:'Chakra Petch',monospace; }
.nw-accent       { color:#adff00; }
.nw-panel-subtitle { color:#555; font-weight:400; font-size:18px; margin-left:4px; }
.nw-header-actions { display:flex; gap:8px; }

.nw-btn { font-family:'Chakra Petch',monospace; font-size:12px; font-weight:600; padding:7px 16px; border-radius:5px; border:1px solid transparent; cursor:pointer; transition:all .15s ease; text-transform:uppercase; letter-spacing:.5px; }
.nw-btn-primary  { background:#adff00; color:#0a0a0a; border-color:#adff00; }
.nw-btn-primary:hover { background:#c8ff40; }
.nw-btn-ghost    { background:transparent; color:#adff00; border-color:#2e2e2e; }
.nw-btn-ghost:hover { border-color:#adff00; }
.nw-btn-danger   { background:transparent; color:#ff4444; border-color:#3a1111; }
.nw-btn-danger:hover { background:#2a0000; border-color:#ff4444; }

.nw-stats-bar { display:flex; gap:10px; margin-bottom:16px; }
.nw-stat-pill { font-size:12px; padding:4px 12px; border-radius:20px; background:#1a1a1a; border:1px solid #2e2e2e; color:#aaa; }
.nw-stat-pill strong { color:#fff; }
.nw-pill-active { border-color:#adff00; }
.nw-pill-active strong { color:#adff00; }
.nw-pill-inactive strong { color:#ff6b35; }

.nw-notice { padding:10px 16px; border-radius:6px; margin-bottom:14px; font-size:13px; border-left:3px solid; }
.nw-notice-success { background:#0a2800; border-color:#adff00; color:#adff00; }
.nw-notice-error   { background:#2a0000; border-color:#ff4444; color:#ff4444; }

.nw-table-wrap { background:#111; border:1px solid #222; border-radius:8px; overflow:hidden; }
.nw-table { width:100%; border-collapse:collapse; font-size:13px; }
.nw-table thead tr { background:#1a1a1a; border-bottom:1px solid #2a2a2a; }
.nw-table th { padding:10px 14px; text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.8px; color:#666; font-weight:600; }
.nw-table tbody tr { border-bottom:1px solid #1e1e1e; transition:background .12s; }
.nw-table tbody tr:last-child { border-bottom:none; }
.nw-table tbody tr:hover { background:#161616; }
.nw-table td { padding:10px 14px; vertical-align:middle; }

.nw-col-img { width:44px; }
.nw-race-img { width:36px; height:36px; border-radius:50%; object-fit:cover; border:1px solid #2e2e2e; background:#1a1a1a; }
.nw-race-img-placeholder { width:36px; height:36px; border-radius:50%; background:#1a1a1a; border:1px solid #2e2e2e; display:flex; align-items:center; justify-content:center; color:#444; font-size:16px; }
.nw-race-name { font-weight:600; color:#fff; }
.nw-race-sub  { font-size:11px; color:#666; margin-top:2px; }

.nw-role-badge { font-size:10px; padding:2px 8px; border-radius:3px; background:#1a1a2e; border:1px solid #2e2e4e; color:#7ca4ff; text-transform:uppercase; letter-spacing:.5px; }
.nw-tags { display:flex; flex-wrap:wrap; gap:4px; }
.nw-tag  { font-size:10px; padding:2px 7px; background:#1e1e1e; border:1px solid #2e2e2e; border-radius:3px; color:#888; }

.nw-hp-mp { font-size:12px; color:#aaa; white-space:nowrap; }
.nw-hp  { color:#ff6b35; font-weight:600; }
.nw-mp  { color:#00d4ff; font-weight:600; }
.nw-sk  { color:#adff00; font-weight:600; }

.nw-toggle { position:relative; display:inline-block; width:40px; height:22px; }
.nw-toggle input { opacity:0; width:0; height:0; }
.nw-toggle-slider { position:absolute; inset:0; background:#2a2a2a; border-radius:22px; cursor:pointer; transition:background .2s; border:1px solid #3a3a3a; }
.nw-toggle-slider::before { content:''; position:absolute; width:16px; height:16px; left:2px; top:2px; background:#555; border-radius:50%; transition:all .2s; }
.nw-toggle input:checked + .nw-toggle-slider { background:#1a3300; border-color:#adff00; }
.nw-toggle input:checked + .nw-toggle-slider::before { background:#adff00; transform:translateX(18px); }

.nw-row-inactive td:not(:last-child):not(:first-child) { opacity:.4; }

.nw-row-actions { display:flex; gap:6px; }
.nw-action-btn  { font-family:'Chakra Petch',monospace; font-size:11px; padding:4px 10px; border-radius:4px; border:1px solid #2e2e2e; background:transparent; color:#aaa; cursor:pointer; transition:all .15s; text-transform:uppercase; }
.nw-action-btn:hover { border-color:#adff00; color:#adff00; }

.nw-loading-row td { text-align:center; padding:32px; color:#555; }
.nw-spinner { display:inline-block; width:16px; height:16px; border:2px solid #333; border-top-color:#adff00; border-radius:50%; animation:nw-spin .6s linear infinite; vertical-align:middle; margin-right:8px; }
@keyframes nw-spin { to { transform:rotate(360deg); } }

/* Modal */
.nw-modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.75); z-index:99998; display:flex; align-items:center; justify-content:center; padding:20px; }
.nw-modal { background:#111; border:1px solid #2e2e2e; border-radius:10px; width:100%; max-width:720px; max-height:90vh; overflow-y:auto; display:flex; flex-direction:column; font-family:'Chakra Petch',monospace; }
.nw-modal-header { display:flex; align-items:center; justify-content:space-between; padding:18px 24px 14px; border-bottom:1px solid #1e1e1e; position:sticky; top:0; background:#111; z-index:1; }
.nw-modal-header h2 { margin:0; font-size:16px; color:#fff; font-family:'Chakra Petch',monospace; }
.nw-modal-close { background:none; border:none; color:#666; font-size:18px; cursor:pointer; padding:2px 6px; border-radius:4px; }
.nw-modal-close:hover { color:#fff; background:#222; }
.nw-modal-body   { padding:20px 24px; flex:1; }
.nw-modal-footer { padding:14px 24px; border-top:1px solid #1e1e1e; display:flex; justify-content:flex-end; align-items:center; gap:10px; position:sticky; bottom:0; background:#111; }

.nw-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.nw-field { display:flex; flex-direction:column; gap:5px; }
.nw-field-full   { grid-column:1/-1; }
.nw-field-center { align-items:flex-start; }
.nw-field label  { font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#666; font-weight:600; }
.nw-req  { color:#ff4444; }
.nw-hint { font-size:10px; color:#444; text-transform:none; letter-spacing:0; font-weight:400; }

.nw-field input[type="text"],
.nw-field input[type="url"],
.nw-field input[type="number"],
.nw-field textarea,
.nw-field select {
    background:#0d0d0d; border:1px solid #2a2a2a; border-radius:5px;
    color:#e0e0e0; padding:8px 10px;
    font-family:'Chakra Petch',monospace; font-size:13px;
    transition:border-color .15s; width:100%;
}
.nw-input-num { width:90px !important; }
.nw-select option { background:#111; }
.nw-field input:focus,.nw-field textarea:focus,.nw-field select:focus { outline:none; border-color:#adff00; box-shadow:0 0 0 2px rgba(173,255,0,.08); }
.nw-field textarea { resize:vertical; }

.nw-stats-section { margin-top:20px; padding-top:16px; border-top:1px solid #1e1e1e; }
.nw-stats-title   { font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#666; margin:0 0 12px; font-family:'Chakra Petch',monospace; }

.nw-basestats-row { display:flex; gap:24px; margin-bottom:4px; }
.nw-basestat { display:flex; flex-direction:column; gap:5px; }
.nw-basestat label { font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#666; font-weight:600; }

.nw-stats-grid    { display:grid; grid-template-columns:repeat(2,1fr); gap:12px; }
.nw-stat-slider   { display:flex; flex-direction:column; gap:4px; }
.nw-stat-slider label { font-size:11px; color:#666; text-transform:uppercase; letter-spacing:.5px; }
.nw-stat-slider-row { display:flex; align-items:center; gap:8px; }
.nw-range     { width:100%; accent-color:#adff00; cursor:pointer; }
.nw-range-val { font-size:13px; color:#adff00; font-weight:700; min-width:22px; text-align:right; }
CSS;
    }

    /* -------------------------------------------------------------- */
    /*  JS                                                              */
    /* -------------------------------------------------------------- */

    private function get_js(): string { return <<<'JS'
jQuery(function($){
    var nonce = $('#nw-nonce').val();
    var allClasses = [];

    /* helpers */
    function notice(msg, type) {
        var el = $('#nw-notice');
        el.attr('class','nw-notice nw-notice-'+type).text(msg).show();
        setTimeout(function(){ el.fadeOut(300); }, 3500);
    }
    function tagsStr(tags) {
        if (!tags) return '';
        if (Array.isArray(tags)) return tags.join(', ');
        try { var a=JSON.parse(tags); return Array.isArray(a)?a.join(', '):tags; } catch(e){ return tags; }
    }
    function updateStats(data) {
        var active = data.filter(function(c){ return c.is_active!==false; }).length;
        $('#nw-total').text(data.length);
        $('#nw-active').text(active);
        $('#nw-inactive').text(data.length - active);
    }

    /* render table */
    function renderTable(data) {
        var tbody = $('#nw-classes-tbody');
        if (!data.length) {
            tbody.html('<tr><td colspan="7" style="text-align:center;padding:32px;color:#555;">No classes found. Add the first one!</td></tr>');
            return;
        }
        tbody.html(data.map(function(c){
            var tags  = Array.isArray(c.tags) ? c.tags : [];
            var tagsH = tags.slice(0,4).map(function(t){ return '<span class="nw-tag">'+$('<s>').text(t).html()+'</span>'; }).join('')
                      + (tags.length>4?'<span class="nw-tag">+'+(tags.length-4)+'</span>':'');
            var active = c.is_active!==false;
            var imgH   = c.imgurl
                ? '<img src="'+$('<s>').text(c.imgurl).html()+'" class="nw-race-img" loading="lazy" onerror="this.style.display=\'none\'">'
                : '<div class="nw-race-img-placeholder">⚡</div>';
            var roleH  = c.primaryrole ? '<span class="nw-role-badge">'+$('<s>').text(c.primaryrole).html()+'</span>' : '<span style="color:#444">—</span>';
            return '<tr data-id="'+c.id+'" class="'+(active?'':'nw-row-inactive')+'">'
                +'<td>'+imgH+'</td>'
                +'<td><div class="nw-race-name">'+$('<s>').text(c.name).html()+'</div>'
                +    '<div class="nw-race-sub">Skills: '+(c.startingskills||3)+'</div></td>'
                +'<td>'+roleH+'</td>'
                +'<td><div class="nw-tags">'+tagsH+'</div></td>'
                +'<td class="nw-hp-mp"><span class="nw-hp">HP '+(c.basehp||10)+'</span> / <span class="nw-mp">MP '+(c.basemp||8)+'</span></td>'
                +'<td><label class="nw-toggle"><input type="checkbox" class="nw-active-toggle" data-id="'+c.id+'" '+(active?'checked':'')+'><span class="nw-toggle-slider"></span></label></td>'
                +'<td><div class="nw-row-actions"><button class="nw-action-btn nw-edit-btn" data-id="'+c.id+'">Edit</button></div></td>'
                +'</tr>';
        }).join(''));
    }

    /* load */
    function loadAll() {
        $('#nw-classes-tbody').html('<tr class="nw-loading-row"><td colspan="7"><div class="nw-spinner"></div> Loading…</td></tr>');
        $.post(ajaxurl,{action:'nw_classes_get_all',nonce:nonce},function(res){
            if (!res.success){ notice('Error: '+res.data,'error'); return; }
            allClasses = res.data||[];
            renderTable(allClasses);
            updateStats(allClasses);
        }).fail(function(){ notice('Request failed.','error'); });
    }

    /* toggle */
    $(document).on('change','.nw-active-toggle',function(){
        var id=$(this).data('id'), val=$(this).is(':checked'), row=$(this).closest('tr');
        $.post(ajaxurl,{action:'nw_classes_toggle',nonce:nonce,class_id:id,is_active:val?1:0},function(res){
            if (res.success){
                row.toggleClass('nw-row-inactive',!val);
                allClasses = allClasses.map(function(c){ if(c.id===id) c.is_active=val; return c; });
                updateStats(allClasses);
                notice((val?'Activated':'Deactivated')+' successfully.','success');
            } else {
                notice('Toggle failed: '+res.data,'error');
                row.find('.nw-active-toggle').prop('checked',!val);
            }
        });
    });

    /* open modal */
    function openModal(id) {
        resetForm();
        if (id) {
            var c = allClasses.find(function(x){ return x.id===id; });
            if (c) fillForm(c);
            $('#nw-modal-title').text('Edit Class');
            $('#nw-save-label').text('Save Changes');
            $('#nw-delete-btn').show().data('id',id);
        } else {
            $('#nw-modal-title').text('New Class');
            $('#nw-save-label').text('Create Class');
            $('#nw-delete-btn').hide();
        }
        $('#nw-modal-overlay').fadeIn(150);
    }

    function resetForm(){
        $('#nw-class-form')[0].reset();
        $('#nw-field-id').val('');
        ['preferredbody','preferredreflex','preferredmind','preferredspirit'].forEach(function(k){
            $('#nw-val-'+k).text(3);
        });
    }

    function fillForm(c){
        $('#nw-field-id').val(c.id);
        $('#nw-field-name').val(c.name||'');
        $('#nw-field-primaryrole').val(c.primaryrole||'');
        $('#nw-field-description').val(c.description||'');
        $('#nw-field-gminstructions').val(c.gminstructions||'');
        $('#nw-field-imgurl').val(c.imgurl||'');
        $('#nw-field-tags').val(tagsStr(c.tags));
        $('#nw-field-conflictaxis').val(c.conflictaxis||'');
        $('#nw-field-conflictside').val(c.conflictside||'');
        $('#nw-field-active').prop('checked', c.is_active!==false);
        $('#nw-field-basehp').val(c.basehp||10);
        $('#nw-field-basemp').val(c.basemp||8);
        $('#nw-field-startingskills').val(c.startingskills||3);
        ['preferredbody','preferredreflex','preferredmind','preferredspirit'].forEach(function(k){
            var v = c[k]!==undefined ? c[k] : 3;
            $('#nw-field-'+k).val(v);
            $('#nw-val-'+k).text(v);
        });
    }

    /* sliders live */
    $(document).on('input','.nw-range',function(){
        $('#nw-val-'+$(this).attr('id').replace('nw-field-','')).text($(this).val());
    });

    /* close */
    $('#nw-modal-close,#nw-cancel-btn').on('click',closeModal);
    $('#nw-modal-overlay').on('click',function(e){ if($(e.target).is('#nw-modal-overlay')) closeModal(); });
    function closeModal(){ $('#nw-modal-overlay').fadeOut(150); }

    /* save */
    $('#nw-save-btn').on('click',function(){
        if (!$('#nw-field-name').val().trim()){ notice('Name is required.','error'); return; }
        var btn=$(this);
        btn.prop('disabled',true);
        $('#nw-save-label').text('Saving…');

        var fd={action:'nw_classes_save',nonce:nonce,'class':{}};
        $('#nw-class-form').serializeArray().forEach(function(f){
            if(f.name!=='is_active') fd['class'][f.name]=f.value;
        });
        fd['class'].is_active = $('#nw-field-active').is(':checked') ? 1 : 0;

        $.post(ajaxurl,fd,function(res){
            btn.prop('disabled',false);
            $('#nw-save-label').text('Save Changes');
            if(res.success){ notice('Class saved!','success'); closeModal(); loadAll(); }
            else { notice('Error: '+(res.data||'Unknown'),'error'); }
        }).fail(function(){ btn.prop('disabled',false); $('#nw-save-label').text('Save Changes'); notice('Request failed.','error'); });
    });

    /* delete */
    $('#nw-delete-btn').on('click',function(){
        var id=$(this).data('id');
        if (!id || !confirm('Delete this class? This cannot be undone.')) return;
        $.post(ajaxurl,{action:'nw_classes_delete',nonce:nonce,class_id:id},function(res){
            if(res.success){ notice('Class deleted.','success'); closeModal(); loadAll(); }
            else { notice('Delete failed: '+res.data,'error'); }
        });
    });

    $('#nw-refresh-btn').on('click',loadAll);
    $('#nw-add-btn').on('click',function(){ openModal(null); });

    loadAll();
});
JS;
    }
}

new NeoWeaver_Classes_Admin();
