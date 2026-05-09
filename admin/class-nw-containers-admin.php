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
            '📦 Containers',
            'manage_options',
            $this->page_slug,
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, $this->page_slug ) ) return;

        $plugin_url = plugin_dir_url( dirname( __FILE__ ) );

        wp_enqueue_style(
            'chakra-petch',
            'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
            [],
            null
        );

        wp_enqueue_style(
            'nw-admin-core',
            $plugin_url . 'assets/css/nw-admin-core.css',
            [ 'chakra-petch' ],
            NEOWEAVER_VERSION
        );

        wp_enqueue_style(
            'nw-containers-style',
            $plugin_url . 'assets/css/containers-admin.css',
            [ 'chakra-petch', 'nw-admin-core' ],
            NEOWEAVER_VERSION
        );

        wp_enqueue_script(
            'nw-containers-script',
            $plugin_url . 'assets/js/containers-admin.js',
            [ 'jquery' ],
            NEOWEAVER_VERSION,
            true
        );

        wp_localize_script(
            'nw-containers-script',
            'NWContainers',
            [
                'ajaxurl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'neoweaver_containers' ),
            ]
        );
    }

    /* ---------------------------------------------------------------- */
    /*  RENDER                                                           */
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
        </div>
    <?php }

    /* ---------------------------------------------------------------- */
    /*  SUPABASE                                                         */
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
    /*  AJAX                                                             */
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

        $raw = $_POST['container'] ?? [];
        $id  = sanitize_text_field( $raw['id'] ?? '' );

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

}

new NeoWeaver_Containers_Admin();
