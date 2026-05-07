<?php
/**
 * NeoWeaver Admin Panel — Items (cyber_items)
 *
 * Columns: id, name, description, type, tags, slot, power_value, price,
 *          img_url, sound_url, rarity, size, mass, stack_limit,
 *          is_container, is_active, min_kingdom_tech, min_kingdom_magic,
 *          min_kingdom_wealth, restricted_to_archetype
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Items_Admin {

    private string $supabase_url;
    private string $supabase_key;
    private string $page_slug   = 'neoweaver-items';
    private string $parent_slug = 'neoweaver';

    public function __construct() {
        $this->supabase_url = rtrim( tw_supabase_url(), '/' );
        $this->supabase_key = tw_supabase_anon_key();

        add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_nw_items_get_all', [ $this, 'ajax_get_all' ] );
        add_action( 'wp_ajax_nw_items_save',    [ $this, 'ajax_save'    ] );
        add_action( 'wp_ajax_nw_items_toggle',  [ $this, 'ajax_toggle'  ] );
        add_action( 'wp_ajax_nw_items_delete',  [ $this, 'ajax_delete'  ] );
    }

    /* ---------------------------------------------------------------- */
    /*  MENU                                                              */
    /* ---------------------------------------------------------------- */

    public function register_menu(): void {
        add_submenu_page(
            $this->parent_slug,
            'NeoWeaver — Items',
            '⚔️ Items',
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
        <div class="wrap nw-panel" id="nw-items-panel">

            <div class="nw-panel-header">
                <h1 class="nw-panel-title">
                    <span class="nw-accent">Neo</span>Weaver
                    <span class="nw-panel-subtitle">/ Items</span>
                </h1>
                <div class="nw-header-actions">
                    <div class="nw-filter-bar">
                        <select id="nw-filter-type" class="nw-select-filter">
                            <option value="">All types</option>
                            <?php foreach ( $this->item_types() as $t ) : ?>
                            <option value="<?php echo esc_attr($t); ?>"><?php echo esc_html(ucfirst($t)); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="nw-filter-rarity" class="nw-select-filter">
                            <option value="">All rarities</option>
                            <?php foreach ( $this->rarities() as $r ) : ?>
                            <option value="<?php echo esc_attr($r); ?>"><?php echo esc_html(ucfirst($r)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">↻ Refresh</button>
                    <button class="nw-btn nw-btn-primary" id="nw-add-btn">+ New Item</button>
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
                        <th>Type / Slot</th>
                        <th>Tags</th>
                        <th>Rarity</th>
                        <th>PWR / Price</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody id="nw-items-tbody">
                        <tr class="nw-loading-row"><td colspan="8"><div class="nw-spinner"></div> Loading items…</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- ==================== MODAL ==================== -->
            <div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
                <div class="nw-modal">
                    <div class="nw-modal-header">
                        <h2 id="nw-modal-title">Edit Item</h2>
                        <button class="nw-modal-close" id="nw-modal-close">✕</button>
                    </div>
                    <div class="nw-modal-body">
                        <form id="nw-item-form">
                            <input type="hidden" id="nw-field-id" name="id">

                            <!-- Section: Identity -->
                            <div class="nw-section-label">Identity</div>
                            <div class="nw-form-grid">
                                <div class="nw-field nw-field-full">
                                    <label>Name <span class="nw-req">*</span></label>
                                    <input type="text" id="nw-field-name" name="name" required placeholder="e.g. Monofilament Blade">
                                </div>
                                <div class="nw-field">
                                    <label>Type</label>
                                    <select id="nw-field-type" name="type" class="nw-select">
                                        <option value="">— choose —</option>
                                        <?php foreach ( $this->item_types() as $t ) : ?>
                                        <option value="<?php echo esc_attr($t); ?>"><?php echo esc_html(ucfirst($t)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="nw-field">
                                    <label>Slot</label>
                                    <select id="nw-field-slot" name="slot" class="nw-select">
                                        <option value="">— none —</option>
                                        <?php foreach ( $this->slots() as $s ) : ?>
                                        <option value="<?php echo esc_attr($s); ?>"><?php echo esc_html($s); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="nw-field">
                                    <label>Rarity</label>
                                    <select id="nw-field-rarity" name="rarity" class="nw-select">
                                        <option value="">— choose —</option>
                                        <?php foreach ( $this->rarities() as $r ) : ?>
                                        <option value="<?php echo esc_attr($r); ?>"><?php echo esc_html(ucfirst($r)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Tags <span class="nw-hint">(comma-separated → JSON array)</span></label>
                                    <input type="text" id="nw-field-tags" name="tags" placeholder="e.g. melee, sharp">
                                </div>
                                <div class="nw-field nw-field-full">
                                    <label>Description</label>
                                    <textarea id="nw-field-description" name="description" rows="3" placeholder="Public item description…"></textarea>
                                </div>
                            </div>

                            <!-- Section: Stats -->
                            <div class="nw-section-label">Stats &amp; Economy</div>
                            <div class="nw-form-grid">
                                <div class="nw-field">
                                    <label>Power Value</label>
                                    <input type="number" id="nw-field-power_value" name="power_value" min="0" value="0">
                                </div>
                                <div class="nw-field">
                                    <label>Price</label>
                                    <input type="number" id="nw-field-price" name="price" min="0" value="0">
                                </div>
                                <div class="nw-field">
                                    <label>Size</label>
                                    <select id="nw-field-size" name="size" class="nw-select">
                                        <option value="">— choose —</option>
                                        <?php foreach ( ['tiny','small','medium','large','huge'] as $s ) : ?>
                                        <option value="<?php echo esc_attr($s); ?>"><?php echo esc_html(ucfirst($s)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="nw-field">
                                    <label>Mass (kg)</label>
                                    <input type="number" id="nw-field-mass" name="mass" min="0" step="0.1" value="1">
                                </div>
                                <div class="nw-field">
                                    <label>Stack Limit</label>
                                    <input type="number" id="nw-field-stack_limit" name="stack_limit" min="1" value="1">
                                </div>
                                <div class="nw-field nw-field-center">
                                    <label>Is Container?</label>
                                    <label class="nw-toggle">
                                        <input type="checkbox" id="nw-field-is_container" name="is_container">
                                        <span class="nw-toggle-slider nw-toggle-blue"></span>
                                    </label>
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
                                <div class="nw-field nw-field-full">
                                    <label>Sound URL</label>
                                    <input type="url" id="nw-field-sound_url" name="sound_url" placeholder="https://…/sword.mp3">
                                    <div id="nw-sound-wrap" style="display:none;margin-top:6px;">
                                        <audio id="nw-audio-preview" controls style="width:100%;height:32px;"></audio>
                                    </div>
                                </div>
                            </div>

                            <!-- Section: Kingdom Requirements -->
                            <div class="nw-section-label">Kingdom Requirements</div>
                            <div class="nw-form-grid">
                                <div class="nw-field">
                                    <label>Min Tech Level</label>
                                    <input type="number" id="nw-field-min_kingdom_tech" name="min_kingdom_tech" min="0" value="0">
                                </div>
                                <div class="nw-field">
                                    <label>Min Magic Level</label>
                                    <input type="number" id="nw-field-min_kingdom_magic" name="min_kingdom_magic" min="0" value="0">
                                </div>
                                <div class="nw-field">
                                    <label>Min Wealth Level</label>
                                    <input type="number" id="nw-field-min_kingdom_wealth" name="min_kingdom_wealth" min="0" value="0">
                                </div>
                                <div class="nw-field">
                                    <label>Restricted to Archetype</label>
                                    <input type="text" id="nw-field-restricted_to_archetype" name="restricted_to_archetype" placeholder="e.g. Psychic (leave empty for all)">
                                </div>
                            </div>

                            <!-- Active -->
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
                        <button class="nw-btn nw-btn-primary" id="nw-save-btn"><span id="nw-save-label">Save Item</span></button>
                    </div>
                </div>
            </div><!-- .nw-modal-overlay -->

            <input type="hidden" id="nw-nonce" value="<?php echo esc_attr( wp_create_nonce( 'neoweaver_items' ) ); ?>">
        </div>
    <?php }

    /* ---------------------------------------------------------------- */
    /*  DATA LISTS                                                        */
    /* ---------------------------------------------------------------- */

    private function item_types(): array {
        return ['weapon','armor','shield','helmet','boots','gloves','accessory','consumable','tool','ammo','container','misc'];
    }
    private function rarities(): array {
        return ['common','uncommon','rare','epic','legendary','unique'];
    }
    private function slots(): array {
        return ['head','neck','chest','back','hand_r','hand_l','hands','waist','legs','feet','ring_l','ring_r','trinket','bag'];
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
        check_ajax_referer( 'neoweaver_items', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $type   = sanitize_text_field( $_POST['filter_type']   ?? '' );
        $rarity = sanitize_text_field( $_POST['filter_rarity'] ?? '' );

        $qs = 'cyber_items?select=id,name,description,type,tags,slot,power_value,price,img_url,sound_url,rarity,size,mass,stack_limit,is_container,is_active,min_kingdom_tech,min_kingdom_magic,min_kingdom_wealth,restricted_to_archetype&order=name.asc';
        if ( $type   ) $qs .= '&type=eq.'   . urlencode( $type );
        if ( $rarity ) $qs .= '&rarity=eq.' . urlencode( $rarity );

        $res = $this->supa( 'GET', $qs );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( $res['data'] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: SAVE                                                        */
    /* ---------------------------------------------------------------- */

    public function ajax_save(): void {
        check_ajax_referer( 'neoweaver_items', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $raw = $_POST['item'] ?? [];
        $id  = sanitize_text_field( $raw['id'] ?? '' );
        $tags = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $raw['tags'] ?? '' ) ) ) ) );

        $payload = [
            'name'                    => sanitize_text_field(     $raw['name']                    ?? '' ),
            'description'             => sanitize_textarea_field( $raw['description']             ?? '' ) ?: null,
            'type'                    => sanitize_text_field(     $raw['type']                    ?? '' ) ?: null,
            'slot'                    => sanitize_text_field(     $raw['slot']                    ?? '' ) ?: null,
            'rarity'                  => sanitize_text_field(     $raw['rarity']                  ?? '' ) ?: null,
            'size'                    => sanitize_text_field(     $raw['size']                    ?? '' ) ?: null,
            'img_url'                 => esc_url_raw(             $raw['img_url']                 ?? '' ) ?: null,
            'sound_url'               => esc_url_raw(             $raw['sound_url']               ?? '' ) ?: null,
            'restricted_to_archetype' => sanitize_text_field(     $raw['restricted_to_archetype'] ?? '' ) ?: null,
            'tags'                    => $tags,
            'power_value'             => (int)  ( $raw['power_value']          ?? 0 ),
            'price'                   => (int)  ( $raw['price']                ?? 0 ),
            'stack_limit'             => (int)  ( $raw['stack_limit']          ?? 1 ),
            'min_kingdom_tech'        => (int)  ( $raw['min_kingdom_tech']     ?? 0 ),
            'min_kingdom_magic'       => (int)  ( $raw['min_kingdom_magic']    ?? 0 ),
            'min_kingdom_wealth'      => (int)  ( $raw['min_kingdom_wealth']   ?? 0 ),
            'mass'                    => (float)( $raw['mass']                 ?? 1 ),
            'is_container'            => filter_var( $raw['is_container'] ?? false, FILTER_VALIDATE_BOOLEAN ),
            'is_active'               => filter_var( $raw['is_active']    ?? true,  FILTER_VALIDATE_BOOLEAN ),
        ];

        $res = $id
            ? $this->supa( 'PATCH', 'cyber_items?id=eq.' . urlencode( $id ), $payload )
            : $this->supa( 'POST',  'cyber_items', $payload );

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
        check_ajax_referer( 'neoweaver_items', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $id    = sanitize_text_field( $_POST['item_id']  ?? '' );
        $state = filter_var(           $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );
        $res = $this->supa( 'PATCH', 'cyber_items?id=eq.' . urlencode( $id ), [ 'is_active' => $state ] );
        isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( [ 'is_active' => $state ] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: DELETE                                                      */
    /* ---------------------------------------------------------------- */

    public function ajax_delete(): void {
        check_ajax_referer( 'neoweaver_items', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $id = sanitize_text_field( $_POST['item_id'] ?? '' );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );
        $res = $this->supa( 'DELETE', 'cyber_items?id=eq.' . urlencode( $id ), [], [ 'Prefer' => '' ] );
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
.nw-item-img{width:40px;height:40px;border-radius:6px;object-fit:cover;border:1px solid #2e2e2e;background:#1a1a1a}
.nw-item-img-placeholder{width:40px;height:40px;border-radius:6px;background:#1a1a1a;border:1px solid #2e2e2e;display:flex;align-items:center;justify-content:center;color:#444;font-size:20px}
.nw-item-name{font-weight:600;color:#fff}.nw-item-sub{font-size:11px;color:#555;margin-top:2px}
.nw-type-badge{font-size:10px;padding:2px 8px;border-radius:3px;background:#1e1e1e;border:1px solid #2e2e2e;color:#aaa;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}
.nw-slot-badge{font-size:10px;padding:2px 6px;border-radius:3px;background:#0d1a2e;border:1px solid #1e3a5e;color:#5599ff;margin-top:3px;display:inline-block}
.nw-tags{display:flex;flex-wrap:wrap;gap:4px}
.nw-tag{font-size:10px;padding:2px 7px;background:#1e1e1e;border:1px solid #2e2e2e;border-radius:3px;color:#888}
.nw-rarity-common{color:#aaa}.nw-rarity-uncommon{color:#4fc874}.nw-rarity-rare{color:#4da6ff}
.nw-rarity-epic{color:#b04dff}.nw-rarity-legendary{color:#ff9f00}.nw-rarity-unique{color:#ff4da6}
.nw-rarity-badge{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px}
.nw-pwr{color:#adff00;font-weight:700;font-size:12px}.nw-price{color:#e8af34;font-size:12px}
.nw-toggle{position:relative;display:inline-block;width:40px;height:22px}
.nw-toggle input{opacity:0;width:0;height:0}
.nw-toggle-slider{position:absolute;inset:0;background:#2a2a2a;border-radius:22px;cursor:pointer;transition:background .2s;border:1px solid #3a3a3a}
.nw-toggle-slider::before{content:'';position:absolute;width:16px;height:16px;left:2px;top:2px;background:#555;border-radius:50%;transition:all .2s}
.nw-toggle input:checked+.nw-toggle-slider{background:#1a3300;border-color:#adff00}
.nw-toggle input:checked+.nw-toggle-slider::before{background:#adff00;transform:translateX(18px)}
.nw-toggle-blue.nw-toggle-slider{background:#2a2a2a}
.nw-toggle input:checked+.nw-toggle-blue{background:#001a3a;border-color:#4da6ff}
.nw-toggle input:checked+.nw-toggle-blue::before{background:#4da6ff;transform:translateX(18px)}
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
.nw-field input[type="text"],.nw-field input[type="number"],.nw-field input[type="url"],.nw-field textarea,.nw-select{background:#0d0d0d;border:1px solid #2a2a2a;border-radius:5px;color:#e0e0e0;padding:8px 10px;font-family:'Chakra Petch',monospace;font-size:13px;transition:border-color .15s;width:100%}
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
    var nonce=$("#nw-nonce").val(), all=[];
    var rarityColors={common:"#aaa",uncommon:"#4fc874",rare:"#4da6ff",epic:"#b04dff",legendary:"#ff9f00",unique:"#ff4da6"};

    function esc(s){return $('<span>').text(s||'').html();}
    function notice(msg,type){var el=$("#nw-notice");el.attr("class","nw-notice nw-notice-"+type).text(msg).show();setTimeout(function(){el.fadeOut(300);},3500);}
    function tagsStr(t){if(!t)return'';if(Array.isArray(t))return t.join(', ');try{var a=JSON.parse(t);return Array.isArray(a)?a.join(', '):t;}catch(e){return t;}}
    function updateStats(d){var a=d.filter(function(i){return i.is_active!==false;}).length;$("#nw-total").text(d.length);$("#nw-active").text(a);$("#nw-inactive").text(d.length-a);}

    function renderTable(data){
        var tbody=$("#nw-items-tbody");
        if(!data.length){tbody.html('<tr><td colspan="8" style="text-align:center;padding:32px;color:#555;">No items found.</td></tr>');return;}
        tbody.html(data.map(function(item){
            var tags=Array.isArray(item.tags)?item.tags:[];
            var tagsH=tags.slice(0,3).map(function(t){return'<span class="nw-tag">'+esc(t)+'</span>';}).join('')+(tags.length>3?'<span class="nw-tag">+'+(tags.length-3)+'</span>':'');
            var active=item.is_active!==false;
            var imgH=item.img_url?'<img src="'+esc(item.img_url)+'" class="nw-item-img" loading="lazy" onerror="this.style.display=\'none\'">':'<div class="nw-item-img-placeholder">⚔</div>';
            var rc=rarityColors[item.rarity]||"#aaa";
            var rarityH=item.rarity?'<span class="nw-rarity-badge" style="color:'+rc+'">'+esc(item.rarity)+'</span>':'—';
            var typeSlot='<span class="nw-type-badge">'+esc(item.type||'—')+'</span>'+(item.slot?'<br><span class="nw-slot-badge">'+esc(item.slot)+'</span>':'');
            return'<tr data-id="'+item.id+'" class="'+(active?'':'nw-row-inactive')+'">'
                +'<td>'+imgH+'</td>'
                +'<td><div class="nw-item-name">'+esc(item.name)+'</div>'+(item.restricted_to_archetype?'<div class="nw-item-sub">'+esc(item.restricted_to_archetype)+' only</div>':'')+'</td>'
                +'<td>'+typeSlot+'</td>'
                +'<td><div class="nw-tags">'+tagsH+'</div></td>'
                +'<td>'+rarityH+'</td>'
                +'<td><span class="nw-pwr">⚡'+( item.power_value||0)+'</span><br><span class="nw-price">⚙ '+(item.price||0)+'g</span></td>'
                +'<td><label class="nw-toggle"><input type="checkbox" class="nw-active-toggle" data-id="'+item.id+'" '+(active?'checked':'')+'><span class="nw-toggle-slider"></span></label></td>'
                +'<td><div class="nw-row-actions"><button class="nw-action-btn nw-edit-btn" data-id="'+item.id+'">Edit</button></div></td>'
                +'</tr>';
        }).join(''));
    }

    function loadAll(){
        var ft=$("#nw-filter-type").val(), fr=$("#nw-filter-rarity").val();
        $("#nw-items-tbody").html('<tr class="nw-loading-row"><td colspan="8"><div class="nw-spinner"></div> Loading…</td></tr>');
        $.post(ajaxurl,{action:"nw_items_get_all",nonce:nonce,filter_type:ft,filter_rarity:fr},function(res){
            if(!res.success){notice("Error: "+res.data,"error");return;}
            all=res.data||[];renderTable(all);updateStats(all);
        }).fail(function(){notice("Request failed.","error");});
    }

    /* filter */
    $("#nw-filter-type,#nw-filter-rarity").on("change",loadAll);

    /* toggle */
    $(document).on("change",".nw-active-toggle",function(){
        var id=$(this).data("id"),val=$(this).is(":checked"),row=$(this).closest("tr");
        $.post(ajaxurl,{action:"nw_items_toggle",nonce:nonce,item_id:id,is_active:val?1:0},function(res){
            if(res.success){row.toggleClass("nw-row-inactive",!val);all=all.map(function(i){if(i.id===id)i.is_active=val;return i;});updateStats(all);notice((val?"Activated":"Deactivated")+".","success");}
            else{notice("Toggle failed: "+res.data,"error");row.find(".nw-active-toggle").prop("checked",!val);}
        });
    });

    /* image preview */
    $("#nw-field-img_url").on("input",function(){
        var v=$(this).val().trim();
        if(v){$("#nw-img-preview").attr("src",v);$("#nw-img-preview-wrap").show();}
        else{$("#nw-img-preview-wrap").hide();}
    });
    /* sound preview */
    $("#nw-field-sound_url").on("input",function(){
        var v=$(this).val().trim();
        if(v){$("#nw-audio-preview").attr("src",v);$("#nw-sound-wrap").show();}
        else{$("#nw-sound-wrap").hide();}
    });

    function openModal(id){
        $("#nw-item-form")[0].reset();
        $("#nw-field-id").val("");
        $("#nw-img-preview-wrap,#nw-sound-wrap").hide();
        if(id){
            var item=all.find(function(x){return x.id===id;});
            if(item){
                $("#nw-field-id").val(item.id);
                $("#nw-field-name").val(item.name||"");
                $("#nw-field-description").val(item.description||"");
                $("#nw-field-type").val(item.type||"");
                $("#nw-field-slot").val(item.slot||"");
                $("#nw-field-rarity").val(item.rarity||"");
                $("#nw-field-size").val(item.size||"");
                $("#nw-field-tags").val(tagsStr(item.tags));
                $("#nw-field-power_value").val(item.power_value||0);
                $("#nw-field-price").val(item.price||0);
                $("#nw-field-mass").val(item.mass||1);
                $("#nw-field-stack_limit").val(item.stack_limit||1);
                $("#nw-field-min_kingdom_tech").val(item.min_kingdom_tech||0);
                $("#nw-field-min_kingdom_magic").val(item.min_kingdom_magic||0);
                $("#nw-field-min_kingdom_wealth").val(item.min_kingdom_wealth||0);
                $("#nw-field-restricted_to_archetype").val(item.restricted_to_archetype||"");
                $("#nw-field-is_active").prop("checked",item.is_active!==false);
                $("#nw-field-is_container").prop("checked",item.is_container===true);
                if(item.img_url){$("#nw-field-img_url").val(item.img_url);$("#nw-img-preview").attr("src",item.img_url);$("#nw-img-preview-wrap").show();}
                if(item.sound_url){$("#nw-field-sound_url").val(item.sound_url);$("#nw-audio-preview").attr("src",item.sound_url);$("#nw-sound-wrap").show();}
            }
            $("#nw-modal-title").text("Edit Item");
            $("#nw-save-label").text("Save Changes");
            $("#nw-delete-btn").show().data("id",id);
        } else {
            $("#nw-modal-title").text("New Item");
            $("#nw-save-label").text("Create Item");
            $("#nw-delete-btn").hide();
        }
        $("#nw-modal-overlay").fadeIn(150);
    }

    $("#nw-modal-close,#nw-cancel-btn").on("click",function(){$("#nw-modal-overlay").fadeOut(150);});
    $("#nw-modal-overlay").on("click",function(e){if($(e.target).is("#nw-modal-overlay"))$("#nw-modal-overlay").fadeOut(150);});
    $(document).on("click",".nw-edit-btn",function(){openModal($(this).data("id"));});
    $("#nw-add-btn").on("click",function(){openModal(null);});
    $("#nw-refresh-btn").on("click",loadAll);

    $("#nw-save-btn").on("click",function(){
        if(!$("#nw-field-name").val().trim()){notice("Name is required.","error");return;}
        var btn=$(this);btn.prop("disabled",true);$("#nw-save-label").text("Saving…");
        var fd={action:"nw_items_save",nonce:nonce,"item":{}};
        $("#nw-item-form").serializeArray().forEach(function(f){
            if(f.name!=="is_active"&&f.name!=="is_container") fd["item"][f.name]=f.value;
        });
        fd["item"].is_active    = $("#nw-field-is_active").is(":checked")    ? 1 : 0;
        fd["item"].is_container = $("#nw-field-is_container").is(":checked") ? 1 : 0;
        $.post(ajaxurl,fd,function(res){
            btn.prop("disabled",false);$("#nw-save-label").text("Save Changes");
            if(res.success){notice("Item saved!","success");$("#nw-modal-overlay").fadeOut(150);loadAll();}
            else{notice("Error: "+(res.data||"Unknown"),"error");}
        }).fail(function(){btn.prop("disabled",false);$("#nw-save-label").text("Save Changes");notice("Request failed.","error");});
    });

    $("#nw-delete-btn").on("click",function(){
        var id=$(this).data("id");
        if(!id||!confirm("Delete this item permanently?"))return;
        $.post(ajaxurl,{action:"nw_items_delete",nonce:nonce,item_id:id},function(res){
            if(res.success){notice("Item deleted.","success");$("#nw-modal-overlay").fadeOut(150);loadAll();}
            else{notice("Delete failed: "+res.data,"error");}
        });
    });

    loadAll();
});
JS;
    }
}

new NeoWeaver_Items_Admin();
