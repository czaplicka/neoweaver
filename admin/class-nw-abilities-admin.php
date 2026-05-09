<?php
/**
 * NeoWeaver Admin Panel — Abilities (cyber_abilities)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Abilities_Admin {

    private string $page_slug   = 'neoweaver-abilities';
    private string $parent_slug = 'neoweaver';

    private const ABILITY_TYPES = [
        'Active', 'Passive', 'Reaction', 'Ultimate',
        'Racial', 'Class', 'Item', 'Special',
    ];

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

        // plugin_dir_url( dirname( __FILE__ ) ) zawsze wskazuje na główny folder pluginu
        // niezależnie od tego, jak stała NEOWEAVER_PLUGIN_URL jest zdefiniowana
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
            'nw-abilities-style',
            $plugin_url . 'assets/css/abilities-admin.css',
            [ 'chakra-petch', 'nw-admin-core' ],
            NEOWEAVER_VERSION
        );

        wp_enqueue_script(
            'nw-abilities-script',
            $plugin_url . 'assets/js/abilities-admin.js',
            [ 'jquery' ],
            NEOWEAVER_VERSION,
            true
        );

        wp_localize_script( 'nw-abilities-script', 'NWAbilities', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'neoweaver_abilities' ),
        ] );
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
                    <input type="text" id="nw-search" class="nw-search-input"
                           placeholder="🔍 Search name, source or tag…">
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
                                    <input type="text" id="nw-field-name" name="name"
                                           required placeholder="e.g. Brute Force">
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
                                    <input type="text" id="nw-field-cost" name="cost"
                                           placeholder="e.g. 1 card, 2 MP, Free">
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>Source
                                        <span class="nw-hint">(classes / archetypes that have this ability)</span>
                                    </label>
                                    <input type="text" id="nw-field-source" name="source"
                                           placeholder="e.g. Mercenary, Soldier, Juggernaut">
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>Tags <span class="nw-hint">(comma-separated)</span></label>
                                    <input type="text" id="nw-field-tags" name="tags"
                                           placeholder="e.g. Combat, Power, Sacrifice">
                                </div>

                            </div>

                            <div class="nw-section-label">Content</div>
                            <div class="nw-form-grid">

                                <div class="nw-field nw-field-full">
                                    <label>Description <span class="nw-hint">(shown to players)</span></label>
                                    <textarea id="nw-field-description" name="description"
                                              rows="4" placeholder="Ability effect description…"></textarea>
                                </div>

                                <div class="nw-field nw-field-full">
                                    <label>GM Notes <span class="nw-hint">(internal / AI context)</span></label>
                                    <textarea id="nw-field-gm_notes" name="gm_notes"
                                              rows="3" placeholder="GM/AI interpretation hints…"></textarea>
                                </div>

                            </div>

                            <div class="nw-section-label">Media</div>
                            <div class="nw-form-grid">

                                <div class="nw-field nw-field-full">
                                    <label>Image URL</label>
                                    <input type="url" id="nw-field-img_url" name="img_url"
                                           placeholder="https://…">
                                    <div id="nw-img-preview-wrap" style="display:none;margin-top:6px;">
                                        <img id="nw-img-preview" src="" alt="preview"
                                             style="max-height:80px;border-radius:4px;border:1px solid #2e2e2e;">
                                    </div>
                                </div>

                            </div>
                        </form>
                    </div>
                    <div class="nw-modal-footer">
                        <button class="nw-btn nw-btn-danger" id="nw-delete-btn"
                                style="display:none;margin-right:auto;">🗑 Delete</button>
                        <button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
                        <button class="nw-btn nw-btn-primary" id="nw-save-btn">
                            <span id="nw-save-label">Save Ability</span>
                        </button>
                    </div>
                </div>
            </div>

        </div>
    <?php }

    /* ---------------------------------------------------------------- */
    /*  SUPABASE                                                          */
    /* ---------------------------------------------------------------- */

    private function supa(
        string $method,
        string $table,
        array  $body  = [],
        array  $query = [],
        array  $extra = []
    ): array {
        $method = strtoupper( $method );

        if ( 'GET' === $method ) {
            $data = tw_supabase_get( $table, $query, $extra );
            return [ 'code' => 200, 'data' => $data ];
        }

        $prefer_header = in_array( $method, [ 'POST', 'PATCH' ], true )
            ? [ 'headers' => [ 'Prefer' => 'return=representation' ] ]
            : [];

        $merged_extra = array_replace_recursive( $prefer_header, $extra );

        $res = tw_supabase_request(
            $method,
            $table,
            $query,
            $body ?: null,
            $merged_extra
        );

        if ( ! is_array( $res ) || ! array_key_exists( 'code', $res ) ) {
            return [ 'error' => 'Unexpected response from tw_supabase_request' ];
        }

        return [ 'code' => $res['code'], 'data' => $res['data'] ];
    }

    /* ---------------------------------------------------------------- */
    /*  UUID sanitisation                                                 */
    /* ---------------------------------------------------------------- */

    private function sanitize_uuid( string $raw ): string {
        if ( function_exists( 'tw_sanitize_supabase_id' ) ) {
            return tw_sanitize_supabase_id( $raw );
        }
        return preg_replace( '/[^a-f0-9\-]/i', '', $raw );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: GET ALL                                                     */
    /* ---------------------------------------------------------------- */

    public function ajax_get_all(): void {
        check_ajax_referer( 'neoweaver_abilities', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $raw_type = sanitize_text_field( $_POST['filter_type'] ?? '' );
        $type     = in_array( $raw_type, self::ABILITY_TYPES, true ) ? $raw_type : '';

        $limit = (int) apply_filters( 'neoweaver_abilities_per_page', 500 );

        $query = [
            'select' => 'id,name,description,ability_type,source,gm_notes,cost,img_url,tags,created_at',
            'order'  => 'name.asc',
            'limit'  => $limit,
        ];

        if ( $type ) {
            $query['ability_type'] = 'eq.' . $type;
        }

        $res = $this->supa( 'GET', 'cyber_abilities', [], $query );

        if ( isset( $res['error'] ) ) {
            wp_send_json_error( $res['error'] );
            return;
        }

        wp_send_json_success( $res['data'] );
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: SAVE                                                        */
    /* ---------------------------------------------------------------- */

    public function ajax_save(): void {
        check_ajax_referer( 'neoweaver_abilities', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $raw = $_POST;
        $id  = $this->sanitize_uuid( sanitize_text_field( $raw['id'] ?? '' ) );

        $tags = array_values( array_filter(
            array_map( 'trim', explode( ',', sanitize_text_field( $raw['tags'] ?? '' ) ) )
        ) );

        $ability_type_raw = sanitize_text_field( $raw['ability_type'] ?? '' );
        $ability_type     = in_array( $ability_type_raw, self::ABILITY_TYPES, true )
            ? $ability_type_raw
            : null;

        $payload = [
            'name'         => sanitize_text_field(     $raw['name']        ?? '' ),
            'description'  => sanitize_textarea_field( $raw['description'] ?? '' ) ?: null,
            'gm_notes'     => sanitize_textarea_field( $raw['gm_notes']    ?? '' ) ?: null,
            'ability_type' => $ability_type,
            'source'       => sanitize_text_field(     $raw['source']      ?? '' ) ?: null,
            'cost'         => sanitize_text_field(     $raw['cost']        ?? '' ) ?: null,
            'img_url'      => esc_url_raw(             $raw['img_url']     ?? '' ) ?: null,
            'tags'         => $tags,
        ];

        if ( empty( $payload['name'] ) ) {
            wp_send_json_error( 'Name is required.' );
            return;
        }

        $res = $id
            ? $this->supa( 'PATCH', 'cyber_abilities', $payload, [ 'id' => 'eq.' . $id ] )
            : $this->supa( 'POST',  'cyber_abilities', $payload );

        if ( isset( $res['error'] ) ) {
            wp_send_json_error( $res['error'] );
            return;
        }

        $code = $res['code'] ?? 0;
        if ( $code >= 200 && $code < 300 ) {
            $data = $res['data'];
            wp_send_json_success( is_array( $data ) ? ( $data[0] ?? $data ) : $data );
        } else {
            $msg = is_array( $res['data'] )
                ? ( $res['data']['message'] ?? 'Supabase error ' . $code )
                : 'Supabase error ' . $code;
            wp_send_json_error( $msg );
        }
    }

    /* ---------------------------------------------------------------- */
    /*  AJAX: DELETE                                                      */
    /* ---------------------------------------------------------------- */

    public function ajax_delete(): void {
        check_ajax_referer( 'neoweaver_abilities', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $id = $this->sanitize_uuid( sanitize_text_field( $_POST['ability_id'] ?? '' ) );

        if ( ! $id ) {
            wp_send_json_error( 'Missing or invalid ID' );
            return;
        }

        $res = $this->supa( 'DELETE', 'cyber_abilities', [], [ 'id' => 'eq.' . $id ] );

        if ( isset( $res['error'] ) ) {
            wp_send_json_error( $res['error'] );
            return;
        }

        wp_send_json_success( 'deleted' );
    }

} // end class

// Instantiate only after plugins_loaded (priority 20 ensures supabase-config is loaded)
add_action( 'plugins_loaded', static function () {
    if ( is_admin() ) {
        new NeoWeaver_Abilities_Admin();
    }
}, 20 );
